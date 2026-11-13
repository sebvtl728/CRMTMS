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
        Schema::create('saved_filters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('resource', 20);
            $table->string('name', 100);
            // Keys validated against the list of the resource (no arbitrary JSON).
            $table->jsonb('filters')->default('{}');
            $table->jsonb('columns')->nullable();
            $table->boolean('is_shared')->default(false);
            $table->timestampsTz();

            $table->index(['tenant_id', 'resource', 'is_shared']);
            $table->index(['user_id', 'resource']);
        });

        DB::statement("alter table saved_filters add constraint saved_filters_resource_check check (resource in ('organisations', 'people'))");

        Rls::enable('saved_filters');
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_filters');
    }
};
