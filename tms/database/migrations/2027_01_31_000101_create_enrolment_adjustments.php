<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The individual planning of a trainee (2026-11-24): the gaps of one
     * enrolment from the séances of its session - a séance or a whole module
     * exempted, own hours at a séance, a séance of another session added.
     * planned_hours_base keeps the enrolment's planned hours before its first
     * adjustment (null: none), its planned hours being computed from then on.
     */
    public function up(): void
    {
        Schema::create('enrolment_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('enrolment_id')->constrained('enrolments')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->foreignUuid('seance_id')->nullable()->constrained('seances');
            $table->foreignUuid('session_module_id')->nullable()->constrained('session_modules');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->unsignedSmallInteger('break_minutes')->nullable();
            $table->string('reason', 300)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('enrolment_id');
            $table->index('seance_id');
            $table->index('session_module_id');
        });
        DB::statement('alter table enrolment_adjustments add column duration_minutes integer generated always as '
            .'((extract(epoch from (ends_at - starts_at)) / 60)::integer - coalesce(break_minutes, 0)) stored');
        DB::statement("alter table enrolment_adjustments add constraint enrolment_adjustments_kind_check check (kind in ('exempt_seance', 'exempt_module', 'added_seance', 'own_hours'))");
        DB::statement('alter table enrolment_adjustments add constraint enrolment_adjustments_target_check check ('
            ."(kind = 'exempt_module' and session_module_id is not null and seance_id is null)"
            ." or (kind <> 'exempt_module' and seance_id is not null and session_module_id is null))");
        DB::statement('alter table enrolment_adjustments add constraint enrolment_adjustments_hours_check check ('
            ."(kind = 'own_hours' and starts_at is not null and ends_at is not null and break_minutes is not null)"
            ." or (kind in ('exempt_seance', 'exempt_module') and starts_at is null and ends_at is null and break_minutes is null)"
            ." or (kind = 'added_seance' and ((starts_at is null and ends_at is null and break_minutes is null)"
            .' or (starts_at is not null and ends_at is not null and break_minutes is not null))))');
        DB::statement('alter table enrolment_adjustments add constraint enrolment_adjustments_times_check check (ends_at is null or (ends_at > starts_at and duration_minutes > 0))');
        DB::statement('create unique index enrolment_adjustments_seance_unique on enrolment_adjustments (enrolment_id, seance_id) where seance_id is not null');
        DB::statement("create unique index enrolment_adjustments_module_unique on enrolment_adjustments (enrolment_id, session_module_id) where kind = 'exempt_module'");
        Rls::enable('enrolment_adjustments');

        Schema::table('enrolments', function (Blueprint $table) {
            $table->decimal('planned_hours_base', 6, 2)->nullable();
        });
        DB::statement('alter table enrolments add constraint enrolments_planned_base_check check (planned_hours_base is null or planned_hours_base >= 0)');
    }

    public function down(): void
    {
        DB::statement('alter table enrolments drop constraint if exists enrolments_planned_base_check');
        Schema::table('enrolments', function (Blueprint $table) {
            $table->dropColumn('planned_hours_base');
        });
        Schema::dropIfExists('enrolment_adjustments');
    }
};
