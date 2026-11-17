<?php

namespace App\Modules\Finance\Invoices;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Users\User;
use App\Modules\Finance\Permissions;
use App\Modules\Finance\Plans\BillingPlans;
use App\Modules\Sessions\Public\Billing;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Drafts of invoices and credit notes (spec 0.9 § 4.4): prepared from the
 * due rows (« À facturer », one per recipient and dossier at its cut-off),
 * or by hand from the dossier; their lines edited (computed lines kept,
 * their amounts and labels editable; free lines added and removed) and
 * deleted. One open draft per recipient of a dossier and kind (an invoice
 * draft and a credit-note draft may live together): preparing again (a
 * double click, another user) skips it — under the row lock of the
 * dossier (Billing::lockDossier), the check is made again inside the
 * transaction.
 */
final class InvoiceDrafts
{
    public const VALIDATED = 'Une facture validée ne se modifie plus : faites un avoir.';

    public const NOT_DELETABLE = 'Une facture validée ne se supprime jamais : faites un avoir.';

    public const COMPUTED_KEPT = 'Une ligne calculée ne se supprime pas : mettez son montant à 0.';

    public const UNITS = ['forfait', 'heure', 'jour', 'stagiaire', 'session'];

    public const MAX_LINES = 100;

    /** Bounds of a line: its total stays far below the bigint limits. */
    public const MAX_QUANTITY = 100000;

    public const MAX_UNIT_PRICE_CENTS = 999_999_999;

    public function __construct(
        private readonly Due $due,
        private readonly BillingPlans $plans,
    ) {}

