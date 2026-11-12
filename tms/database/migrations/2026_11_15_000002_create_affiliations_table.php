<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignUuid('organisation_id')->constrained('organisations')->cascadeOnDelete();
            $table->string('job_title')->nullable();
            $table->foreignUuid('decision_role_id')->nullable()->constrained('reference_items');
            $table->string('work_email')->nullable();
            $table->string('work_phone', 30)->nullable();
            $table->boolean('is_referent')->default(false);
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('person_id');
            $table->index('organisation_id');
        });

        // One active affiliation per person and entreprise.
        DB::statement('create unique index affiliations_active_unique on affiliations (tenant_id, person_id, organisation_id) where ended_on is null and deleted_at is null');

        Rls::enable('affiliations');
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliations');
    }
};
