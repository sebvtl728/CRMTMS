<?php

namespace App\Modules\Finance;

use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Audit\MorphAliases;
use App\Modules\Core\Companies\PrefixUsage;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\FicheLinks;
use App\Modules\Finance\Invoices\FinanceMailTemplates;
use App\Modules\Finance\Invoices\Invoice;
use App\Modules\Finance\Invoices\InvoiceAnonymisation;
use App\Modules\Finance\Invoices\InvoiceLine;
use App\Modules\Finance\Invoices\InvoiceSending;
use App\Modules\Finance\Invoices\InvoiceStatus;
use App\Modules\Finance\Payments\Payment;
use App\Modules\Finance\Payments\PaymentAllocation;
use App\Modules\Finance\Payouts\CotraitantPayout;
use App\Modules\Finance\Payments\PaymentAnonymisation;
use App\Modules\Finance\Plans\BillingBlock;
use App\Modules\Finance\Plans\BillingPlan;
use App\Modules\Finance\Plans\BillingPlans;
use App\Modules\Finance\Reminders\ReminderPause;
use App\Modules\Finance\Settings\FinanceSettings;
use App\Modules\Sessions\Public\BillingGuards;
use App\Modules\Sessions\Public\DossierBlocks;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        CorePermissions::registerModule('finance', Permissions::all(), Permissions::defaultsFor(...), Permissions::labels(), Permissions::GROUP, Permissions::scoped());

        MorphAliases::register([
            BillingPlan::MORPH_ALIAS => BillingPlan::class,
            FinanceSettings::MORPH_ALIAS => FinanceSettings::class,
            Invoice::MORPH_ALIAS => Invoice::class,
            InvoiceLine::MORPH_ALIAS => InvoiceLine::class,
            InvoiceSending::MORPH_ALIAS => InvoiceSending::class,
            Payment::MORPH_ALIAS => Payment::class,
            PaymentAllocation::MORPH_ALIAS => PaymentAllocation::class,
            CotraitantPayout::MORPH_ALIAS => CotraitantPayout::class,
            ReminderPause::MORPH_ALIAS => ReminderPause::class,
        ]);

        FinanceMailTemplates::register();

        $this->app->singleton(BillingPlans::class);
    }

    public function boot(): void
    {
        // A payment method in use is never deleted.
        ReferenceUsage::register(ReferenceList::PaymentMethod, 'payments', 'method_id');

        // « Facturation » block of the dossier page.
        DossierBlocks::register('finance.billing', fn (string $dossierId, User $viewer) => BillingBlock::for($dossierId, $viewer));

        // A validated invoice freezes who pays directly, the issuing company and the contractor,
        // and keeps the dossier out of the trash; an invoiced funding keeps its funder.
        $hasInvoices = fn (string $dossierId): ?string => app(BillingPlans::class)->isLocked($dossierId) ? BillingPlans::HAS_INVOICES : null;
        BillingGuards::register(BillingGuards::FUNDING_LOCKED, 'finance.invoices', $hasInvoices);
        BillingGuards::register(BillingGuards::DOSSIER_TRASHABLE, 'finance.invoices', $hasInvoices);
        BillingGuards::register(BillingGuards::PARTIES_LOCKED, 'finance.invoices', $hasInvoices);
        BillingGuards::register(BillingGuards::FUNDER_LOCKED, 'finance.invoices', fn (string $fundingId): ?string => Invoice::query()
            ->where('funding_id', $fundingId)->whereIn('status', InvoiceStatus::values(InvoiceStatus::ISSUED))->exists() ? BillingPlans::HAS_INVOICES : null);

        // A prefix that numbered invoices never numbers credit notes of the company, nor the reverse.
        PrefixUsage::register('finance.invoices', fn (string $companyId, string $kind, string $prefix): bool => Invoice::query()
            ->where('company_id', $companyId)->where('kind', $kind)->whereNotNull('number')->where('number', 'like', $prefix.'-%')->exists());

        // Anonymising a person: her drafts go (addressed to her, or naming her as a trainee);
        // validated invoices are accounting records kept 10 years, never changed (spec § 4.7).
        FicheLinks::registerAnonymisation('finance.invoices', fn (array $personIds) => InvoiceAnonymisation::deleteDrafts($personIds));
        // Her payments are kept too (10 years): their notes and reference are cleared (0.9.1).
        FicheLinks::registerAnonymisation('finance.payments', fn (array $personIds) => PaymentAnonymisation::clear($personIds));

        // Rendering a PDF (preview, archive at validation) costs a conversion.
        $by = fn (Request $request, string $name): string => $name.':'.($request->user()?->getAuthIdentifier() ?? $request->ip());
        RateLimiter::for('invoice-mail', fn (Request $request) => Limit::perMinute(20)->by($by($request, 'invoice-mail')));
        // The search of a payer (Finance › Règlements), typed letter by letter.
        RateLimiter::for('finance-lookup', fn (Request $request) => Limit::perMinute(60)->by($by($request, 'finance-lookup')));
        RateLimiter::for('finance-export', fn (Request $request) => Limit::perMinute(20)->by($by($request, 'finance-export')));
        // Recomputing the chain reads every archived PDF.
        RateLimiter::for('invoice-integrity', fn (Request $request) => Limit::perMinute(6)->by($by($request, 'invoice-integrity')));
        RateLimiter::for('invoice-pdf', fn (Request $request) => Limit::perMinute(30)->by('invoice-pdf:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
