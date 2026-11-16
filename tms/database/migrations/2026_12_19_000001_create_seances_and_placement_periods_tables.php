<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Séances of the session modules dated « séances » (0.8.0): a Paris day,
     * wall-clock start and end (time without zone: 9 h–17 h stays 9 h–17 h
     * across a change of hour), a break and the duration in whole minutes
     * computed by the database. One séance per module, day and start among
     * those out of the trash (the generator never creates a duplicate).
     *
     * Placement periods (stages en entreprise) of a session: common dates,
     * and per trainee (enrolment) a host entreprise of the CRM and own dates
     * replacing the common ones. Never counted in hours.
     */
    public function up(): void
    {
        Schema::create('seances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('training_session_id')->constrained('training_sessions')->cascadeOnDelete();
            $table->foreignUuid('session_module_id')->constrained('session_modules')->cascadeOnDelete();
            $table->date('date');
            $table->time('starts_at', 0);
            $table->time('ends_at', 0);
            $table->integer('break_minutes')->default(0);
            $table->foreignUuid('trainer_id')->nullable()->constrained('trainers');
            $table->foreignUuid('room_id')->nullable()->constrained('rooms');
            $table->string('place_text', 300)->nullable();
            $table->string('status', 20)->default('planned');
            $table->string('cancel_reason', 300)->nullable();
            $table->string('note', 1000)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'date']);
            $table->index(['training_session_id', 'date']);
            $table->index(['trainer_id', 'date']);
            $table->index(['room_id', 'date']);
        });
        DB::statement('alter table seances add column duration_minutes integer generated always as '
            .'((extract(epoch from (ends_at - starts_at)) / 60)::integer - break_minutes) stored');
        DB::statement('alter table seances add constraint seances_times_check check (ends_at > starts_at)');
        DB::statement('alter table seances add constraint seances_break_check check (break_minutes >= 0)');
        DB::statement('alter table seances add constraint seances_duration_check check (duration_minutes > 0)');
        DB::statement("alter table seances add constraint seances_status_check check (status in ('planned', 'cancelled'))");
        DB::statement("alter table seances add constraint seances_cancel_check check (status = 'cancelled' or cancel_reason is null)");
        DB::statement('create unique index seances_module_slot_unique on seances (session_module_id, date, starts_at) where deleted_at is null');
        Rls::enable('seances');

        Schema::create('placement_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('training_session_id')->constrained('training_sessions')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('label', 120);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['training_session_id', 'starts_on']);
        });
        DB::statement('alter table placement_periods add constraint placement_periods_dates_check check (ends_on >= starts_on)');
        Rls::enable('placement_periods');

        Schema::create('placement_period_hosts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('placement_period_id')->constrained('placement_periods')->cascadeOnDelete();
            $table->foreignUuid('enrolment_id')->constrained('enrolments')->cascadeOnDelete();
            $table->foreignUuid('organisation_id')->nullable()->constrained('organisations');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['placement_period_id', 'enrolment_id']);
            $table->index('enrolment_id');
            $table->index('organisation_id');
        });
        DB::statement('alter table placement_period_hosts add constraint placement_period_hosts_dates_check check ('
            .'(starts_on is null and ends_on is null) or (starts_on is not null and ends_on is not null and ends_on >= starts_on))');
        Rls::enable('placement_period_hosts');
    }

    public function down(): void
    {
        Schema::dropIfExists('placement_period_hosts');
        Schema::dropIfExists('placement_periods');
        Schema::dropIfExists('seances');
    }
};