    /**
     * Drafts for the due rows $keys (« <dossier id>|<recipient> ») that
     * $user may prepare (finance.invoices.manage and its scope); a row no
     * longer due, out of scope or with a draft in progress is skipped.
     *
     * @param  list<string>  $keys
     * @return array{created: int, skipped: int}
     */
    public function prepare(User $user, array $keys): array
    {
        $byDossier = [];
        foreach (array_unique($keys) as $key) {
            [$dossierId, $recipient] = array_pad(explode('|', (string) $key, 2), 2, '');
            $byDossier[$dossierId][] = $recipient;
        }

        $created = 0;
        $skipped = 0;
        foreach ($byDossier as $dossierId => $recipients) {
            if (! Billing::allows($user, Permissions::MANAGE, (string) $dossierId)) {
                $skipped += count($recipients);

                continue;
            }
            foreach ($recipients as $recipient) {
                if ($this->create((string) $dossierId, $recipient, $user) === null) {
                    $skipped++;
                } else {
                    $created++;
                }
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * A draft for the recipient of the dossier made by hand: its invoice
     * draft in progress if any; else what is due to it now; else its
     * credit-note draft in progress; else an empty invoice (free lines). $recipient: « contractor » or « funding:<id> » (a
     * funding of the dossier paying directly).
     *
     * @throws ValidationException an unknown recipient
     */
    public function manual(User $user, string $dossierId, string $recipient): Invoice
    {
        $dossier = Billing::dossier($dossierId) ?? throw new NotFoundHttpException;
        $who = self::recipientOf($dossier, $recipient)
            ?? throw ValidationException::withMessages(['recipient' => 'Choisissez le destinataire de la facture.']);

        return DB::transaction(function () use ($dossier, $dossierId, $recipient, $who, $user): Invoice {
            self::lockDossier($dossierId);
            $open = $this->openDraft($dossierId, $recipient, Invoice::INVOICE)
                ?? $this->create($dossierId, $recipient, $user)
                ?? $this->openDraft($dossierId, $recipient);
            if ($open !== null) {
                return $open;
            }
            $plan = $this->plans->for($dossierId);
            $starts = array_column($dossier['sessions'], 'starts_on');
            $ends = array_column($dossier['sessions'], 'ends_on');
            $invoice = Invoice::query()->create([
                'company_id' => $dossier['company_id'], 'kind' => Invoice::INVOICE, 'dossier_id' => $dossierId,
                'recipient_type' => $who['type'], 'recipient_id' => $who['id'], 'funding_id' => $who['funding_id'],
                'period_from' => $starts === [] ? null : min($starts), 'period_to' => $ends === [] ? null : max($ends),
                'cut_off' => null, 'rhythm' => $plan['rhythm'], 'basis' => $plan['basis'],
                'created_by' => $user->id, 'updated_by' => $user->id,
            ]);
            $this->totals($invoice);

            return $invoice;
        });
    }

    /**
     * Saves the lines (in their order) and the notes of a draft.
     *
     * @param  array<string, mixed>  $data  notes, lines: [{id?, label, description, quantity, unit, unit_price, vat_rate}]
     *
     * @throws UserFacingHttpException 409 not a draft
     * @throws ValidationException
     */
    public function update(Invoice $draft, array $data): Invoice
    {
        return DB::transaction(function () use ($draft, $data): Invoice {
            $invoice = $this->lockedDraft($draft, self::VALIDATED);
            $company = Company::withTrashed()->findOrFail($invoice->company_id);
            /** @var \Illuminate\Database\Eloquent\Collection<string, InvoiceLine> $current */
            $current = $invoice->lines()->get()->keyBy('id');
            $lines = $this->validated($data, $current);

            $kept = [];
            foreach ($lines as $position => $input) {
                $line = isset($input['id']) ? $current[$input['id']] : new InvoiceLine(['invoice_id' => $invoice->id, 'kind' => InvoiceLine::FREE]);
                $quantity = (string) $input['quantity'];
                $price = (int) $input['unit_price_cents'];
                $line->fill([
                    'position' => $position,
                    'label' => $input['label'],
                    'description' => $input['description'],
                    'quantity' => $quantity,
                    'unit' => $input['unit'],
                    'unit_price_cents' => $price,
                    'vat_rate' => InvoiceTotals::rateFor($company, $line->component, $input['vat_rate']),
                    'total_ht_cents' => InvoiceTotals::line($quantity, $price),
                ]);
                $line->save();
                $kept[] = $line->id;
            }
            $current->except($kept)->each(fn (InvoiceLine $line) => $line->delete());

            $invoice->notes = $data['notes'] ?? null;
            $invoice->updated_by = Auth::id();
            $this->totals($invoice);

            return $invoice;
        });
    }

    /**
     * @throws UserFacingHttpException 409 not a draft
     */
    public function delete(Invoice $draft): void
    {
        DB::transaction(function () use ($draft): void {
            $invoice = $this->lockedDraft($draft, self::NOT_DELETABLE);
            $invoice->lines()->get()->each(fn (InvoiceLine $line) => $line->delete());
            $invoice->delete();
        });
    }

    /**
     * The draft of what is due now to the recipient, computed under the
     * lock of the dossier; null when it has a draft of that kind in progress, nothing is
     * due, or an invoice would go to a recipient no longer invoiceable (a
     * funding billed outside the TMS, deleted, or no longer paying
     * directly: a credit note only).
     */
    private function create(string $dossierId, string $recipient, User $user): ?Invoice
    {
        return DB::transaction(function () use ($dossierId, $recipient, $user): ?Invoice {
            self::lockDossier($dossierId);
            $dossier = Billing::dossier($dossierId) ?? throw new NotFoundHttpException;
            $row = $this->rowFor($dossier, $recipient);
            if ($row === null || $this->openDraft($dossierId, $recipient, $row['kind']) !== null) {
                return null;
            }
            $company = Company::withTrashed()->findOrFail($dossier['company_id']);
            $invoice = Invoice::query()->create([
                'company_id' => $company->id,
                'dossier_id' => $dossierId,
                'recipient_type' => $row['recipient']['type'],
                'recipient_id' => $row['recipient']['id'],
                'funding_id' => $row['recipient']['funding_id'],
                'created_by' => $user->id,
                'updated_by' => $user->id,
                ...self::header($row),
            ]);
            $this->insertLines($invoice, $row['lines'], $company, 0);
            $this->totals($invoice);

            return $invoice;
        });
    }

    /**
     * Rebuilds the computed lines of a draft from what is due now (under the
     * lock of the dossier), its free lines kept after them; nothing due: no
     * computed line left.
     *
     * @throws UserFacingHttpException 409 not a draft
     */
    public function recompute(Invoice $draft): Invoice
    {
        return DB::transaction(function () use ($draft): Invoice {
            self::lockDossier($draft->dossier_id);
            $invoice = $this->lockedDraft($draft, self::VALIDATED);
            $dossier = Billing::dossier($invoice->dossier_id) ?? throw new NotFoundHttpException;
            $row = $this->rowFor($dossier, Ledger::keyOf($invoice));
            $company = Company::withTrashed()->findOrFail($invoice->company_id);

            $lines = $invoice->lines()->get();
            $lines->where('kind', InvoiceLine::COMPUTED)->each(fn (InvoiceLine $l) => $l->delete());
            $computed = $row['lines'] ?? [];
            $this->insertLines($invoice, $computed, $company, 0);
            foreach ($lines->where('kind', InvoiceLine::FREE)->values() as $i => $free) {
                $free->forceFill(['position' => count($computed) + $i])->save();
            }
            if ($row !== null) {
                $invoice->forceFill(self::header($row));
            }
            $invoice->updated_by = Auth::id();
            $this->totals($invoice);

            return $invoice;
        });
    }

    /**
     * The due row of the recipient now, null when nothing is due or when an
     * invoice would go to a recipient no longer invoiceable.
     *
     * @param  array<string, mixed>  $dossier
     * @return array<string, mixed>|null
     */
    private function rowFor(array $dossier, string $recipient): ?array
    {
        $row = array_column($this->due->forDossier($dossier['id'])['rows'], null, 'recipient_key')[$recipient] ?? null;
        if ($row !== null && $row['kind'] === Invoice::INVOICE && self::recipientOf($dossier, $recipient) === null) {
            return null;
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function header(array $row): array
    {
        return [
            'kind' => $row['kind'],
            'period_from' => $row['period']['from'],
            'period_to' => $row['period']['to'],
            'cut_off' => $row['cut_off'],
            'rhythm' => $row['rhythm'],
            'basis' => $row['basis'],
            'corrected_invoice_id' => $row['corrected_invoice_id'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines  computed lines of a due row
     */
    private function insertLines(Invoice $invoice, array $lines, Company $company, int $from): void
    {
        foreach ($lines as $i => $line) {
            InvoiceLine::query()->create([
                ...$line,
                'invoice_id' => $invoice->id,
                'position' => $from + $i,
                'vat_rate' => InvoiceTotals::rateFor($company, $line['component']),
                'computed_cents' => $line['total_ht_cents'],
            ]);
        }
    }

    private function totals(Invoice $invoice): void
    {
        InvoiceTotals::apply($invoice, Company::withTrashed()->findOrFail($invoice->company_id));
        $invoice->save();
    }

    /** The open draft of the recipient ($kind: of that kind only; else an invoice first). */
    private function openDraft(string $dossierId, string $recipient, ?string $kind = null): ?Invoice
    {
        return Invoice::query()->where('dossier_id', $dossierId)->where('status', InvoiceStatus::Draft)
            ->where('recipient_key', $recipient === Invoice::CONTRACTOR ? Invoice::CONTRACTOR : substr($recipient, strlen('funding:')))
            ->when($kind, fn ($q, string $k) => $q->where('kind', $k))
            ->orderByRaw("kind = 'invoice' desc")
            ->first();
    }

    /**
     * Serialises the drafts of one dossier (preparing twice at once) and
     * their validation (InvoiceValidator takes the same lock first).
     */
    private static function lockDossier(string $dossierId): void
    {
        Billing::lockDossier($dossierId);
    }

    private function lockedDraft(Invoice $draft, string $refusal): Invoice
    {
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($draft->id);
        if (! $invoice->isDraft()) {
            throw new UserFacingHttpException(409, $refusal);
        }

        return $invoice;
    }

    /**
     * The recipient « contractor » or « funding:<id> » of the dossier (a
     * funding paying directly, not billed outside the TMS), null otherwise.
     *
     * @param  array<string, mixed>  $dossier
     * @return array{type: string, id: string, funding_id: ?string}|null
     */
    public static function recipientOf(array $dossier, string $recipient): ?array
    {
        if ($recipient === Invoice::CONTRACTOR) {
            return ['type' => $dossier['contractor']['type'], 'id' => $dossier['contractor']['id'], 'funding_id' => null];
        }
        foreach ($dossier['fundings'] as $f) {
            if ('funding:'.$f['id'] === $recipient && $f['pays_directly'] && ! $f['external_billing']) {
                return ['type' => $f['funder']['type'], 'id' => $f['funder']['id'], 'funding_id' => $f['id']];
            }
        }

        return null;
    }

    /**
     * The lines of the form, in order, with their amount in cents; every
     * computed line of the draft must be there (its amount may be 0).
     *
     * @param  array<string, mixed>  $data
     * @param  Collection<string, InvoiceLine>  $current
     * @return list<array{id?: string, label: string, description: ?string, quantity: string, unit: string, unit_price_cents: int, vat_rate: string}>
     *
     * @throws ValidationException
     */
    private function validated(array $data, $current): array
    {
        $lines = is_array($data['lines'] ?? null) ? array_values($data['lines']) : [];
        $validator = Validator::make(['lines' => $lines, 'notes' => $data['notes'] ?? null], [
            'lines' => ['present', 'array', 'max:'.self::MAX_LINES],
            'lines.*.id' => ['nullable', 'string'],
            'lines.*.label' => ['required', 'string', 'max:500'],
            'lines.*.description' => ['nullable', 'string', 'max:2000'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,7}([.,]\d{1,2})?$/', 'not_regex:/^0+([.,]0+)?$/'],
            'lines.*.unit' => ['required', 'in:'.implode(',', self::UNITS)],
            'lines.*.unit_price' => ['required'],
            'lines.*.vat_rate' => ['nullable'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'lines.max' => 'Une facture compte au plus '.self::MAX_LINES.' lignes.',
            'lines.*.label.required' => 'Indiquez le libellé de la ligne.',
            'lines.*.label.max' => 'Le libellé ne peut pas dépasser 500 caractères.',
            'lines.*.description.max' => 'La description ne peut pas dépasser 2 000 caractères.',
            'lines.*.quantity.required' => 'Indiquez la quantité.',
            'lines.*.quantity.regex' => 'Indiquez une quantité positive (ex. 2 ou 1,5).',
            'lines.*.quantity.not_regex' => 'Indiquez une quantité positive (ex. 2 ou 1,5).',
            'lines.*.unit.required' => 'Choisissez l\'unité.',
            'lines.*.unit.in' => 'Choisissez l\'unité.',
            'lines.*.unit_price.required' => 'Indiquez un montant valide (ex. 1 200,50).',
            'notes.max' => 'Les notes ne peuvent pas dépasser 2 000 caractères.',
        ]);
        $validator->after(function ($v) use ($lines, $current): void {
            $seen = [];
            foreach ($lines as $i => $line) {
                if (! is_array($line)) {
                    continue;
                }
                $price = array_key_exists('unit_price', $line) ? Money::parse($line['unit_price']) : 0;
                if ($price === null) {
                    $v->errors()->add("lines.{$i}.unit_price", 'Indiquez un montant valide (ex. 1 200,50).');
                } elseif (abs($price) > self::MAX_UNIT_PRICE_CENTS) {
                    $v->errors()->add("lines.{$i}.unit_price", 'Le prix unitaire ne peut pas dépasser 9 999 999,99 €.');
                }
                $quantity = is_string($line['quantity'] ?? null) || is_int($line['quantity'] ?? null) ? str_replace(',', '.', (string) $line['quantity']) : '';
                if (preg_match('/^\d{1,7}(\.\d{1,2})?$/', $quantity) === 1 && BigDecimal::of($quantity)->isGreaterThan(self::MAX_QUANTITY)) {
                    $v->errors()->add("lines.{$i}.quantity", 'La quantité ne peut pas dépasser 100 000.');
                }
                $rate = self::rate($line['vat_rate'] ?? null);
                if (($line['vat_rate'] ?? null) !== null && $line['vat_rate'] !== '' && $rate === null) {
                    $v->errors()->add("lines.{$i}.vat_rate", 'Choisissez un taux de TVA (0, 5,5, 10 ou 20 %).');
                }
                $id = $line['id'] ?? null;
                if ($id !== null && $id !== '') {
                    if (! is_string($id) || ! Str::isUuid($id) || ! $current->has($id) || isset($seen[$id])) {
                        $v->errors()->add("lines.{$i}.id", 'Cette ligne n\'appartient pas à la facture.');
                    }
                    $seen[(string) $id] = true;
                }
            }
            $computed = $current->filter(fn (InvoiceLine $l) => $l->kind === InvoiceLine::COMPUTED)->keys()->all();
            if (array_diff($computed, array_keys($seen)) !== []) {
                $v->errors()->add('lines', self::COMPUTED_KEPT);
            }
        });
        $validator->validate();

        return array_map(fn (array $line) => array_filter([
            'id' => ($line['id'] ?? '') !== '' ? (string) $line['id'] : null,
            'label' => trim((string) $line['label']),
            'description' => ($line['description'] ?? '') === '' ? null : trim((string) $line['description']),
            'quantity' => str_replace(',', '.', (string) $line['quantity']),
            'unit' => (string) $line['unit'],
            'unit_price_cents' => (int) Money::parse($line['unit_price']),
            'vat_rate' => self::rate($line['vat_rate'] ?? null) ?? InvoiceTotals::DEFAULT_RATE,
        ], fn ($v, $k) => $k !== 'id' || $v !== null, ARRAY_FILTER_USE_BOTH), $lines);
    }

    /** « 20 », « 5,5 », « 5.50 » → « 20.00 », « 5.50 »; null when not offered. */
    private static function rate(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $text = str_replace(',', '.', trim((string) $value));
        if (preg_match('/^\d{1,2}(\.\d{1,2})?$/', $text) !== 1) {
            return null;
        }
        $rate = (string) BigDecimal::of($text)->toScale(2);

        return in_array($rate, InvoiceTotals::RATES, true) ? $rate : null;
    }
}
