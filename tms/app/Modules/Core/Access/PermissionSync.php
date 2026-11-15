<?php

namespace App\Modules\Core\Access;

use App\Modules\Core\Tenancy\ForEachTenant;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Brings every tenant's roles up to date with the permission catalogue
 * (`php artisan tms:permissions:sync`, run by ops/migrate.sh after each
 * migration; RoleSeeder for a new tenant).
 *
 *  - creates the missing permissions (global catalogue) and system roles;
 *  - grants each role the default permissions never offered to it yet, and
 *    records them in role_permission_defaults_applied: a permission an
 *    administrator removed is never granted again. Custom roles
 *    (RoleCatalogue) never receive default permissions.
 *
 * Idempotent. Runs under RLS, in each tenant's context (runtime connection).
 * One tenant failing never blocks the others; run() returns the failed ones.
 */
final class PermissionSync
{
    public const TABLE = 'role_permission_defaults_applied';

    /**
     * @return list<Tenant> the tenants that could not be synchronised
     */
    public static function run(): array
    {
        self::ensurePermissions();

        // Suspended tenants too: their matrix must be complete if reactivated.
        return ForEachTenant::run(fn () => self::syncCurrentTenant(), includeSuspended: true);
    }

    public static function forTenant(Tenant $tenant): void
    {
        self::ensurePermissions();

        app(TenantContext::class)->run($tenant, fn () => self::syncCurrentTenant());
    }

    /**
     * Read from the table, not spatie's cached catalogue (which may be stale).
     */
    private static function ensurePermissions(): void
    {
        foreach (Permissions::all() as $permission) {
            PermissionModel::query()->firstOrCreate(['name' => $permission, 'guard_name' => RoleSeeder::GUARD]);
        }
    }

    /**
     * Writes the default scope of the given permissions, when the role's
     * defaults declare one (called only as a default is granted: a scope the
     * administrator changed later is never rewritten).
     *
     * @param  list<string>  $permissions
     */
    public static function writeDefaultScopes(string $roleId, Role $role, array $permissions, ?string $tenantId): void
    {
        $rows = [];
        foreach (Permissions::defaultScopesFor($role) as $permission => $scope) {
            if (in_array($permission, $permissions, true)) {
                $rows[] = ['tenant_id' => $tenantId, 'role_id' => $roleId, 'permission' => $permission, 'scope' => $scope->value];
            }
        }
        if ($rows !== []) {
            DB::table(Scopes::TABLE)->insertOrIgnore($rows);
        }
    }

    private static function syncCurrentTenant(): void
    {
        $tenantId = app(TenantContext::class)->id();
        $registrar = app(PermissionRegistrar::class);

        // The tenant's cached catalogue may predate permissions created above.
        $registrar->forgetCachedPermissions();

        DB::transaction(function () use ($tenantId): void {
            $offered = DB::table(self::TABLE)->get(['role', 'permission'])
                ->groupBy('role')
                ->map(fn ($rows) => $rows->pluck('permission')->all());

            foreach (Role::cases() as $role) {
                $model = RoleModel::query()->where('name', $role->value)->where('guard_name', RoleSeeder::GUARD)->first()
                    ?? RoleModel::create(['name' => $role->value, 'guard_name' => RoleSeeder::GUARD, 'system' => true, 'requires_2fa' => $role->requires2fa()]);

                $new = array_values(array_diff(Permissions::defaultsFor($role), $offered->get($role->value, [])));

                if ($new === []) {
                    continue;
                }

                $model->givePermissionTo($new);
                self::writeDefaultScopes((string) $model->getKey(), $role, $new, $tenantId);

                DB::table(self::TABLE)->insertOrIgnore(array_map(fn (string $permission) => [
                    'tenant_id' => $tenantId,
                    'role' => $role->value,
                    'permission' => $permission,
                ], $new));
            }
        });

        $registrar->forgetCachedPermissions();
        Scopes::forget();
    }
}
