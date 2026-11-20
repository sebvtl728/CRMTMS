<?php

namespace App\Modules\Finance\Invoices;

/**
 * What was already invoiced on a dossier, per recipient (« contractor »,
 * « funding:<id> »; spec 0.9 § 4.3 step 4 and § 4 bis 3): the computed
 * lines of its validated (or sent) invoices and credit notes - the latter
 * negative, so a plain sum - at the amount really invoiced; free lines and
 * informative mentions never count. Open drafts are « in preparation »: not
 * invoiced, but one per recipient and kind (Préparer skips it).
 */
final class Ledger
{
    /** @var array<string, array<string, int>> recipient => component => cents */
    public array $invoiced = [];

    /** @var array<string, array<string, int>> recipient => dossier line id => training cents */
    public array $perLine = [];

    /** @var array<string, int> recipient => Σ (invoiced − computed) of its computed lines changed by hand */
    public array $modified = [];

    /** @var array<string, string> recipient => the latest cut-off invoiced (« 2026-09 » or a day) */
    public array $lastCut = [];

    /** @var array<string, array<string, Invoice>> recipient => kind (invoice, credit_note) => its open draft */
    public array $drafts = [];

    /** @var array<string, Invoice> recipient => its latest validated invoice (not a credit note) */
    public array $latestInvoice = [];

    /** @var array<string, list<Invoice>> recipient => its validated invoices (not credit notes), in order of validation */
    public array $invoices = [];

    /** @var array<string, int> invoice id => Σ HT of the validated credit notes correcting it (negative) */
    public array $credited = [];

    /** Some invoice or credit note of the dossier is validated. */
    public bool $issued = false;

    /** @var array<string, array{role: string, type: string, id: string, funding_id: ?string}> recipients met on the invoices */
    public array $recipients = [];

    public static function of(string $dossierId): self
    {
        $ledger = new self;
        $invoices = Invoice::query()->where('dossier_id', $dossierId)->with('lines')
            ->orderBy('validated_at')->orderBy('created_at')->orderBy('id')->get();

        foreach ($invoices as $invoice) {
            $key = self::keyOf($invoice);
            $ledger->recipients[$key] ??= [
                'role' => $invoice->funding_id === null ? 'contractor' : 'funder',
                'type' => $invoice->recipient_type,
                'id' => $invoice->recipient_id,
                'funding_id' => $invoice->funding_id,
            ];
            if ($invoice->isDraft()) {
                $ledger->drafts[$key][$invoice->kind] ??= $invoice;

                continue;
            }
            $ledger->issued = true;
            if ($invoice->kind === Invoice::INVOICE) {
                $ledger->latestInvoice[$key] = $invoice;
                $ledger->invoices[$key][] = $invoice;
            } elseif ($invoice->corrected_invoice_id !== null) {
                $ledger->credited[$invoice->corrected_invoice_id] = ($ledger->credited[$invoice->corrected_invoice_id] ?? 0) + $invoice->total_ht_cents;
            }
            if ($invoice->cut_off !== null && $invoice->cut_off > ($ledger->lastCut[$key] ?? '')) {
                $ledger->lastCut[$key] = $invoice->cut_off;
            }
            foreach ($invoice->lines as $line) {
                if ($line->kind !== InvoiceLine::COMPUTED || ! in_array($line->component, InvoiceLine::BILLED, true)) {
                    continue;
                }
                $component = (string) $line->component;
                $ledger->invoiced[$key][$component] = ($ledger->invoiced[$key][$component] ?? 0) + $line->total_ht_cents;
                if ($component === InvoiceLine::TRAINING) {
                    $lineId = (string) $line->dossier_line_id;
                    $ledger->perLine[$key][$lineId] = ($ledger->perLine[$key][$lineId] ?? 0) + $line->total_ht_cents;
                }
                if ($line->computed_cents !== null && $line->computed_cents !== $line->total_ht_cents) {
                    $ledger->modified[$key] = ($ledger->modified[$key] ?? 0) + $line->total_ht_cents - $line->computed_cents;
                }
            }
        }

        return $ledger;
    }

    /** « contractor » or « funding:<id> ». */
    public static function keyOf(Invoice $invoice): string
    {
        return $invoice->funding_id === null ? Invoice::CONTRACTOR : 'funding:'.$invoice->funding_id;
    }

    /**
     * The most recent validated invoice of the recipient that a credit note
     * may still correct, with what is left of it (its HT net of its
     * validated credit notes, > 0); null when none is left.
     *
     * @return array{invoice: Invoice, capacity: int}|null
     */
    public function creditable(string $key): ?array
    {
        $invoices = $this->invoices[$key] ?? [];
        usort($invoices, fn (Invoice $a, Invoice $b) => [$b->validated_at, $b->issued_on, $b->number] <=> [$a->validated_at, $a->issued_on, $a->number]);
        foreach ($invoices as $invoice) {
            $capacity = $invoice->total_ht_cents + ($this->credited[$invoice->id] ?? 0);
            if ($capacity > 0) {
                return ['invoice' => $invoice, 'capacity' => $capacity];
            }
        }

        return null;
    }

    public function invoiced(string $key, string $component): int
    {
        return $this->invoiced[$key][$component] ?? 0;
    }
}
