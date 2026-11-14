<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Funding modes a training is eligible to (référentiel « Mode de financement »).
     */
    public function up(): void
    {
        Schema::create('training_funding_modes', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('training_id')->constrained('trainings')->cascadeOnDelete();
            $table->foreignUuid('funding_mode_id')->constrained('reference_items');
            $table->primary(['training_id', 'funding_mode_id']);
            $table->index('funding_mode_id');
        });

        Rls::enable('training_funding_modes');
    }

    public function down(): void
    {
        Schema::dropIfExists('training_funding_modes');
    }
};
