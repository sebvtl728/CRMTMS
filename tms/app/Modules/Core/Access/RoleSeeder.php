<?php

namespace App\Modules\Core\Access;

use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;

/**
 * Creates the roles of a tenant with the default permission matrix
 * (called by tms:install and tms:tenant:create).
 *
 * Idempotent: missing permissions and roles are created; an existing role
 * keeps its permissions (a tenant's customised matrix is never overwritten).
 */
final class RoleSeeder
{
    public const GUARD = 'web';

    public static function seedFor(Tenant $tenant): void
    {
        foreach (Permissions::all() as $permission) {
            PermissionModel::findOrCreate($permission, self::GUARD);
        }

        app(TenantContext::class)->run($tenant, function (): void {
            foreach (Role::cases() as $role) {
                if (RoleModel::query()->where('name', $role->value)->where('guard_name', self::GUARD)->exists()) {
                    continue;
                }

                RoleModel::create(['name' => $role->value, 'guard_name' => self::GUARD])
                    ->syncPermissions(Permissions::defaultsFor($role));
            }
        });
    }
}
