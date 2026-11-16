<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fundings of a dossier (module Sessions, lot 2a): one per funder, an
     * entreprise or a person of the CRM (exactly one of the two columns, as
     * the contractant of a dossier), a type (« Types de financeur »,
     * reference list), an amount HT, a state (FundingStatus, fixed in the
     * code), the agreement number and day. It may target a line of the
     * dossier or an enrolment of it, never both (FundingWriter checks they
     * belong to the dossier). The targets are never deleted under a funding
     * (DossierWriter refuses, the foreign keys restrict).
     */
    public function up(): void
    {
        Schema::create('fundings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('dossier_id')->constrained('dossiers');
            $table->foreignUuid('funder_organisation_id')->nullable()->constrained('organisations');
            $table->foreignUuid('funder_person_id')->nullable()->constrained('people');
            $table->foreignUuid('funding_type_id')->constrained('reference_items');
            $table->foreignUuid('dossier_line_id')->nullable()->constrained('dossier_lines');
            $table->foreignUuid('enrolment_id')->nullable()->constrained('enrolments');
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('to_request');
            $table->string('agreement_number', 100)->nullable();
            $table->date('agreed_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('dossier_id');
            $table->index('funder_organisation_id');
            $table->index('funder_person_id');
            $table->index('funding_type_id');
            $table->index('dossier_line_id');
            $table->index('enrolment_id');
        });

        DB::statement('alter table fundings add constraint fundings_funder_check check ((funder_organisation_id is null) <> (funder_person_id is null))');
        DB::statement('alter table fundings add constraint fundings_target_check check (dossier_line_id is null or enrolment_id is null)');
        DB::statement('alter table fundings add constraint fundings_amount_check check (amount >= 0)');
        DB::statement("alter table fundings add constraint fundings_status_check check (status in ('to_request', 'requested', 'agreed', 'refused'))");

        Rls::enable('fundings');
    }

    public function down(): void
    {
        Schema::dropIfExists('fundings');
    }
};
