<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Training sessions (module Sessions, lot 2a), named training_sessions so
     * as not to clash with Laravel's « sessions » table. A session runs one
     * training of the catalogue: intra (one entreprise, no places) or inter
     * (several dossiers, a number of places). The DAS is the training's,
     * copied at each save (visibility « Ses DAS »). States: SessionStatus;
     * « en cours » / « terminée » are computed from the dates when read.
     *
     * session_sequences: last number given per organisme and Paris year
     * (SessionNumbering, S-<année>-<0001>), incremented by an atomic upsert
     * in the creating transaction.
     */
    public function up(): void
    {
        Schema::create('training_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('number', 20);
            $table->foreignUuid('training_id')->constrained('trainings');
            $table->foreignUuid('das_id')->constrained('reference_items');
            $table->string('kind', 10);
            $table->foreignUuid('organisation_id')->nullable()->constrained('organisations');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('planned_hours', 6, 2)->nullable();
            $table->string('location_kind', 10);
            $table->string('location_text', 500)->nullable();
            $table->integer('capacity')->nullable();
            $table->foreignUuid('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('planned');
            $table->foreignUuid('cancel_reason_id')->nullable()->constrained('reference_items');
            $table->date('cancelled_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'number']);
            $table->index('training_id');
            $table->index('organisation_id');
            $table->index('das_id');
            $table->index('responsible_user_id');
            $table->index('created_by');
            $table->index(['tenant_id', 'starts_on']);
        });

        DB::statement("alter table training_sessions add constraint training_sessions_kind_check check (kind in ('intra', 'inter'))");
        DB::statement("alter table training_sessions add constraint training_sessions_status_check check (status in ('planned', 'confirmed', 'running', 'done', 'cancelled'))");
        DB::statement("alter table training_sessions add constraint training_sessions_location_check check (location_kind in ('address', 'client', 'remote'))");
        DB::statement('alter table training_sessions add constraint training_sessions_dates_check check (ends_on >= starts_on)');
        DB::statement('alter table training_sessions add constraint training_sessions_hours_check check (planned_hours is null or planned_hours >= 0)');
        // Intra: one entreprise, no places; inter: places, no entreprise.
        DB::statement("alter table training_sessions add constraint training_sessions_intra_check check (
            (kind = 'intra' and organisation_id is not null and capacity is null)
            or (kind = 'inter' and organisation_id is null and capacity is not null and capacity >= 1))");
        DB::statement("alter table training_sessions add constraint training_sessions_cancel_check check (
            (status = 'cancelled') = (cancel_reason_id is not null and cancelled_on is not null))");

        Rls::enable('training_sessions');

        Schema::create('session_sequences', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->smallInteger('year');
            $table->integer('last_number');
            $table->timestamps();

            $table->primary(['tenant_id', 'year']);
        });

        DB::statement('alter table session_sequences add constraint session_sequences_last_number_check check (last_number > 0)');
        DB::statement('alter table session_sequences add constraint session_sequences_year_check check (year between 2000 and 2999)');

        Rls::enable('session_sequences');
    }

    public function down(): void
    {
        Schema::dropIfExists('session_sequences');
        Schema::dropIfExists('training_sessions');
    }
};
