<?php

namespace App\Modules\Core\Access;

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The permissions of each role of the current organisme, changed from the
 * « Rôles et droits » screen. The administrator's role is never changed, and
 * the LOCKED permissions are never granted nor revoked here. Each change is
 * journaled (role, permission: no personal data) and drops the organisme's
 * cached permissions, so users see it at their next request.
 *
 * A right removed here stays removed: PermissionSync only grants a default
 * once (role_permission_defaults_applied).
 */
final class RoleMatrix
{
    public function __construct(private readonly AuditRecorder $audit, private readonly TenantContext $tenants) {}

    public static function editable(Role $role): bool
    {
        return $role !== Role::AdminOrganisme;
    }

    /**
     * @return list<string> sorted
     */
    public static function granted(Role $role): array
    {
        $model = self::model($role);

        return $model === null ? [] : $model->permissions()->pluck('name')->sort()->values()->all();
    }

    public function set(Role $role, string $permission, bool $granted): void
    {
        self::guard($role, $permission);
        $model = self::model($role) ?? throw new AccessDeniedHttpException;

        DB::transaction(function () use ($model, $role, $permission, $granted): void {
            $has = $model->permissions()->where('name', $permission)->exists();
            if ($has === $granted) {
                return;
            }
            $granted ? $model->givePermissionTo($permission) : $model->revokePermissionTo($permission);
            if (! $granted) {
                DB::table(Scopes::TABLE)->where('role_id', $model->getKey())->where('permission', $permission)->delete();
            }
            $this->audit->record($granted ? 'roles.permission_granted' : 'roles.permission_revoked', $model, [], ['role' => $role->value, 'permission' => $permission]);
        });

        $this->forget();
    }

    /**
     * Changes the scope of a granted, scoped permission (journaled with the
     * previous value).
     */
    public function setScope(Role $role, string $permission, AccessScope $scope): void
    {
        self::guard($role, $permission);
        if (! Permissions::isScoped($permission)) {
            throw new AccessDeniedHttpException;
        }
        $model = self::model($role) ?? throw new AccessDeniedHttpException;

        DB::transaction(function () use ($model, $permission, $scope): void {
            if (! $model->permissions()->where('name', $permission)->exists()) {
                throw new UserFacingHttpException(422, 'Accordez d\'abord ce droit.');
            }
            $current = DB::table(Scopes::TABLE)->where('role_id', $model->getKey())->where('permission', $permission)->value('scope');
            $before = AccessScope::tryFrom((string) $current) ?? AccessScope::All;
            if ($before === $scope) {
                return;
            }
            DB::table(Scopes::TABLE)->upsert(
                [['tenant_id' => $this->tenants->id(), 'role_id' => $model->getKey(), 'permission' => $permission, 'scope' => $scope->value, 'updated_at' => now()]],
                ['role_id', 'permission'],
                ['scope', 'updated_at'],
            );
            $this->audit->record('roles.scope_changed', $model, ['permission' => $permission, 'scope' => $before->value], ['permission' => $permission, 'scope' => $scope->value]);
        });

        $this->forget();
    }

    /**
     * Exactly the default permissions of the role, all recorded as offered.
     */
    public function reset(Role $role): void
    {
        if (! self::editable($role)) {
            throw new AccessDeniedHttpException;
        }
        $model = self::model($role) ?? throw new AccessDeniedHttpException;
        $defaults = Permissions::defaultsFor($role);

        DB::transaction(function () use ($model, $role, $defaults): void {
            $model->syncPermissions($defaults);
            DB::table(Scopes::TABLE)->where('role_id', $model->getKey())->delete();
            PermissionSync::writeDefaultScopes((string) $model->getKey(), $role, $defaults, $this->tenants->id());
            DB::table(PermissionSync::TABLE)->insertOrIgnore(array_map(fn (string $permission) => [
                'tenant_id' => $this->tenants->id(),
                'role' => $role->value,
                'permission' => $permission,
            ], $defaults));
            $this->audit->record('roles.reset', $model, [], ['role' => $role->value]);
        });

        $this->forget();
    }

    private static function guard(Role $role, string $permission): void
    {
        if (! self::editable($role) || in_array($permission, Permissions::LOCKED, true)) {
            throw new AccessDeniedHttpException;
        }
    }

    private static function model(Role $role): ?RoleModel
    {
        return RoleModel::query()->where('name', $role->value)->where('guard_name', RoleSeeder::GUARD)->first();
    }

    /**
     * The organisme's cached catalogue (key per tenant, TenantPermissionRegistrar).
     */
    private function forget(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Scopes::forget();
    }
}
