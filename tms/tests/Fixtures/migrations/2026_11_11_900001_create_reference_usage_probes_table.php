<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-only table standing for a business table that references a
 * reference item (see ReferenceUsage); loaded by tests/RefreshDatabaseAsOwner.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_usage_probes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('origin_id')->nullable();
            $table->timestamps();
        });

        Rls::enable('reference_usage_probes');
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_usage_probes');
    }
};
