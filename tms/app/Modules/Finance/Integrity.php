<?php

namespace App\Modules\Finance;

use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\InvoiceJournalEntry;
use App\Modules\Finance\Invoices\InvoiceLine;
use App\Modules\Finance\Invoices\InvoiceStatus;
use Illuminate\Support\Collection;

/**
 * The tamper-evident chain of the validated invoices and credit notes of
 * the organisme (spec 0.9 § 4.4, NF203-compatible):
 *
 *  - canonical(): the frozen content of an invoice as JSON, keys sorted at
 *    every level, no whitespace — number, kind, company, dossier,
 *    recipient (type, id, funding, snapshot), dates, period, corrected
 *    invoice, VAT mention, lines (in order) and totals;
 *  - hash(): SHA-256 (hex) of that JSON followed by the hash of the
 *    previous piece of the organisme ('' for the first);
 *  - verify(): walks the journal in order and recomputes every link; the
 *    first number whose content, totals, link or journal row does not
 *    match, or an issued invoice missing from the journal, breaks it.
 */
final class Integrity
{
    public static function hash(Invoice $invoice, ?string $previous): string
    {
        return hash('sha256', self::canonical($invoice).($previous ?? ''));
    }

    /**
     * @param  Collection<int, InvoiceLine>|null  $lines  the lines in order (default: read)
     */
    public static function canonical(Invoice $invoice, ?Collection $lines = null): string
    {
        $lines ??= InvoiceLine::query()->where('invoice_id', $invoice->id)->orderBy('position')->get();
        $content = [
            'number' => $invoice->number,
            'kind' => $invoice->kind,
            'company_id' => $invoice->company_id,
            'dossier_id' => $invoice->dossier_id,
            'recipient' => [
                'type' => $invoice->recipient_type,
                'id' => $invoice->recipient_id,
                'funding_id' => $invoice->funding_id,
                'snapshot' => $invoice->recipient_snapshot,
            ],
            'issued_on' => $invoice->issued_on?->toDateString(),
            'due_on' => $invoice->due_on?->toDateString(),
            'period_from' => $invoice->period_from?->toDateString(),
            'period_to' => $invoice->period_to?->toDateString(),
            'corrected_invoice_id' => $invoice->corrected_invoice_id,
            'vat_mention' => $invoice->vat_mention,
            'lines' => $lines->map(fn (InvoiceLine $l) => [
                'position' => $l->position,
                'kind' => $l->kind,
                'component' => $l->component,
                'label' => $l->label,
                'description' => $l->description,
                'quantity' => (string) $l->quantity,
                'unit' => $l->unit,
                'unit_price_cents' => $l->unit_price_cents,
                'vat_rate' => (string) $l->vat_rate,
                'total_ht_cents' => $l->total_ht_cents,
                'dossier_line_id' => $l->dossier_line_id,
                'enrolment_id' => $l->enrolment_id,
                'funding_id' => $l->funding_id,
            ])->values()->all(),
            'totals' => [
                'ht' => $invoice->total_ht_cents,
                'vat' => $invoice->total_vat_cents,
                'ttc' => $invoice->total_ttc_cents,
            ],
        ];

        return json_encode(self::sorted($content), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * The chain of the current organisme: null when whole, else the number
     * of the first piece that breaks it.
     *
     * @return array{count: int, broken: ?string}
     */
    public static function verify(): array
    {
        $previous = null;
        $count = 0;
        $journaled = [];
        foreach (InvoiceJournalEntry::query()->orderBy('position')->lazy(200) as $entry) {
            $count++;
            $journaled[$entry->invoice_id] = true;
            $invoice = Invoice::query()->find($entry->invoice_id);
            $intact = $invoice !== null
                && $entry->position === $count
                && $entry->previous_hash === $previous
                && in_array($invoice->status, InvoiceStatus::ISSUED, true)
                && $invoice->number === $entry->number
                && $invoice->kind === $entry->kind
                && $invoice->issued_on?->toDateString() === $entry->issued_on->toDateString()
                && $invoice->total_ht_cents === $entry->total_ht_cents
                && $invoice->total_vat_cents === $entry->total_vat_cents
                && $invoice->total_ttc_cents === $entry->total_ttc_cents
                && $invoice->hash === $entry->hash
                && $invoice->previous_hash === $entry->previous_hash
                && self::hash($invoice, $previous) === $entry->hash;
            if (! $intact) {
                return ['count' => $count, 'broken' => $entry->number];
            }
            $previous = $entry->hash;
        }

        $missing = Invoice::query()->whereIn('status', InvoiceStatus::values(InvoiceStatus::ISSUED))
            ->orderBy('validated_at')->orderBy('number')->get(['id', 'number'])
            ->first(fn (Invoice $i) => ! isset($journaled[$i->id]));

        return ['count' => $count, 'broken' => $missing?->number];
    }

    /**
     * Keys sorted at every level of an associative array; lists keep their order.
     */
    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::sorted(...), $value);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
