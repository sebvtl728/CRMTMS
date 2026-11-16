<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enrolments (module Sessions, lot 2a): a trainee (person of the CRM) on
     * a training session, for a training line of a dossier. States:
     * EnrolmentStatus (fixed in the code). A trainee is enrolled once on a
     * session, cancelled enrolments aside (partial unique index). An inter
     * session that is full puts the next enrolment on the waiting list
     * (waitlist_position: order of arrival, manual promotion).
     *
     * price_share: the share of a line priced per trainee (PriceShares,
     * integer cents, the remainder to the first enrolments), null for a line
     * priced for the group, a waitlisted or a cancelled enrolment. An abandon
     * keeps its share, its leaving day and a reason (« Motifs d'abandon »).
     * adaptation_needs and notes are personal data (journaled without value).
     */
    public function up(): void
    {
        Schema::create('enrolments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('person_id')->constrained('people');
            $table->foreignUuid('training_session_id')->constrained('training_sessions');
            $table->foreignUuid('dossier_id')->constrained('dossiers');
            $table->foreignUuid('dossier_line_id')->constrained('dossier_lines');
            $table->string('status', 20)->default('enrolled');
            $table->integer('waitlist_position')->nullable();
            $table->date('entered_on')->nullable();
            $table->date('left_on')->nullable();
            $table->foreignUuid('abandon_reason_id')->nullable()->constrained('reference_items');
            $table->decimal('planned_hours', 6, 2)->nullable();
            $table->decimal('price_share', 12, 2)->nullable();
            $table->foreignUuid('bpf_category_id')->nullable()->constrained('reference_items');
            $table->text('adaptation_needs')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('person_id');
            $table->index('training_session_id');
            $table->index('dossier_id');
            $table->index('dossier_line_id');
            $table->index('abandon_reason_id');
            $table->index('bpf_category_id');
        });

        DB::statement("alter table enrolments add constraint enrolments_status_check check (status in ('enrolled', 'waitlisted', 'confirmed', 'abandoned', 'cancelled'))");
        DB::statement("alter table enrolments add constraint enrolments_waitlist_check check ((status = 'waitlisted') = (waitlist_position is not null))");
        // An abandon has its leaving day and its reason (kept if it is cancelled afterwards).
        DB::statement("alter table enrolments add constraint enrolments_abandon_check check (
            status <> 'abandoned' or (abandon_reason_id is not null and left_on is not null))");
        DB::statement('alter table enrolments add constraint enrolments_dates_check check (left_on is null or entered_on is null or left_on >= entered_on)');
        DB::statement('alter table enrolments add constraint enrolments_amounts_check check ((price_share is null or price_share >= 0) and (planned_hours is null or planned_hours >= 0))');
        // A trainee once per session, cancelled enrolments aside (plain columns: usable under RLS).
        DB::statement("create unique index enrolments_session_person_unique on enrolments (training_session_id, person_id) where status <> 'cancelled'");

        Rls::enable('enrolments');
    }

    public function down(): void
    {
        Schema::dropIfExists('enrolments');
    }
};
