<?php

namespace App\Modules\Finance\Invoices;

/**
 * What was already invoiced on a dossier, per recipient (« contractor »,
 * « funding:<id> »; spec 0.9 § 4.3 step 4 and § 4 bis 3): the computed
 * lines of its validated (or sent) invoices and credit notes — the latter
 * negative, so a plain sum — at the amount really invoiced; free lines and
 * informative mentions never count. Open drafts are « in preparation »: not
 * invoiced, but one per recipient (Préparer skips it).
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

    /** @var array<string, Invoice> recipient => its open draft */
    public array $drafts = [];

    /** @var array<string, Invoice> recipient => its latest validated invoice (not a credit note) */
    public array $latestInvoice = [];

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
                $ledger->drafts[$key] ??= $invoice;

                continue;
            }
            if ($invoice->kind === Invoice::INVOICE) {
                $ledger->latestInvoice[$key] = $invoice;
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

    public function invoiced(string $key, string $component): int
    {
        return $this->invoiced[$key][$component] ?? 0;
    }
}
