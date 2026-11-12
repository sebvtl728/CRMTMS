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
        Schema::create('connector_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('connector_key', 50);
            // null = the whole organisme; a company overrides it for that entity.
            $table->foreignUuid('company_id')->nullable()->constrained('companies');
            $table->boolean('enabled')->default(false);
            $table->jsonb('settings')->default('{}');
            // Laravel "encrypted:array" cast (APP_KEY): ciphertext, hence text.
            $table->text('secrets')->nullable();
            $table->timestamps();
        });

        // NULLS NOT DISTINCT: two organisme-wide configurations of the same connector are also a duplicate.
        DB::statement('create unique index connector_configs_unique_scope on connector_configs (tenant_id, connector_key, company_id) nulls not distinct');

        Rls::enable('connector_configs');
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_configs');
    }
};
