<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The organisme's previous column choices at the « Colonnes » step:
        // a normalised header (Text::key) => a field, or null for « Ignorer ».
        // Headers and field codes only, never a cell value.
        Schema::create('import_header_choices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('target', 30);
            $table->string('header_key', 200);
            $table->string('field', 60)->nullable();
            $table->integer('uses')->default(1);
            $table->timestampTz('last_used_at');

            $table->unique(['tenant_id', 'target', 'header_key']);
        });

        Rls::enable('import_header_choices');
    }

    public function down(): void
    {
        Schema::dropIfExists('import_header_choices');
    }
};
