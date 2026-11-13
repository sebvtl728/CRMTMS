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
        // Saved import settings (columns, values, options), e.g. the future « Formevol » profile.
        Schema::create('import_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('target', 20);
            $table->jsonb('field_map')->default('{}');
            $table->jsonb('value_map')->default('{}');
            $table->jsonb('options')->default('{}');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'name']);
        });

        DB::statement("alter table import_profiles add constraint import_profiles_target_check check (target in ('organisations','people','affiliations','notes'))");

        Rls::enable('import_profiles');
    }

    public function down(): void
    {
        Schema::dropIfExists('import_profiles');
    }
};
