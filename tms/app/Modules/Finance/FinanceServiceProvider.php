<?php

namespace App\Modules\Finance;

use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Audit\MorphAliases;
use App\Modules\Core\Users\User;
use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\InvoiceLine;
use App\Modules\Finance\Plans\BillingBlock;
use App\Modules\Finance\Plans\BillingPlan;
use App\Modules\Finance\Plans\BillingPlans;
use App\Modules\Finance\Settings\FinanceSettings;
use App\Modules\Sessions\Public\BillingGuards;
use App\Modules\Sessions\Public\DossierBlocks;
use Illuminate\Support\ServiceProvider;

/**
 * Module Finance (0.9): invoices and credit notes of the dossiers. It reads
 * Sessions through Sessions\Public only (Billing, DossierBlocks,
 * BillingGuards, DossierStates) and the CRM through Crm\Public; no module
 * depends on it (registries of theirs).
 */
class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // In register(): the catalogue must be complete before
        // AccessServiceProvider::boot() defines one gate per permission.
        CorePermissions::registerModule('finance', Permissions::all(), Permissions::defaultsFor(...), Permissions::labels(), Permissions::GROUP, Permissions::all());

        MorphAliases::register([
            BillingPlan::MORPH_ALIAS => BillingPlan::class,
            FinanceSettings::MORPH_ALIAS => FinanceSettings::class,
            Invoice::MORPH_ALIAS => Invoice::class,
            InvoiceLine::MORPH_ALIAS => InvoiceLine::class,
        ]);

        $this->app->singleton(BillingPlans::class);
    }

    public function boot(): void
    {
        // « Facturation » block of the dossier page.
        DossierBlocks::register('finance.billing', fn (string $dossierId, User $viewer) => BillingBlock::for($dossierId, $viewer));

        // A validated invoice freezes who pays directly and keeps the dossier out of the trash.
        $hasInvoices = fn (string $dossierId): ?string => app(BillingPlans::class)->isLocked($dossierId) ? BillingPlans::HAS_INVOICES : null;
        BillingGuards::register(BillingGuards::FUNDING_LOCKED, 'finance.invoices', $hasInvoices);
        BillingGuards::register(BillingGuards::DOSSIER_TRASHABLE, 'finance.invoices', $hasInvoices);
    }
}
