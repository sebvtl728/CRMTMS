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

    public static function editable(RoleModel $role): bool
    {
        return ! $role->isAdmin();
    }

    /**
     * @return list<string> sorted
     */
    public static function granted(RoleModel $role): array
    {
        return $role->permissions()->pluck('name')->sort()->values()->all();
    }

    /**
     * The default permissions of a system role; none for a custom role.
     *
     * @return list<string>
     */
    public static function defaults(RoleModel $role): array
    {
        $system = $role->systemRole();

        return $system === null ? [] : Permissions::defaultsFor($system);
    }

    public function set(RoleModel $model, string $permission, bool $granted): void
    {
        self::guard($model, $permission);

        DB::transaction(function () use ($model, $permission, $granted): void {
            $has = $model->permissions()->where('name', $permission)->exists();
            if ($has === $granted) {
                return;
            }
            $granted ? $model->givePermissionTo($permission) : $model->revokePermissionTo($permission);
            // Re-granting never widens a role: the default scope comes back (none declared = all).
            DB::table(Scopes::TABLE)->where('role_id', $model->getKey())->where('permission', $permission)->delete();
            $system = $model->systemRole();
            if ($granted && $system !== null) {
                PermissionSync::writeDefaultScopes((string) $model->getKey(), $system, [$permission], $this->tenants->id());
            }
            $this->audit->record($granted ? 'roles.permission_granted' : 'roles.permission_revoked', $model, [], ['role' => $model->name, 'permission' => $permission]);
        });

        $this->changed($model);
    }

    /**
     * Changes the scope of a granted, scoped permission (journaled with the
     * previous value).
     */
    public function setScope(RoleModel $model, string $permission, AccessScope $scope): void
    {
        self::guard($model, $permission);
        if (! Permissions::isScoped($permission)) {
            throw new AccessDeniedHttpException;
        }

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

        $this->changed($model);
    }

    /**
     * Exactly the default permissions of a system role, all recorded as
     * offered; no right at all for a custom role.
     */
    public function reset(RoleModel $model): void
    {
        if (! self::editable($model)) {
            throw new AccessDeniedHttpException;
        }
        $system = $model->systemRole();
        $defaults = self::defaults($model);

        DB::transaction(function () use ($model, $system, $defaults): void {
            $model->syncPermissions($defaults);
            DB::table(Scopes::TABLE)->where('role_id', $model->getKey())->delete();
            if ($system !== null) {
                PermissionSync::writeDefaultScopes((string) $model->getKey(), $system, $defaults, $this->tenants->id());
                DB::table(PermissionSync::TABLE)->insertOrIgnore(array_map(fn (string $permission) => [
                    'tenant_id' => $this->tenants->id(),
                    'role' => $system->value,
                    'permission' => $permission,
                ], $defaults));
            }
            $this->audit->record('roles.reset', $model, [], ['role' => $model->name]);
        });

        $this->changed($model);
    }

    private static function guard(RoleModel $role, string $permission): void
    {
        if (! self::editable($role) || in_array($permission, Permissions::LOCKED, true)) {
            throw new AccessDeniedHttpException;
        }
    }

    /**
     * Drops the organisme's cached catalogue (key per tenant,
     * TenantPermissionRegistrar) and tells the modules (after commit).
     */
    private function changed(RoleModel $role): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Scopes::forget();
        $role->unsetRelation('permissions');
        DB::afterCommit(fn () => RoleScopesChanged::dispatch($role));
    }
}
