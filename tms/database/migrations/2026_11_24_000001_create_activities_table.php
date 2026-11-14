<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A commercial action (call, meeting, e-mail, reminder...) on an
     * entreprise, a person and/or an opportunity (at least one). To do while
     * done_at is null (due_at then required); a done action is a contact
     * (last_contact_at). happened_at orders the « Historique ».
     */
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('type_id')->constrained('reference_items');
            $table->string('subject', 200);
            $table->text('report')->nullable();
            $table->foreignUuid('organisation_id')->nullable()->constrained('organisations')->cascadeOnDelete();
            $table->foreignUuid('person_id')->nullable()->constrained('people')->cascadeOnDelete();
            $table->foreignUuid('opportunity_id')->nullable()->constrained('opportunities')->cascadeOnDelete();
            $table->timestampTz('due_at', 6)->nullable();
            $table->timestampTz('done_at', 6)->nullable();
            $table->smallInteger('duration_minutes')->nullable();
            $table->foreignUuid('result_id')->nullable()->constrained('reference_items');
            $table->foreignUuid('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('location', 255)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz(6);
            $table->softDeletesTz('deleted_at', 6);

            $table->index('organisation_id');
            $table->index('person_id');
            $table->index('opportunity_id');
        });

        DB::statement('alter table activities add constraint activities_subject_check check (organisation_id is not null or person_id is not null or opportunity_id is not null)');
        DB::statement('alter table activities add constraint activities_date_check check (done_at is not null or due_at is not null)');
        DB::statement('alter table activities add constraint activities_duration_check check (duration_minutes is null or duration_minutes between 1 and 1440)');
        DB::statement('alter table activities add column happened_at timestamptz(6) generated always as (coalesce(done_at, due_at)) stored');
        // « À faire » of a responsible (lot 1c, Task 6), and the timeline order.
        DB::statement('create index activities_todo_index on activities (tenant_id, responsible_user_id, due_at) where done_at is null and deleted_at is null');
        DB::statement('create index activities_happened_at_index on activities (tenant_id, happened_at)');

        Rls::enable('activities');
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
