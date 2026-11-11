<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-only business table used to exercise tenant isolation (loaded by
 * tests/RefreshDatabaseAsOwner.php, never by production migrations).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('probe_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('label');
            $table->timestamps();
        });

        Rls::enable('probe_items');
    }

    public function down(): void
    {
        Schema::dropIfExists('probe_items');
    }
};
