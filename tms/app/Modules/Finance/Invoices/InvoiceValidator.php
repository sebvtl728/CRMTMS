<?php

namespace App\Modules\Finance\Invoices;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Documents\PdfRenderingFailed;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use App\Modules\Finance\Integrity;
use App\Modules\Finance\Plans\BillingPlans;
use App\Modules\Sessions\Public\Billing;
use App\Modules\Sessions\Public\DossierStates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Validates a draft invoice or credit note (spec 0.9 § 4.4), all in one
 * transaction — any failure (a check, the PDF converter) rolls everything
 * back, the number included:
 *
 *  1. checks: still a draft; an invoice goes to a recipient still
 *     invoiceable (the contractor of the dossier, or a funding agreed and
 *     paying directly, not billed outside the TMS) with the agreement number
 *     of its funding, and its computed lines never exceed what is due to
 *     that recipient now (Due, recomputed under the locks); a credit note
 *     is issued by the company of the invoice it corrects, to its
 *     recipient, and never gives back more than is left of that invoice (its
 *     HT minus its validated credit notes), nor more than was invoiced to
 *     that recipient on the dossier (all lines; computed lines alone); an
 *     invoice is positive, a credit note negative;
 *  2. the billing plan of the dossier is locked (rhythm, basis, « paie
 *     directement » and the trash: credit note first);
 *  3. number <prefix>-<AAAA>-<NNNNN> (InvoiceNumbering), issued today
 *     (Paris), due after the payment terms of the company, recipient copied
 *     (InvoiceRecipient), totals recomputed;
 *  4. PDF rendered and archived (InvoicePdf), its SHA-256 kept;
 *  5. hash of the canonical content (the PDF fingerprint included)
 *     chained to the last piece of the organisme (Integrity), one row
 *     appended to invoice_journal, status « Validée »;
 *  6. the dossier « Facturé » when nothing is due any more and its sessions
 *     are over (« Réalisé »); back to « Réalisé » when a credit note leaves
 *     something due.
 *
 * Locks, always in this order (no deadlock with the drafts nor with
 * Sessions): (a) the dossier row (Billing::lockDossier, the lock of the
 * drafts and of the Sessions writers), (b) the draft row, (c) the billing
 * plan row (BillingPlans::lock), (d) the invoice_sequences row of the
 * company, kind and year (InvoiceNumbering), (e) the journal of the
 * organisme — a transaction advisory lock, since the first validation has
 * no journal row to lock — before reading its last row.
 */
final class InvoiceValidator
{
    public const NOT_DRAFT = 'Cette pièce est déjà validée.';

    public const NO_AGREEMENT = 'Indiquez le numéro d\'accord du financement avant de valider.';

    public const DUE_CHANGED = 'Le montant dû a changé : recalculez le brouillon avant de le valider.';

    public const NOT_INVOICEABLE = 'Ce destinataire ne peut plus être facturé (financement supprimé, ne payant plus directement l\'organisme ou facturé hors du TMS) : supprimez ce brouillon.';

    public const TRASHED = 'Ce dossier est à la corbeille : restaurez-le avant de valider une facture.';

    public const NOT_POSITIVE = 'Le total d\'une facture doit être positif : pour diminuer ce qui a été facturé, faites un avoir.';

    public const NOT_NEGATIVE = 'Le total d\'un avoir doit être négatif.';

    public const OVER_INVOICED = 'L\'avoir dépasse ce qui a été facturé à ce destinataire.';

    public const NO_CORRECTED = 'Un avoir corrige une facture validée du même destinataire : celle-ci est introuvable.';

    public const COMPANY_CHANGED = 'La société du dossier a changé : recalculez le brouillon avant de le valider.';

    public const OTHER_PARTIES = 'Un avoir doit être émis par la même société et au même destinataire que la facture corrigée.';

    /** %s: the number of the corrected invoice. */
    public const OVER_CORRECTED = 'Cet avoir dépasse le montant restant de la facture %s.';

