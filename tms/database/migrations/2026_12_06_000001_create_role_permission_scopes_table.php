<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scope (own / das / all) of a role for a permission that has one. A scoped
 * permission without a row here means « all » (Scopes). Query-builder only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permission_scopes', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('role_id');
            $table->string('permission', 100);
            $table->string('scope', 10);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['role_id', 'permission']);
            $table->foreign(['role_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('roles')->cascadeOnDelete();
        });

        DB::statement("alter table role_permission_scopes add constraint role_permission_scopes_scope_check check (scope in ('own', 'das', 'all'))");

        Rls::enable('role_permission_scopes');
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permission_scopes');
    }
};
