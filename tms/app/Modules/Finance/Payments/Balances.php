<?php

namespace App\Modules\Finance\Payments;

use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\InvoiceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What is left to pay on issued invoices (spec 0.9.1 § 4.2):
 *
 *   remaining = TTC − |TTC of the validated credit notes correcting it|
 *               − Σ payment allocations
 *
 * Only invoices (never a credit note) validated or sent; a draft has no
 * balance. Paid when remaining ≤ 0 (< 0: overpaid, shown, never refunded by
 * the TMS); overdue when not settled and its due date is before today
 * (Paris). Two queries whatever the number of invoices.
 *
 * @phpstan-type Balance array{ttc_cents: int, credited_cents: int, paid_cents: int, remaining_cents: int, status: PaymentStatus, overdue: bool, overpaid: bool, due_on: ?string}
 */
final class Balances
{
    /**
     * @param  list<string>  $invoiceIds
     * @return array<string, Balance> keyed by invoice id (issued invoices only)
     */
    public static function of(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }
        $rows = DB::table('invoices as i')
            ->whereIn('i.id', array_values(array_unique($invoiceIds)))
            ->where('i.kind', Invoice::INVOICE)
            ->whereIn('i.status', InvoiceStatus::values(InvoiceStatus::ISSUED))
            ->select('i.id', 'i.total_ttc_cents', 'i.due_on')
            ->selectSub(self::credited(), 'credited_cents')
            ->selectSub(self::paid(), 'paid_cents')
            ->get();

        $today = self::today();
        $balances = [];
        foreach ($rows as $row) {
            $balances[(string) $row->id] = self::balance((int) $row->total_ttc_cents, (int) $row->credited_cents, (int) $row->paid_cents, $row->due_on === null ? null : substr((string) $row->due_on, 0, 10), $today);
        }

        return $balances;
    }

    /**
     * @return Balance|null null for a draft or a credit note
     */
    public static function one(Invoice $invoice): ?array
    {
        return self::of([$invoice->id])[$invoice->id] ?? null;
    }

    /**
     * Σ |TTC| of the validated credit notes of the invoice row « i ».
     */
    public static function credited(string $invoice = 'i.id'): Builder
    {
        return DB::table('invoices as c')
            ->selectRaw('coalesce(-sum(c.total_ttc_cents), 0)')
            ->whereColumn('c.corrected_invoice_id', $invoice)
            ->where('c.kind', Invoice::CREDIT_NOTE)
            ->whereIn('c.status', InvoiceStatus::values(InvoiceStatus::ISSUED));
    }

    /**
     * Σ allocations to the invoice row « i ».
     */
    public static function paid(string $invoice = 'i.id'): Builder
    {
        return DB::table('payment_allocations as pa')
            ->selectRaw('coalesce(sum(pa.amount_cents), 0)')
            ->whereColumn('pa.invoice_id', $invoice);
    }

    /**
     * The SQL expression of the remaining due of the invoice row « i » (the
     * lists filter on it).
     */
    public static function remainingSql(string $alias = 'invoices'): string
    {
        $credited = 'coalesce((select -sum(c.total_ttc_cents) from invoices c where c.corrected_invoice_id = '.$alias.".id and c.kind = 'credit_note' and c.status in ('validated', 'sent')), 0)";
        $paid = '(select coalesce(sum(pa.amount_cents), 0) from payment_allocations pa where pa.invoice_id = '.$alias.'.id)';

        return '('.$alias.'.total_ttc_cents - '.$credited.' - '.$paid.')';
    }

    /** Today, a day of Paris. */
    public static function today(): string
    {
        return CarbonImmutable::now('Europe/Paris')->toDateString();
    }

    /**
     * @return Balance
     */
    private static function balance(int $ttc, int $credited, int $paid, ?string $dueOn, string $today): array
    {
        $remaining = $ttc - $credited - $paid;
        $status = $remaining <= 0 ? PaymentStatus::Paid : ($paid > 0 ? PaymentStatus::Partial : PaymentStatus::Unpaid);

        return [
            'ttc_cents' => $ttc,
            'credited_cents' => $credited,
            'paid_cents' => $paid,
            'remaining_cents' => $remaining,
            'status' => $status,
            'overdue' => $remaining > 0 && $dueOn !== null && $dueOn < $today,
            'overpaid' => $remaining < 0,
            'due_on' => $dueOn,
        ];
    }
}
