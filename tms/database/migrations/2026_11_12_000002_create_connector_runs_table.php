<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connector_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('connector_config_id')->index()->constrained('connector_configs')->cascadeOnDelete();
            $table->string('direction', 3);
            $table->string('status', 10)->default('pending');
            $table->text('summary')->nullable();
            $table->text('error')->nullable();
            $table->string('payload_ref')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'started_at']);
        });

        DB::statement("alter table connector_runs add constraint connector_runs_direction_check check (direction in ('in','out'))");
        DB::statement("alter table connector_runs add constraint connector_runs_status_check check (status in ('pending','success','failed'))");

        Rls::enable('connector_runs');
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_runs');
    }
};
