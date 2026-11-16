<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trainers (0.8.0): a person of the CRM declared trainer, once per
     * organisme among the trainers out of the trash (a trashed one is
     * restored when the person is declared again), salaried or freelance,
     * optionally linked to one user account of the organisme (one trainer
     * per account). Unavailabilities: instants (timestamptz), end after start.
     */
    public function up(): void
    {
        Schema::create('trainers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('person_id')->constrained('people');
            $table->string('status', 10);
            $table->boolean('active')->default(true);
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'user_id']);
            $table->index('person_id');
        });
        DB::statement("alter table trainers add constraint trainers_status_check check (status in ('employee', 'freelance'))");
        DB::statement('create unique index trainers_person_unique on trainers (tenant_id, person_id) where deleted_at is null');
        Rls::enable('trainers');

        Schema::create('trainer_unavailabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('trainer_id')->constrained('trainers')->cascadeOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('reason', 200)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['trainer_id', 'starts_at']);
        });
        DB::statement('alter table trainer_unavailabilities add constraint trainer_unavailabilities_dates_check check (ends_at > starts_at)');
        Rls::enable('trainer_unavailabilities');
    }

    public function down(): void
    {
        Schema::dropIfExists('trainer_unavailabilities');
        Schema::dropIfExists('trainers');
    }
};
