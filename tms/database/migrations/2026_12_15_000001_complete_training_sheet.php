<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The training sheet « formation type » (0.7): presentation, ordered
     * objectives (jsonb list of texts), methods, evaluation, sanction,
     * accessibility, access terms and results, plus the modules of the
     * training (title, hours, modality = référentiel « training_modality »).
     */
    public function up(): void
    {
        Schema::table('trainings', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->jsonb('objectives')->nullable();
            $table->text('methods')->nullable();
            $table->text('evaluation')->nullable();
            $table->text('sanction')->nullable();
            $table->text('accessibility')->nullable();
            $table->text('access_terms')->nullable();
            $table->text('results')->nullable();
        });

        Schema::create('training_modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('training_id')->constrained('trainings')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('title', 200);
            $table->decimal('duration_hours', 6, 2)->nullable();
            $table->foreignUuid('modality_id')->nullable()->constrained('reference_items');
            $table->text('content')->nullable();
            $table->timestamps();

            $table->index(['training_id', 'position']);
            $table->index('modality_id');
        });

        Rls::enable('training_modules');
    }

    public function down(): void
    {
        Schema::dropIfExists('training_modules');
        Schema::table('trainings', function (Blueprint $table) {
            $table->dropColumn(['description', 'objectives', 'methods', 'evaluation', 'sanction', 'accessibility', 'access_terms', 'results']);
        });
    }
};
