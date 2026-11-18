<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invoices and credit notes (0.9, module Finance), amounts in integer
     * cents (HT; a credit note's lines and totals are negative):
     *
     *  - invoices: a draft (no number) prepared from « À facturer » or by
     *    hand from the dossier, then validated (number, frozen recipient,
     *    PDF, hash: Task 5) and sent. Recipient: the contractor of the
     *    dossier (funding_id null) or the funder of a funding paying
     *    directly (funding_id; no foreign key: a funding may be deleted,
     *    its invoices stay); recipient_key names it (« contractor » or the
     *    funding id) for « already invoiced » and the one open draft per
     *    recipient. cut_off: the cut-off of the computation (« 2026-09 »
     *    for a monthly plan, else a day);
     *  - invoice_lines: computed lines (component training / dedit /
     *    absences / mention, computed_cents = the amount proposed) and free
     *    lines (never counted as invoiced);
     *  - invoice_sequences: numbering per company, kind and Paris year;
     *  - invoice_journal: one row per validation, chained hashes (Task 5).
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies');
            $table->string('kind', 12);
            $table->string('number', 30)->nullable();
            $table->string('status', 10)->default('draft');
            $table->foreignUuid('dossier_id')->index()->constrained('dossiers');
            $table->string('recipient_type', 12);
            $table->uuid('recipient_id');
            $table->uuid('funding_id')->nullable();
            $table->string('recipient_key', 40)->storedAs("coalesce(funding_id::text, 'contractor')");
            $table->jsonb('recipient_snapshot')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->string('cut_off', 10)->nullable();
            $table->string('rhythm', 10);
            $table->string('basis', 10);
            $table->bigInteger('total_ht_cents')->default(0);
            $table->bigInteger('total_vat_cents')->default(0);
            $table->bigInteger('total_ttc_cents')->default(0);
            $table->text('vat_mention')->nullable();
            $table->uuid('corrected_invoice_id')->nullable();
            $table->text('notes')->nullable();
            $table->string('pdf_path')->nullable();
            $table->char('hash', 64)->nullable();
            $table->char('previous_hash', 64)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->string('email_status', 10)->nullable();
            $table->string('email_error_code', 40)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('validated_at')->nullable();
            $table->timestamps();
            $table->index(['dossier_id', 'recipient_key', 'status']);
            $table->unique(['company_id', 'number']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreign('corrected_invoice_id')->references('id')->on('invoices');
        });
        DB::statement("alter table invoices add constraint invoices_kind_check check (kind in ('invoice', 'credit_note'))");
        DB::statement("alter table invoices add constraint invoices_status_check check (status in ('draft', 'validated', 'sent'))");
        DB::statement("alter table invoices add constraint invoices_recipient_type_check check (recipient_type in ('organisation', 'person'))");
        DB::statement("alter table invoices add constraint invoices_number_check check ((status = 'draft') = (number is null))");
        DB::statement("alter table invoices add constraint invoices_correction_check check (corrected_invoice_id is null or kind = 'credit_note')");
        Rls::enable('invoices');

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->index()->constrained('invoices')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('kind', 10);
            // Computed lines: what they bill (« already invoiced » per component).
            $table->string('component', 10)->nullable();
            $table->text('label');
            $table->text('description')->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit', 20)->default('forfait');
            $table->bigInteger('unit_price_cents');
            $table->decimal('vat_rate', 4, 2)->default(0);
            $table->bigInteger('total_ht_cents');
            // The amount proposed by the computation (the gap shows a line changed by hand).
            $table->bigInteger('computed_cents')->nullable();
            $table->integer('planned_minutes')->nullable();
            $table->integer('realised_minutes')->nullable();
            // References to the dossier (no foreign key: Sessions may replace them, the invoice stays).
            $table->uuid('dossier_line_id')->nullable();
            $table->uuid('enrolment_id')->nullable();
            $table->uuid('funding_id')->nullable();
            $table->timestamps();
        });
        DB::statement("alter table invoice_lines add constraint invoice_lines_kind_check check (kind in ('computed', 'free'))");
        DB::statement("alter table invoice_lines add constraint invoice_lines_component_check check ((kind = 'free' and component is null) or (kind = 'computed' and component in ('training', 'dedit', 'absences', 'mention')))");
        Rls::enable('invoice_lines');

        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('company_id')->constrained('companies');
            $table->string('kind', 12);
            $table->smallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'company_id', 'kind', 'year']);
        });
        DB::statement("alter table invoice_sequences add constraint invoice_sequences_kind_check check (kind in ('invoice', 'credit_note'))");
        Rls::enable('invoice_sequences');

        Schema::create('invoice_journal', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->unique()->constrained('invoices');
            $table->string('number', 30);
            $table->string('kind', 12);
            $table->date('issued_on');
            $table->bigInteger('total_ht_cents');
            $table->bigInteger('total_vat_cents');
            $table->bigInteger('total_ttc_cents');
            $table->char('hash', 64);
            $table->char('previous_hash', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
        Rls::enable('invoice_journal');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_journal');
        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
