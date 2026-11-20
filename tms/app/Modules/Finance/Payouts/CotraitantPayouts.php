<?php

namespace App\Modules\Finance\Payouts;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\InvoiceAccess;
use App\Modules\Finance\Invoices\InvoiceLine;
use App\Modules\Finance\Invoices\InvoiceStatus;
use App\Modules\Finance\Invoices\InvoiceTotals;
use App\Modules\Finance\Invoices\Money;
use App\Modules\Finance\Payments\Balances;
use App\Modules\Finance\Permissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * What the mandataire owes to the co-contractors of a grouping (spec
 * 2026-11-20 facture libre et co-traitance), per issued invoice and
 * co-contractor: the TTC of its lines (VAT per rate on their sum, half
 * up), less the same lines given back by validated credit notes, less the
 * payouts recorded. Whether the buyer paid the invoice comes from
 * Balances. Free invoices only (a dossier invoice never names a
 * co-contractor), so a scope « Tout » is required.
 */
final class CotraitantPayouts
{
    /** %s: the amount left (« 300,00 € »). */
    public const TOO_MUCH = 'Ce reversement dépasse le reste à reverser (%s).';

    public const NOTHING_OWED = 'Rien n\'est dû à ce co-traitant sur cette facture.';

    public const NOT_ALL = 'Le suivi des reversements exige de voir toutes les factures (périmètre « Tout »).';

    public function __construct(private readonly Fiches $fiches) {}

