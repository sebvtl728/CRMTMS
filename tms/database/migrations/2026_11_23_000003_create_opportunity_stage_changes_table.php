<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stage history of an opportunity (append-only): the first row has no
     * from_stage_id (creation).
     */
    public function up(): void
    {
        Schema::create('opportunity_stage_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->foreignUuid('from_stage_id')->nullable()->constrained('reference_items');
            $table->foreignUuid('to_stage_id')->constrained('reference_items');
            $table->foreignUuid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('changed_at', 6);

            $table->index(['opportunity_id', 'changed_at']);
            $table->index('to_stage_id');
        });

        Rls::enable('opportunity_stage_changes');
    }

    public function down(): void
    {
        Schema::dropIfExists('opportunity_stage_changes');
    }
};
