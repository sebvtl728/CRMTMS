<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module Admissions: candidatures of CRM people to a training (stages,
     * positioning, interview, decision, custom values), their numbering per
     * Paris year, their files, the alternance contract (one per candidature)
     * and the custom fields of the organisme.
     */
    public function up(): void
    {
        Schema::create('candidatures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('number', 20);
            $table->foreignUuid('person_id')->constrained('people');
            $table->string('status', 30)->default('identified');
            $table->foreignUuid('training_id')->nullable()->constrained('trainings');
            $table->string('rncp_code', 20)->nullable();
            $table->foreignUuid('target_session_id')->nullable()->constrained('training_sessions')->nullOnDelete();
            $table->foreignUuid('das_id')->constrained('reference_items');
            $table->foreignUuid('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('origin_id')->nullable()->constrained('reference_items');
            $table->foreignUuid('prescriber_organisation_id')->nullable()->constrained('organisations');
            $table->string('france_travail_id', 30)->nullable();
            $table->string('last_diploma', 255)->nullable();
            $table->jsonb('legal_representative')->nullable();
            $table->boolean('rqth')->default(false);
            $table->text('adaptation_needs')->nullable();
            $table->date('positioning_sent_on')->nullable();
            $table->date('positioning_received_on')->nullable();
            $table->string('positioning_result', 100)->nullable();
            $table->timestampTz('interview_at')->nullable();
            $table->foreignUuid('interviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('interview_report')->nullable();
            $table->foreignUuid('decision_reason_id')->nullable()->constrained('reference_items');
            $table->foreignUuid('dossier_id')->nullable()->constrained('dossiers');
            $table->jsonb('custom_values')->default('{}');
            $table->timestampTz('anonymised_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'person_id']);
            $table->index(['tenant_id', 'status']);
            $table->index('training_id');
            $table->index('das_id');
            $table->index('responsible_user_id');
            $table->index('created_by');
            $table->index('prescriber_organisation_id');
        });
        DB::statement('create unique index candidatures_dossier_unique on candidatures (tenant_id, dossier_id) where dossier_id is not null');
        DB::statement("alter table candidatures add constraint candidatures_status_check check (status in ('identified', 'positioning_sent', 'positioning_received', 'interview', 'admitted', 'rejected', 'withdrawn'))");
        Rls::enable('candidatures');

        Schema::create('candidature_sequences', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->integer('year');
            $table->integer('last_number');
            $table->timestamps();
            $table->primary(['tenant_id', 'year']);
        });
        Rls::enable('candidature_sequences');

        Schema::create('candidature_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('candidature_id')->constrained('candidatures')->cascadeOnDelete();
            $table->string('kind', 60);
            $table->string('name', 255);
            $table->unsignedBigInteger('size');
            $table->string('path', 500);
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['candidature_id', 'kind']);
        });
        Rls::enable('candidature_files');

        Schema::create('alternance_contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('candidature_id')->unique()->constrained('candidatures')->cascadeOnDelete();
            $table->string('contract_type', 30);
            $table->foreignUuid('employer_organisation_id')->constrained('organisations');
            $table->foreignUuid('tutor_person_id')->nullable()->constrained('people');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->foreignUuid('opco_organisation_id')->nullable()->constrained('organisations');
            $table->timestamps();
            $table->index('employer_organisation_id');
            $table->index('tutor_person_id');
            $table->index('opco_organisation_id');
        });
        DB::statement('alter table alternance_contracts add constraint alternance_contracts_dates_check check (ends_on >= starts_on)');
        DB::statement("alter table alternance_contracts add constraint alternance_contracts_type_check check (contract_type in ('apprenticeship', 'professionalisation'))");
        Rls::enable('alternance_contracts');

        Schema::create('custom_field_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('entity', 40)->default('candidature');
            $table->string('label', 100);
            $table->string('type', 20);
            $table->jsonb('options')->nullable();
            $table->boolean('required_for_admission')->default(false);
            $table->integer('position')->default(0);
            $table->timestampTz('hidden_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'entity', 'position']);
        });
        DB::statement("alter table custom_field_definitions add constraint custom_field_definitions_type_check check (type in ('text', 'textarea', 'number', 'date', 'boolean', 'choice', 'file'))");
        Rls::enable('custom_field_definitions');
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_definitions');
        Schema::dropIfExists('alternance_contracts');
        Schema::dropIfExists('candidature_files');
        Schema::dropIfExists('candidature_sequences');
        Schema::dropIfExists('candidatures');
    }
};
