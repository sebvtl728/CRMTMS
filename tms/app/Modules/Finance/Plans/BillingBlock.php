<?php

namespace App\Modules\Finance\Plans;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Finance\Invoices\Due;
use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\InvoiceRows;
use App\Modules\Finance\Invoices\Money;
use App\Modules\Finance\Permissions;
use App\Modules\Finance\Settings\FinanceSettings;
use App\Modules\Sessions\Public\Billing;

/**
 * The « Facturation » block of the dossier page (registered in
 * Sessions\Public\DossierBlocks): the billing plan, editable by who manages
 * the invoicing of the dossier, its fundings paying directly, what is left
 * to invoice, and its invoices and credit notes (rows). Shown to
 * who reads the invoicing of the dossier (finance.invoices.view and its
 * scope), a dossier in the trash included (read only).
 */
final class BillingBlock
{
    public const COMPONENT = 'finance.billing';

    /**
     * @return array{title: string, empty: string, rows: list<array<string, mixed>>, component: string, props: array<string, mixed>}|null
     */
    public static function for(string $dossierId, User $viewer): ?array
    {
        if (! Billing::allows($viewer, Permissions::VIEW, $dossierId, withTrashed: true)) {
            return null;
        }
        $dossier = Billing::dossier($dossierId);
        if ($dossier === null) {
            return null;
        }
        $starts = array_column($dossier['sessions'], 'starts_on');
        $canManage = ! $dossier['trashed'] && Billing::allows($viewer, Permissions::MANAGE, $dossierId);
        $invoices = Invoice::query()->where('dossier_id', $dossierId)->orderBy('created_at')->orderBy('id')->get();
        $rows = app(InvoiceRows::class);
        $due = $dossier['trashed'] ? ['rows' => [], 'soon' => []] : app(Due::class)->forDossier($dossierId);
        $direct = array_values(array_filter($dossier['fundings'], fn (array $f) => $f['pays_directly'] && ! $f['external_billing']));
        $funders = app(Fiches::class)->organisationLabels(array_values(array_map(fn (array $f) => $f['funder']['id'], array_filter($direct, fn (array $f) => $f['funder']['type'] === 'organisation'))))
            + app(Fiches::class)->personLabels(array_values(array_map(fn (array $f) => $f['funder']['id'], array_filter($direct, fn (array $f) => $f['funder']['type'] === 'person'))));
        $funder = fn (array $f) => $funders[$f['funder']['id']]['label'] ?? '—';

        return [
            'title' => 'Facturation',
            'empty' => 'Aucune facture pour ce dossier.',
            'rows' => array_map(fn (array $r) => [
                'id' => $r['id'],
                'title' => $r['title'],
                'subtitle' => $r['recipient'],
                'meta' => Money::format($r['total_ht_cents']).' HT · '.Money::format($r['total_ttc_cents']).' TTC',
                'badge' => $r['status_label'],
                'href' => $r['url'],
            ], $rows->rows($invoices)),
            'component' => self::COMPONENT,
            'props' => [
                'plan' => app(BillingPlans::class)->for($dossierId),
                'organisme_absences' => FinanceSettings::absencesDefault(),
                // No instalment before it (null: no session yet).
                'starts_on' => $starts === [] ? null : min($starts),
                'can_manage' => $canManage,
                'update_url' => route('finance.plans.update', $dossierId, absolute: false),
                // Fundings paying directly, each invoiced on its own.
                'direct_fundings' => array_map(fn (array $f) => [
                    'id' => $f['id'], 'funder' => $funder($f), 'amount_cents' => $f['amount_cents'], 'status' => $f['status'], 'agreement_number' => $f['agreement_number'],
                ], $direct),
                // What is left to invoice now (« À facturer »), or why not yet.
                'due' => array_map(fn (array $r) => [
                    'recipient' => $r['recipient']['label'], 'kind' => $r['kind'], 'total_cents' => $r['total_cents'], 'period' => $r['period']['label'],
                    'draft_url' => $r['draft']['url'] ?? null,
                ], $due['rows']),
                'soon' => $due['soon'][0]['reason'] ?? null,
                // A draft made by hand, for the contractor or a funder paying directly.
                'create_url' => $canManage ? route('finance.invoices.store', $dossierId, absolute: false) : null,
                'recipients' => [
                    ['value' => Invoice::CONTRACTOR, 'label' => 'Contractant'],
                    ...array_map(fn (array $f) => ['value' => 'funding:'.$f['id'], 'label' => 'Financeur : '.$funder($f)], $direct),
                ],
            ],
        ];
    }
}
