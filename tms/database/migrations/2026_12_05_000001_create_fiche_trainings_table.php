<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * « Formations d'intérêt » of an entreprise or a person (exactly one of
     * the two), one row per training. A training in use cannot be deleted
     * (TrainingUsage), so its foreign key restricts; a fiche is soft deleted,
     * its rows go only with it.
     */
    public function up(): void
    {
        Schema::create('fiche_trainings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('organisation_id')->nullable()->constrained('organisations')->cascadeOnDelete();
            $table->foreignUuid('person_id')->nullable()->constrained('people')->cascadeOnDelete();
            $table->foreignUuid('training_id')->constrained('trainings')->restrictOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organisation_id', 'training_id']);
            $table->unique(['person_id', 'training_id']);
            $table->index('training_id');
        });

        DB::statement('alter table fiche_trainings add constraint fiche_trainings_one_fiche_check check (num_nonnulls(organisation_id, person_id) = 1)');

        Rls::enable('fiche_trainings');
    }

    public function down(): void
    {
        Schema::dropIfExists('fiche_trainings');
    }
};