    /**
     * @param  array{state?: string|null, cotraitant?: string|null}  $filters  state: open (default) | all
     * @return list<array{invoice_id: string, number: string, issued_on: ?string, url: string, recipient: string, cotraitant_id: string, cotraitant: string, owed_cents: int, paid_out_cents: int, left_cents: int, buyer_paid: bool, buyer_left_cents: int}>
     */
    public function owed(User $user, array $filters = []): array
    {
        self::guard($user);
        $invoices = Invoice::query()
            ->where('kind', Invoice::INVOICE)->whereIn('status', InvoiceStatus::values(InvoiceStatus::ISSUED))
            ->whereExists(fn ($q) => $q->from('invoice_lines as l')->whereColumn('l.invoice_id', 'invoices.id')->whereNotNull('l.cotraitant_id'))
            ->orderByDesc('issued_on')->orderByDesc('number')
            ->get(['id', 'number', 'issued_on', 'recipient_type', 'recipient_id']);
        $owed = $this->owedFor($invoices->pluck('id')->all());
        $paid = $this->paidOut($invoices->pluck('id')->all());
        $balances = Balances::of($invoices->pluck('id')->all());
        $names = $this->fiches->organisationLabels(collect($owed)->flatMap(fn (array $byCotraitant) => array_keys($byCotraitant))->unique()->values()->all());
        $clients = $this->fiches->organisationLabels($invoices->where('recipient_type', 'organisation')->pluck('recipient_id')->all());

        $rows = [];
        foreach ($invoices as $invoice) {
            foreach ($owed[$invoice->id] ?? [] as $cotraitant => $cents) {
                $out = $paid[$invoice->id][$cotraitant] ?? 0;
                $row = [
                    'invoice_id' => $invoice->id,
                    'number' => (string) $invoice->number,
                    'issued_on' => $invoice->issued_on?->toDateString(),
                    'url' => '/finance/invoices/'.$invoice->id,
                    'recipient' => $clients[$invoice->recipient_id]['label'] ?? '-',
                    'cotraitant_id' => $cotraitant,
                    'cotraitant' => $names[$cotraitant]['label'] ?? '-',
                    'owed_cents' => $cents,
                    'paid_out_cents' => $out,
                    'left_cents' => $cents - $out,
                    'buyer_paid' => ($balances[$invoice->id]['remaining_cents'] ?? 1) <= 0,
                    'buyer_left_cents' => (int) ($balances[$invoice->id]['remaining_cents'] ?? 0),
                ];
                if (($filters['cotraitant'] ?? null) !== null && $filters['cotraitant'] !== $cotraitant) {
                    continue;
                }
                if (($filters['state'] ?? 'open') !== 'all' && $row['left_cents'] <= 0) {
                    continue;
                }
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data  amount, paid_on (AAAA-MM-JJ), reference?, notes?
     *
     * @throws ValidationException
     * @throws UserFacingHttpException 403 without the scope « Tout », 409 nothing owed
     */
    public function record(User $user, string $invoiceId, string $cotraitantId, array $data): CotraitantPayout
    {
        self::guard($user);
        $values = Validator::make($data, [
            'amount' => ['required'],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'amount.required' => 'Indiquez le montant reversé.',
            'paid_on.required' => 'Indiquez la date du reversement.',
            'paid_on.date_format' => 'Indiquez une date valide.',
            'reference.max' => 'La référence ne peut pas dépasser 100 caractères.',
            'notes.max' => 'La note ne peut pas dépasser 2 000 caractères.',
        ])->validate();
        $cents = Money::parse($values['amount']);
        if ($cents === null || $cents <= 0) {
            throw ValidationException::withMessages(['amount' => 'Indiquez un montant positif (ex. 1 200,50).']);
        }

        return DB::transaction(function () use ($user, $invoiceId, $cotraitantId, $values, $cents): CotraitantPayout {
            // The invoice locked: two payouts at once never go beyond what is owed.
            $invoice = Invoice::query()->lockForUpdate()->find($invoiceId);
            $owed = $invoice === null ? 0 : ($this->owedFor([$invoice->id])[$invoice->id][$cotraitantId] ?? 0);
            if ($owed <= 0) {
                throw new UserFacingHttpException(409, self::NOTHING_OWED);
            }
            $left = $owed - ($this->paidOut([$invoiceId])[$invoiceId][$cotraitantId] ?? 0);
            if ($cents > $left) {
                throw ValidationException::withMessages(['amount' => sprintf(self::TOO_MUCH, Money::format(max(0, $left)))]);
            }

            return CotraitantPayout::query()->create([
                'invoice_id' => $invoiceId, 'cotraitant_id' => $cotraitantId, 'amount_cents' => $cents,
                'paid_on' => $values['paid_on'], 'reference' => self::text($values['reference'] ?? null),
                'notes' => self::text($values['notes'] ?? null), 'created_by' => $user->id,
            ]);
        });
    }

    public function delete(User $user, CotraitantPayout $payout): void
    {
        self::guard($user);
        $payout->delete();
    }

    /**
     * The payouts of an invoice and co-contractor, newest first.
     *
     * @return list<array{id: string, amount_cents: int, paid_on: string, reference: ?string, notes: ?string}>
     */
    public function history(string $invoiceId, string $cotraitantId): array
    {
        return CotraitantPayout::query()->where('invoice_id', $invoiceId)->where('cotraitant_id', $cotraitantId)
            ->orderByDesc('paid_on')->orderByDesc('created_at')->get()
            ->map(fn (CotraitantPayout $p) => [
                'id' => $p->id, 'amount_cents' => $p->amount_cents, 'paid_on' => $p->paid_on->toDateString(),
                'reference' => $p->reference, 'notes' => $p->notes,
            ])->values()->all();
    }

    /**
     * TTC owed per invoice and co-contractor: its lines on the invoice and
     * on the validated credit notes correcting it (negative).
     *
     * @param  list<string>  $invoiceIds
     * @return array<string, array<string, int>>
     */
    private function owedFor(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }
        $lines = InvoiceLine::query()->from('invoice_lines')
            ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
            ->whereNotNull('invoice_lines.cotraitant_id')
            ->whereIn('invoices.status', InvoiceStatus::values(InvoiceStatus::ISSUED))
            ->where(fn ($q) => $q->whereIn('invoices.id', $invoiceIds)->orWhereIn('invoices.corrected_invoice_id', $invoiceIds))
            ->get(['invoice_lines.*', DB::raw('coalesce(invoices.corrected_invoice_id, invoices.id) as owed_on')]);

        $owed = [];
        /** @var Collection<int, InvoiceLine> $group */
        foreach ($lines->groupBy(fn (InvoiceLine $l) => $l->getAttribute('owed_on').'|'.$l->cotraitant_id) as $key => $group) {
            [$invoice, $cotraitant] = explode('|', (string) $key, 2);
            $ttc = 0;
            foreach (InvoiceTotals::byRate($group) as $rate) {
                $ttc += $rate['base'] + $rate['vat'];
            }
            $owed[$invoice][$cotraitant] = ($owed[$invoice][$cotraitant] ?? 0) + $ttc;
        }

        return $owed;
    }

    /**
     * @param  list<string>  $invoiceIds
     * @return array<string, array<string, int>>
     */
    private function paidOut(array $invoiceIds): array
    {
        $paid = [];
        foreach (CotraitantPayout::query()->whereIn('invoice_id', $invoiceIds)
            ->groupBy('invoice_id', 'cotraitant_id')->selectRaw('invoice_id, cotraitant_id, sum(amount_cents) as total')->get() as $row) {
            $paid[(string) $row->getAttribute('invoice_id')][(string) $row->getAttribute('cotraitant_id')] = (int) $row->getAttribute('total');
        }

        return $paid;
    }

    private static function guard(User $user): void
    {
        if (! $user->can(Permissions::PAYMENTS) || ! InvoiceAccess::all($user, Permissions::VIEW)) {
            throw new UserFacingHttpException(403, self::NOT_ALL);
        }
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
