<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * spatie/laravel-permission tables, adapted to the platform:
 *  - UUID keys (users have uuid ids; roles and permissions follow the rule);
 *  - teams = tenant: tenant_id is the team key (config/permission.php);
 *  - every table holding tenant data is under RLS with tenant_id uuid not null.
 *    Only `permissions` (the global catalogue, no tenant data) is whitelisted.
 *  - A role can only be attached inside its own tenant: composite foreign keys
 *    (role_id, tenant_id) -> roles (id, tenant_id).
 */
return new class extends Migration
{
    private const TENANT_DEFAULT = "nullif(current_setting('app.tenant_id', true), '')::uuid";

    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();

            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();

            $table->unique(['tenant_id', 'name', 'guard_name']);
            $table->unique(['id', 'tenant_id']); // target of the composite foreign keys
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->foreignUuid('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->string('model_type');
            $table->uuid('model_id');
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();

            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
            $table->primary(['tenant_id', 'permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->uuid('role_id');
            $table->string('model_type');
            $table->uuid('model_id');
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();

            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->primary(['tenant_id', 'role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
            $table->foreign(['role_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('roles')->cascadeOnDelete();
        });

        // spatie inserts only (permission_id, role_id): tenant_id is filled from
        // the session setting written by TenantContext (same pattern as
        // password_reset_tokens). Without a tenant context the insert fails.
        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->foreignUuid('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->uuid('role_id');
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();

            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
            $table->foreign(['role_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('roles')->cascadeOnDelete();
        });

        DB::statement('alter table role_has_permissions alter column tenant_id set default '.self::TENANT_DEFAULT);

        foreach (['roles', 'model_has_permissions', 'model_has_roles', 'role_has_permissions'] as $table) {
            Rls::enable($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
