<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Realised hours (0.8.0), the base of the invoices on hours done (0.9):
     *
     *  - attendances: one per séance and enrolment; present = the séance's
     *    duration, partial 0 < m < duration, absent / excused 0 (whole
     *    minutes); needs_review once the séance changed its hours;
     *  - monthly_hours: the minutes of a trainee on an interval module for a
     *    Paris month (the 1st of the month), one row per trainee, module, month;
     *  - attendance_closures: a Paris month of a session closed (closed_at
     *    set) or reopened with a reason (the last one kept), one row per
     *    session and month.
     *
     * Rows are never cascaded from their séance or enrolment (neither is
     * hard-deleted): what was realised stays.
     */
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('seance_id')->constrained('seances');
            $table->foreignUuid('enrolment_id')->constrained('enrolments');
            $table->string('status', 20);
            $table->integer('minutes');
            $table->string('reason', 300)->nullable();
            $table->boolean('needs_review')->default(false);
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['seance_id', 'enrolment_id']);
            $table->index('enrolment_id');
        });
        DB::statement("alter table attendances add constraint attendances_status_check check (status in ('present', 'absent', 'partial', 'excused'))");
        DB::statement('alter table attendances add constraint attendances_minutes_check check (minutes >= 0)');
        DB::statement("alter table attendances add constraint attendances_absent_check check (status in ('present', 'partial') or minutes = 0)");
        Rls::enable('attendances');

        Schema::create('monthly_hours', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('enrolment_id')->constrained('enrolments');
            $table->foreignUuid('session_module_id')->constrained('session_modules');
            $table->date('month');
            $table->integer('minutes');
            $table->foreignUuid('trainer_id')->nullable()->constrained('trainers');
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['enrolment_id', 'session_module_id', 'month']);
            $table->index(['session_module_id', 'month']);
        });
        DB::statement('alter table monthly_hours add constraint monthly_hours_minutes_check check (minutes >= 0)');
        DB::statement('alter table monthly_hours add constraint monthly_hours_month_check check (extract(day from month) = 1)');
        Rls::enable('monthly_hours');

        Schema::create('attendance_closures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('training_session_id')->constrained('training_sessions');
            $table->date('month');
            $table->timestampTz('closed_at')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reopened_at')->nullable();
            $table->foreignUuid('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reopen_reason', 300)->nullable();
            $table->timestamps();

            $table->unique(['training_session_id', 'month']);
        });
        DB::statement('alter table attendance_closures add constraint attendance_closures_month_check check (extract(day from month) = 1)');
        Rls::enable('attendance_closures');
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_closures');
        Schema::dropIfExists('monthly_hours');
        Schema::dropIfExists('attendances');
    }
};
