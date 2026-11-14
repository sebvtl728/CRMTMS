<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trainings of the catalogue an opportunity is about (one line per
     * training, with a quantity: trainees, sessions...). A training in use
     * cannot be deleted (TrainingUsage), so the foreign key restricts.
     */
    public function up(): void
    {
        Schema::create('opportunity_trainings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->foreignUuid('training_id')->constrained('trainings')->restrictOnDelete();
            $table->decimal('quantity', 8, 2)->default(1);
            $table->smallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['opportunity_id', 'training_id']);
            $table->index('training_id');
        });

        DB::statement('alter table opportunity_trainings add constraint opportunity_trainings_quantity_check check (quantity > 0)');

        Rls::enable('opportunity_trainings');
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_trainings');
    }
};
