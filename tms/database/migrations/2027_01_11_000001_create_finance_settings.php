<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invoicing settings (0.9, module Finance):
     *
     *  - billing_plans: how a dossier is invoiced, one row per dossier,
     *    created at its first setting (none: « à la fin », « au réalisé »,
     *    the organisme's absences rule); locked_at is set by the first
     *    validated invoice of the dossier (rhythm, basis and schedule no
     *    longer change: a credit note first);
     *  - finance_settings: the organisme's defaults (one row per tenant,
     *    created at the first change);
     *  - companies: numbering prefixes of invoices and credit notes, payment
     *    terms and the footer of the invoices of each issuing company.
     */
    public function up(): void
    {
        Schema::create('billing_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('dossier_id')->unique()->constrained('dossiers');
            $table->string('rhythm', 10)->default('end');
            $table->string('basis', 10)->default('realised');
            // Null: the organisme's default (finance_settings).
            $table->string('unjustified_absences', 20)->nullable();
            // [{due_on: 'Y-m-d', percent: int}], sorted by date, summing to 100; rhythm « schedule » only.
            $table->jsonb('schedule')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement("alter table billing_plans add constraint billing_plans_rhythm_check check (rhythm in ('end', 'monthly', 'schedule'))");
        DB::statement("alter table billing_plans add constraint billing_plans_basis_check check (basis in ('realised', 'planned'))");
        DB::statement("alter table billing_plans add constraint billing_plans_absences_check check (unjustified_absences is null or unjustified_absences in ('not_billed', 'bill_contractor'))");
        DB::statement("alter table billing_plans add constraint billing_plans_schedule_check check ((rhythm = 'schedule') = (schedule is not null))");
        Rls::enable('billing_plans');

        Schema::create('finance_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('unjustified_absences', 20)->default('not_billed');
            $table->timestamps();
        });
        DB::statement("alter table finance_settings add constraint finance_settings_absences_check check (unjustified_absences in ('not_billed', 'bill_contractor'))");
        Rls::enable('finance_settings');

        Schema::table('companies', function (Blueprint $table) {
            $table->string('invoice_prefix', 10)->default('F');
            $table->string('credit_note_prefix', 10)->default('AV');
            $table->smallInteger('payment_terms_days')->default(30);
            $table->text('invoice_footer')->nullable();
        });
        DB::statement("alter table companies add constraint companies_invoice_prefixes_check check (invoice_prefix ~ '^[A-Z0-9]{1,10}$' and credit_note_prefix ~ '^[A-Z0-9]{1,10}$' and invoice_prefix <> credit_note_prefix)");
        DB::statement('alter table companies add constraint companies_payment_terms_check check (payment_terms_days between 0 and 60)');
    }

    public function down(): void
    {
        DB::statement('alter table companies drop constraint if exists companies_payment_terms_check');
        DB::statement('alter table companies drop constraint if exists companies_invoice_prefixes_check');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['invoice_prefix', 'credit_note_prefix', 'payment_terms_days', 'invoice_footer']);
        });
        Schema::dropIfExists('finance_settings');
        Schema::dropIfExists('billing_plans');
    }
};