    public function __construct(
        private readonly Due $due,
        private readonly BillingPlans $plans,
        private readonly InvoiceNumbering $numbering,
        private readonly InvoicePdf $pdf,
        private readonly DossierStates $states,
    ) {}

    /**
     * @throws UserFacingHttpException 409 not a draft, or a refusal of step 1
     * @throws ValidationException a missing agreement number
     * @throws PdfRenderingFailed (503) the PDF converter failed: nothing validated
     */
    public function validate(Invoice $draft, User $user): Invoice
    {
        return DB::transaction(function () use ($draft, $user): Invoice {
            Billing::lockDossier($draft->dossier_id);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($draft->id);
            if (! $invoice->isDraft()) {
                throw new UserFacingHttpException(409, self::NOT_DRAFT);
            }
            $this->plans->lock($invoice->dossier_id);

            $company = Company::withTrashed()->findOrFail($invoice->company_id);
            $lines = $invoice->lines()->get();
            InvoiceTotals::apply($invoice, $company);
            $this->check($invoice, $lines);

            $today = CarbonImmutable::now('Europe/Paris')->startOfDay();
            $number = $this->numbering->next($company, $invoice->kind, $today);
            $previous = $this->lastHash();

            $invoice->forceFill([
                'number' => $number,
                'issued_on' => $today->toDateString(),
                'due_on' => $today->addDays($company->payment_terms_days)->toDateString(),
                'recipient_snapshot' => InvoiceRecipient::current($invoice),
                'validated_by' => $user->id,
                'validated_at' => CarbonImmutable::now(),
                'updated_by' => $user->id,
                'previous_hash' => $previous['hash'],
            ]);
            // Validated before the rendering: the archive bears no « BROUILLON » watermark.
            $invoice->status = InvoiceStatus::Validated;
            $archive = $this->pdf->archive($invoice);
            $invoice->forceFill(['pdf_path' => $archive['path'], 'pdf_sha256' => $archive['sha256']]);
            $invoice->hash = Integrity::hash($invoice, $previous['hash']);
            $invoice->save();

            InvoiceJournalEntry::query()->create([
                'invoice_id' => $invoice->id,
                'position' => $previous['position'] + 1,
                'number' => $number,
                'kind' => $invoice->kind,
                'issued_on' => $invoice->issued_on,
                'total_ht_cents' => $invoice->total_ht_cents,
                'total_vat_cents' => $invoice->total_vat_cents,
                'total_ttc_cents' => $invoice->total_ttc_cents,
                'hash' => $invoice->hash,
                'previous_hash' => $previous['hash'],
                'pdf_sha256' => $invoice->pdf_sha256,
            ]);

            $this->followDossier($invoice->dossier_id);

            return $invoice;
        });
    }

