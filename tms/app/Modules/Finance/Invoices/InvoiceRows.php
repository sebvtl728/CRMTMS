<?php

namespace App\Modules\Finance\Invoices;

use App\Modules\Crm\Public\Fiches;
use App\Modules\Finance\Payments\Balances;
use App\Modules\Sessions\Public\Billing;
use Illuminate\Support\Collection;

/**
 * Invoices as the pages show them (list, page, dossier block): names of the
 * recipients and numbers of the dossiers read in one go per page.
 */
final class InvoiceRows
{
    public function __construct(private readonly Fiches $fiches) {}

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $invoices): array
    {
        $labels = $this->recipients($invoices);
        $numbers = Billing::numbers($invoices->pluck('dossier_id')->filter()->unique()->values()->all());
        $balances = Balances::of($invoices->pluck('id')->all());

        return $invoices->map(fn (Invoice $i) => [
            'id' => $i->id,
            'title' => $i->title(),
            'kind' => $i->kind,
            'kind_label' => $i->kindLabel(),
            'number' => $i->number,
            'status' => $i->status->value,
            'status_label' => $i->status->label(),
            'issued_on' => $i->issued_on?->toDateString(),
            'created_on' => $i->created_at?->toDateString(),
            'recipient' => $labels[$i->recipient_type.':'.$i->recipient_id] ?? '-',
            'recipient_role' => $i->funding_id === null ? 'contractor' : 'funder',
            'origin' => $i->origin,
            // A free invoice has no dossier (2026-11-20).
            'dossier' => $i->dossier_id === null ? null : ['id' => $i->dossier_id, 'number' => $numbers[$i->dossier_id]['number'] ?? '-', 'url' => '/dossiers/'.$i->dossier_id],
            'total_ht_cents' => $i->total_ht_cents,
            'total_ttc_cents' => $i->total_ttc_cents,
            // Issued invoices only (never a draft nor a credit note): what is paid and left (0.9.1).
            'paid_cents' => $balances[$i->id]['paid_cents'] ?? null,
            'remaining_cents' => $balances[$i->id]['remaining_cents'] ?? null,
            'payment_status' => isset($balances[$i->id]) ? $balances[$i->id]['status']->value : null,
            'payment_status_label' => isset($balances[$i->id]) ? $balances[$i->id]['status']->label() : null,
            'overdue' => $balances[$i->id]['overdue'] ?? false,
            'url' => '/finance/invoices/'.$i->id,
        ])->values()->all();
    }

    /**
     * Names keyed « organisation:<id> », « person:<id> ».
     *
     * @param  Collection<int, Invoice>  $invoices
     * @return array<string, string>
     */
    public function recipients(Collection $invoices): array
    {
        $labels = [];
        $organisations = $invoices->where('recipient_type', 'organisation')->pluck('recipient_id')->unique()->values()->all();
        foreach ($this->fiches->organisationLabels($organisations) as $id => $o) {
            $labels['organisation:'.$id] = $o['label'];
        }
        $people = $invoices->where('recipient_type', 'person')->pluck('recipient_id')->unique()->values()->all();
        foreach ($this->fiches->personLabels($people) as $id => $p) {
            $labels['person:'.$id] = $p['label'];
        }

        return $labels;
    }

    /**
     * The page of one invoice: header and lines.
     *
     * @return array<string, mixed>
     */
    public function page(Invoice $invoice): array
    {
        $row = $this->rows(new Collection([$invoice]))[0];
        $corrected = $invoice->corrected_invoice_id === null ? null : Invoice::query()->find($invoice->corrected_invoice_id);
        $cotraitants = InvoiceRecipient::cotraitants($invoice);

        return [
            ...$row,
            'recipient' => ['label' => $row['recipient'], 'role' => $row['recipient_role'], 'type' => $invoice->recipient_type],
            'period' => [
                'from' => $invoice->period_from?->toDateString(),
                'to' => $invoice->period_to?->toDateString(),
            ],
            'cut_off' => $invoice->cut_off,
            'buyer_reference' => $invoice->buyer_reference,
            'service_code' => $invoice->service_code,
            'rhythm_label' => $invoice->isFree() ? null : (Due::RHYTHMS[$invoice->rhythm] ?? $invoice->rhythm),
            'total_vat_cents' => $invoice->total_vat_cents,
            'vat_mention' => $invoice->vat_mention,
            'notes' => $invoice->notes,
            'due_on' => $invoice->due_on?->toDateString(),
            'corrected' => $corrected === null ? null : ['id' => $corrected->id, 'title' => $corrected->title(), 'url' => '/finance/invoices/'.$corrected->id],
            'lines' => $invoice->lines()->get()->map(fn (InvoiceLine $l) => [
                'id' => $l->id,
                'kind' => $l->kind,
                'component' => $l->component,
                'label' => $l->label,
                'description' => $l->description,
                'quantity' => str_replace('.', ',', (string) $l->quantity),
                'unit' => $l->unit,
                'unit_price' => Money::input($l->unit_price_cents),
                'unit_price_cents' => $l->unit_price_cents,
                'vat_rate' => (string) $l->vat_rate,
                'total_ht_cents' => $l->total_ht_cents,
                'computed_cents' => $l->computed_cents,
                'planned_minutes' => $l->planned_minutes,
                'realised_minutes' => $l->realised_minutes,
                'cotraitant_id' => $l->cotraitant_id,
                'cotraitant_label' => $l->cotraitant_id === null ? null : ($cotraitants[$l->cotraitant_id] ?? '-'),
            ])->values()->all(),
        ];
    }
}
