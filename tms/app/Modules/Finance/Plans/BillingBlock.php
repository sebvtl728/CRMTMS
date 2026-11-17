<?php

namespace App\Modules\Finance\Plans;

use App\Modules\Core\Users\User;
use App\Modules\Finance\Permissions;
use App\Modules\Finance\Settings\FinanceSettings;
use App\Modules\Sessions\Public\Billing;

/**
 * The « Facturation » block of the dossier page (registered in
 * Sessions\Public\DossierBlocks): the billing plan, editable by who manages
 * the invoicing of the dossier, and its invoices (rows, Task 4). Shown to
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

        return [
            'title' => 'Facturation',
            'empty' => 'Aucune facture pour ce dossier.',
            'rows' => [],
            'component' => self::COMPONENT,
            'props' => [
                'plan' => app(BillingPlans::class)->for($dossierId),
                'organisme_absences' => FinanceSettings::absencesDefault(),
                // No instalment before it (null: no session yet).
                'starts_on' => $starts === [] ? null : min($starts),
                'can_manage' => ! $dossier['trashed'] && Billing::allows($viewer, Permissions::MANAGE, $dossierId),
                'update_url' => route('finance.plans.update', $dossierId, absolute: false),
            ],
        ];
    }
}