    /**
     * @param  Collection<int, InvoiceLine>  $lines
     *
     * @throws UserFacingHttpException
     * @throws ValidationException
     */
    private function check(Invoice $invoice, $lines): void
    {
        $computed = (int) $lines->filter(fn (InvoiceLine $l) => $l->kind === InvoiceLine::COMPUTED && in_array($l->component, InvoiceLine::BILLED, true))
            ->sum('total_ht_cents');

        if ($invoice->kind === Invoice::CREDIT_NOTE) {
            if ($invoice->total_ht_cents >= 0) {
                throw new UserFacingHttpException(409, self::NOT_NEGATIVE);
            }
            // What is left of the invoice it corrects: its HT minus its credit notes already validated.
            $corrected = $invoice->corrected_invoice_id === null ? null : Invoice::query()->find($invoice->corrected_invoice_id);
            if ($corrected === null || $corrected->isDraft() || $corrected->kind !== Invoice::INVOICE || Ledger::keyOf($corrected) !== Ledger::keyOf($invoice)) {
                throw new UserFacingHttpException(409, self::NO_CORRECTED);
            }
            // Issued by the company that issued the invoice, to whom it was addressed (never the dossier's of today).
            if ($corrected->company_id !== $invoice->company_id
                || $corrected->recipient_type !== $invoice->recipient_type || $corrected->recipient_id !== $invoice->recipient_id) {
                throw new UserFacingHttpException(409, self::OTHER_PARTIES);
            }
            $credited = (int) Invoice::query()->where('corrected_invoice_id', $invoice->corrected_invoice_id)
                ->whereIn('status', InvoiceStatus::values(InvoiceStatus::ISSUED))->sum('total_ht_cents');
            if (-$invoice->total_ht_cents > $corrected->total_ht_cents + $credited) {
                throw new UserFacingHttpException(409, sprintf(self::OVER_CORRECTED, $corrected->number));
            }
            // Net of the recipient on the dossier: its invoices minus its credit notes (all lines; computed lines alone).
            $issued = (int) Invoice::query()->where('dossier_id', $invoice->dossier_id)->where('recipient_key', $invoice->recipient_key)
                ->whereIn('status', InvoiceStatus::values(InvoiceStatus::ISSUED))->sum('total_ht_cents');
            $billed = array_sum(Ledger::of($invoice->dossier_id)->invoiced[Ledger::keyOf($invoice)] ?? []);
            if (-$invoice->total_ht_cents > $issued || -$computed > $billed) {
                throw new UserFacingHttpException(409, self::OVER_INVOICED);
            }

            return;
        }

        if ($invoice->total_ht_cents <= 0) {
            throw new UserFacingHttpException(409, self::NOT_POSITIVE);
        }
        $dossier = Billing::dossier($invoice->dossier_id);
        if ($dossier === null || $dossier['trashed']) {
            throw new UserFacingHttpException(409, self::TRASHED);
        }
        // Issued by the dossier's company of today (Recalculer re-aligns a draft prepared before a change).
        if ($invoice->company_id !== $dossier['company_id']) {
            throw new UserFacingHttpException(409, self::COMPANY_CHANGED);
        }
        $key = Ledger::keyOf($invoice);
        $who = InvoiceDrafts::recipientOf($dossier, $key);
        if ($who === null || $who['type'] !== $invoice->recipient_type || $who['id'] !== $invoice->recipient_id) {
            throw new UserFacingHttpException(409, self::NOT_INVOICEABLE);
        }
        if ($invoice->funding_id !== null) {
            $funding = array_column($dossier['fundings'], null, 'id')[$invoice->funding_id] ?? null;
            if ($funding === null || $funding['status'] !== 'agreed') {
                throw new UserFacingHttpException(409, self::NOT_INVOICEABLE);
            }
            if (trim((string) $funding['agreement_number']) === '') {
                throw ValidationException::withMessages(['invoice' => self::NO_AGREEMENT]);
            }
        }
        if ($computed > 0) {
            $row = array_column($this->due->forDossier($invoice->dossier_id)['rows'], null, 'recipient_key')[$key] ?? null;
            if ($computed > (int) ($row['total_cents'] ?? 0)) {
                throw new UserFacingHttpException(409, self::DUE_CHANGED);
            }
        }
    }

    /**
     * The hash and position of the last piece of the organisme, its journal
     * locked for this transaction (the first validation has no row to lock).
     *
     * @return array{hash: ?string, position: int}
     */
    private function lastHash(): array
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['finance.invoice_journal:'.app(TenantContext::class)->id()]);
        $last = InvoiceJournalEntry::query()->orderByDesc('position')->first(['hash', 'position']);

        return ['hash' => $last?->hash, 'position' => $last->position ?? 0];
    }

    /**
     * « Facturé » once nothing is due nor waiting and the dossier is
     * « Réalisé »; back to « Réalisé » when something is due again.
     */
    private function followDossier(string $dossierId): void
    {
        $dossier = Billing::dossier($dossierId);
        if ($dossier === null) {
            return;
        }
        $due = $this->due->forDossier($dossierId);
        $nothingLeft = $due['rows'] === [] && $due['soon'] === [];
        if ($nothingLeft && $dossier['status'] === 'done') {
            $this->states->markInvoiced($dossierId);
        } elseif (! $nothingLeft && $dossier['status'] === 'invoiced') {
            $this->states->unmarkInvoiced($dossierId);
        }
    }
}
