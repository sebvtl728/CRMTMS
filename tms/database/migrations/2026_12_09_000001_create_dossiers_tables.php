<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dossiers de formation (module Sessions, lot 2a): a contractant
     * (entreprise or particulier, one of the two account columns), a company
     * issuing it, a principal DAS (required: « Ses DAS » visibility), the
     * accepted quote it comes from (at most one dossier per quote), its
     * states (DossierStatus, fixed in the code) and the dates of the manual
     * steps; abandon_mode / abandon_dedit_percent override the organisme's
     * abandon rule (both null: the organisme's rule).
     *
     * dossier_lines: one per training (kind training, training_id required,
     * pricing per trainee or for the group) or per fee (kind fee: travel,
     * meals…, no training, no pricing mode). Amounts numeric(12,2), computed
     * in integer cents (DossierLines). The price of a dossier is the sum of
     * its lines HT.
     *
     * dossier_sequences: last number given per organisme and Paris year
     * (DossierNumbering, D-<année>-<0001>).
     */
    public function up(): void
    {
        Schema::create('dossiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('number', 20);
            $table->foreignUuid('company_id')->constrained('companies');
            $table->foreignUuid('account_organisation_id')->nullable()->constrained('organisations');
            $table->foreignUuid('account_person_id')->nullable()->constrained('people');
            $table->foreignUuid('contact_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignUuid('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('quote_id')->nullable()->constrained('quotes');
            $table->foreignUuid('opportunity_id')->nullable()->constrained('opportunities');
            $table->foreignUuid('das_id')->constrained('reference_items');
            $table->string('status', 20)->default('created');
            $table->date('convention_sent_on')->nullable();
            $table->date('convention_signed_on')->nullable();
            $table->date('convocations_sent_on')->nullable();
            $table->foreignUuid('cancelled_reason_id')->nullable()->constrained('reference_items');
            $table->date('cancelled_on')->nullable();
            $table->string('abandon_mode', 20)->nullable();
            $table->smallInteger('abandon_dedit_percent')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'number']);
            $table->unique('quote_id');
            $table->index('company_id');
            $table->index('account_organisation_id');
            $table->index('account_person_id');
            $table->index('contact_person_id');
            $table->index('opportunity_id');
            $table->index('das_id');
            $table->index('responsible_user_id');
            $table->index('created_by');
            $table->index(['tenant_id', 'created_at']);
        });

        DB::statement('alter table dossiers add constraint dossiers_account_check check ((account_organisation_id is null) <> (account_person_id is null))');
        DB::statement("alter table dossiers add constraint dossiers_status_check check (status in ('created', 'convention_sent', 'convention_signed', 'convocations_sent', 'running', 'done', 'cancelled', 'invoiced', 'settled'))");
        DB::statement("alter table dossiers add constraint dossiers_cancel_check check (
            (status = 'cancelled') = (cancelled_reason_id is not null and cancelled_on is not null))");
        DB::statement("alter table dossiers add constraint dossiers_abandon_check check (
            (abandon_mode is null and abandon_dedit_percent is null)
            or (abandon_mode = 'prorata' and abandon_dedit_percent is null)
            or (abandon_mode = 'prorata_dedit' and abandon_dedit_percent between 1 and 100))");

        Rls::enable('dossiers');

        Schema::create('dossier_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('dossier_id')->constrained('dossiers')->cascadeOnDelete();
            $table->smallInteger('position');
            $table->string('kind', 10);
            $table->foreignUuid('training_id')->nullable()->constrained('trainings');
            $table->foreignUuid('das_id')->nullable()->constrained('reference_items');
            $table->string('label', 255);
            $table->decimal('quantity', 8, 2);
            $table->string('unit', 20);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('total_ht', 12, 2);
            $table->string('pricing_mode', 20)->nullable();
            $table->timestamps();

            $table->index(['dossier_id', 'position']);
            $table->index('training_id');
            $table->index('das_id');
        });

        DB::statement("alter table dossier_lines add constraint dossier_lines_kind_check check (
            (kind = 'training' and training_id is not null and pricing_mode in ('per_trainee', 'group'))
            or (kind = 'fee' and training_id is null and pricing_mode is null))");
        DB::statement('alter table dossier_lines add constraint dossier_lines_amounts_check check (
            quantity > 0 and unit_price >= 0 and total_ht >= 0 and discount_percent between 0 and 100)');

        Rls::enable('dossier_lines');

        Schema::create('dossier_sequences', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->smallInteger('year');
            $table->integer('last_number');
            $table->timestamps();

            $table->primary(['tenant_id', 'year']);
        });

        DB::statement('alter table dossier_sequences add constraint dossier_sequences_last_number_check check (last_number > 0)');
        DB::statement('alter table dossier_sequences add constraint dossier_sequences_year_check check (year between 2000 and 2999)');

        Rls::enable('dossier_sequences');
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_sequences');
        Schema::dropIfExists('dossier_lines');
        Schema::dropIfExists('dossiers');
    }
};
