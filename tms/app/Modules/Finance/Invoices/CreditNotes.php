<?php

namespace App\Modules\Finance\Invoices;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Public\Billing;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * « Créer un avoir » (spec 0.9 § 4.4): a credit-note draft from a validated
 * invoice, total (every line with an amount) or partial (the chosen lines),
 * each line given back negative - same recipient, dossier and period,
 * corrected_invoice_id = the invoice. It is then edited like any draft
 * (amounts lowered for a partial credit) and validated by InvoiceValidator
 * (numbering AV, never more than was invoiced to the recipient). One open
 * credit-note draft per recipient of a dossier.
 */
final class CreditNotes
{
    public const NOT_AN_INVOICE = 'Un avoir se crée depuis une facture validée.';

    public const DRAFT_OPEN = 'Un brouillon d\'avoir est déjà en cours pour ce destinataire : validez-le ou supprimez-le d\'abord.';

    public const NO_LINE = 'Choisissez au moins une ligne à reprendre dans l\'avoir.';

    /**
     * @param  list<string>|null  $lineIds  the lines of the invoice to give back; null: all
     *
     * @throws UserFacingHttpException 409
     * @throws ValidationException
     */
    public function create(User $user, Invoice $invoice, ?array $lineIds = null): Invoice
    {
        return DB::transaction(function () use ($user, $invoice, $lineIds): Invoice {
            if ($invoice->dossier_id !== null) {
                Billing::lockDossier($invoice->dossier_id);
            }
            // A free invoice (no dossier) is locked itself: one credit-note draft at a time.
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->kind !== Invoice::INVOICE || $invoice->isDraft()) {
                throw new UserFacingHttpException(409, self::NOT_AN_INVOICE);
            }
            $open = Invoice::query()
                ->when($invoice->dossier_id === null,
                    fn ($q) => $q->where('corrected_invoice_id', $invoice->id),
                    fn ($q) => $q->where('dossier_id', $invoice->dossier_id)->where('recipient_key', $invoice->recipient_key))
                ->where('kind', Invoice::CREDIT_NOTE)->where('status', InvoiceStatus::Draft)->exists();
            if ($open) {
                throw new UserFacingHttpException(409, self::DRAFT_OPEN);
            }
            $lines = $invoice->lines()->get()->filter(fn (InvoiceLine $l) => $l->total_ht_cents !== 0
                && ($lineIds === null || in_array($l->id, $lineIds, true)))->values();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => self::NO_LINE]);
            }

            $credit = Invoice::query()->create([
                'company_id' => $invoice->company_id,
                'kind' => Invoice::CREDIT_NOTE,
                'dossier_id' => $invoice->dossier_id,
                'recipient_type' => $invoice->recipient_type,
                'recipient_id' => $invoice->recipient_id,
                'funding_id' => $invoice->funding_id,
                'period_from' => $invoice->period_from,
                'period_to' => $invoice->period_to,
                'cut_off' => $invoice->cut_off,
                'rhythm' => $invoice->rhythm,
                'basis' => $invoice->basis,
                'corrected_invoice_id' => $invoice->id,
                'origin' => $invoice->origin,
                'buyer_reference' => $invoice->buyer_reference,
                'service_code' => $invoice->service_code,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
            foreach ($lines as $position => $line) {
                InvoiceLine::query()->create([
                    ...$line->only([
                        'kind', 'component', 'label', 'description', 'quantity', 'unit', 'vat_rate',
                        'planned_minutes', 'realised_minutes', 'dossier_line_id', 'enrolment_id', 'funding_id', 'cotraitant_id',
                    ]),
                    'invoice_id' => $credit->id,
                    'position' => $position,
                    'unit_price_cents' => -$line->unit_price_cents,
                    'total_ht_cents' => -$line->total_ht_cents,
                    'computed_cents' => $line->kind === InvoiceLine::COMPUTED ? -$line->total_ht_cents : null,
                ]);
            }
            InvoiceTotals::apply($credit, Company::withTrashed()->findOrFail($credit->company_id));
            $credit->save();

            return $credit;
        });
    }
}
