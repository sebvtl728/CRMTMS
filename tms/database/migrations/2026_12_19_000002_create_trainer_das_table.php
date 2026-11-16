<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DAS of a trainer (0.8.0): a trainer is attached to any number of DAS
     * (« DAS » reference list of the organisme), once each. No DAS = visible
     * to every manager of the trainers. The column is declared with
     * ReferenceUsage (a DAS value used here is never deleted).
     */
    public function up(): void
    {
        Schema::create('trainer_das', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('trainer_id')->constrained('trainers')->cascadeOnDelete();
            $table->foreignUuid('das_id')->constrained('reference_items');
            $table->timestamps();

            $table->unique(['trainer_id', 'das_id']);
            $table->index('das_id');
        });
        Rls::enable('trainer_das');
    }

    public function down(): void
    {
        Schema::dropIfExists('trainer_das');
    }
};
