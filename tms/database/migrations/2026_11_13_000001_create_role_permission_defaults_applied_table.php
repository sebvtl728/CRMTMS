<?php

use App\Modules\Core\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers, per tenant, which default permissions have already been offered
 * to each role (PermissionSync): a default permission is granted once, and
 * never again after an administrator removed it.
 *
 * Existing tenants received the lot 1a matrix when their roles were created:
 * it is recorded as offered (literal copy, as it was then), so the first sync
 * only adds what is new (crm.*) and never restores a lot 1a permission an
 * administrator had removed.
 */
return new class extends Migration
{
    private const LOT_1A_MATRIX = [
        'admin_organisme' => ['users.manage', 'companies.manage', 'references.manage', 'connectors.manage', 'audit.view', 'settings.manage', 'exports.run'],
        'direction' => ['audit.view', 'exports.run'],
    ];

    public function up(): void
    {
        Schema::create('role_permission_defaults_applied', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('role', 64);
            $table->string('permission');
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['tenant_id', 'role', 'permission']);
        });

        // Before RLS: tenants is a platform table, readable without context.
        foreach (self::LOT_1A_MATRIX as $role => $permissions) {
            foreach ($permissions as $permission) {
                DB::statement(
                    'insert into role_permission_defaults_applied (tenant_id, role, permission) select id, ?, ? from tenants',
                    [$role, $permission],
                );
            }
        }

        Rls::enable('role_permission_defaults_applied');
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permission_defaults_applied');
    }
};
