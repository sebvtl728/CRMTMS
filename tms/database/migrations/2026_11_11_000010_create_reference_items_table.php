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
        Schema::create('reference_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('list');
            $table->string('code');
            $table->string('label');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->jsonb('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Not partial: the code of a deleted item stays reserved.
            $table->unique(['tenant_id', 'list', 'code']);
            $table->index(['tenant_id', 'list', 'position']);
        });

        DB::statement("alter table reference_items add constraint reference_items_code_check check (code ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");

        Rls::enable('reference_items');
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_items');
    }
};
