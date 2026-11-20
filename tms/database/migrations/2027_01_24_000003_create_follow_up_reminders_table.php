<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lot 1e: each due follow-up (the trainer's call after an enrolment,
     * the reactivation after a dossier) is processed once: created or
     * skipped. Ids only, no personal data.
     */
    public function up(): void
    {
        Schema::create('follow_up_reminders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->uuid('subject_id');
            $table->unsignedSmallInteger('months');
            $table->uuid('activity_id')->nullable();
            $table->string('outcome', 10);
            $table->string('reason', 60)->nullable();
            $table->timestampTz('processed_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'kind', 'subject_id', 'months']);
        });
        DB::statement("ALTER TABLE follow_up_reminders ADD CONSTRAINT follow_up_reminders_kind_check CHECK (kind IN ('trainer', 'reactivation'))");
        DB::statement("ALTER TABLE follow_up_reminders ADD CONSTRAINT follow_up_reminders_outcome_check CHECK (outcome IN ('created', 'skipped'))");
        Rls::enable('follow_up_reminders');
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_up_reminders');
    }
};
