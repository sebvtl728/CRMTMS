<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Modules of a training session (0.8.0): copied from the training's
     * modules at creation, then changed on the session only. Planned hours
     * in whole minutes (null when the catalogue gave none); the session's
     * planned_hours is their sum. dating_mode: « interval » (start, end,
     * hours; no séances) or « seances » (dated séances, task 3). An interval
     * module's dates stay within the session's when saved; a session whose
     * dates move later only flags the modules left outside.
     */
    public function up(): void
    {
        Schema::create('session_modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('training_session_id')->constrained('training_sessions')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('title', 200);
            $table->integer('planned_minutes')->nullable();
            $table->foreignUuid('modality_id')->nullable()->constrained('reference_items');
            $table->string('dating_mode', 10)->default('interval');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['training_session_id', 'position']);
            $table->index('modality_id');
        });

        DB::statement('alter table session_modules add constraint session_modules_minutes_check check (planned_minutes is null or planned_minutes > 0)');
        DB::statement("alter table session_modules add constraint session_modules_mode_check check (dating_mode in ('interval', 'seances'))");
        DB::statement('alter table session_modules add constraint session_modules_dates_check check (ends_on is null or starts_on is null or ends_on >= starts_on)');

        Rls::enable('session_modules');
    }

    public function down(): void
    {
        Schema::dropIfExists('session_modules');
    }
};
