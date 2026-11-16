<?php

namespace App\Modules\Core\Access;

use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Scope of a user for a scoped permission, read from his roles in the current
 * organisme. A scoped permission without a row in role_permission_scopes
 * means « all ». Memoised for the request / job only (forget() at each job
 * and after each change of the matrix): never shared between processes.
 */
final class Scopes
{
    public const TABLE = 'role_permission_scopes';

    /** @var array<string, AccessScope|null> */
    private static array $memo = [];

    /**
     * null when the user does not hold the permission; otherwise the widest
     * scope among his roles that grant it.
     */
    public static function of(User $user, string $permission): ?AccessScope
    {
        $tenant = app(TenantContext::class)->current();
        $key = ($tenant === null ? '-' : $tenant->id).'|'.$user->getKey().'|'.$permission;
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        return self::$memo[$key] = self::resolve($user, $permission);
    }

    public static function roleUses(RoleModel $role, AccessScope $scope): bool
    {
        $granted = $role->permissions()->pluck('name')->all();
        $rows = DB::table(self::TABLE)->where('role_id', $role->getKey())->pluck('scope', 'permission');

        foreach ($granted as $permission) {
            if (! Permissions::isScoped($permission)) {
                continue;
            }
            $effective = AccessScope::tryFrom((string) $rows->get($permission, '')) ?? AccessScope::All;
            if ($effective === $scope) {
                return true;
            }
        }

        return false;
    }

    /**
     * The user holds rights, all of them on « Ses séances » only (a trainer
     * and nothing else): the menu shows « Mon planning » alone.
     */
    public static function trainerOnly(User $user): bool
    {
        $held = 0;
        foreach (Permissions::all() as $permission) {
            if (! $user->can($permission)) {
                continue;
            }
            if (self::of($user, $permission) !== AccessScope::Trainer) {
                return false;
            }
            $held++;
        }

        return $held > 0;
    }

    /**
     * Ids of the users of the current organisme (among $userIds when given)
     * one of whose roles grants $permission on $scope, in one query (a list
     * of users). A scope never offered for the right is never matched.
     *
     * @param  list<string>|null  $userIds
     * @return list<string>
     */
    public static function usersWith(string $permission, AccessScope $scope, ?array $userIds = null): array
    {
        if ($scope !== AccessScope::All && ! Permissions::allowsScope($permission, $scope)) {
            return [];
        }
        $query = DB::table('model_has_roles as mr')
            ->join('role_has_permissions as rp', 'rp.role_id', '=', 'mr.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->leftJoin(self::TABLE.' as s', fn ($join) => $join->on('s.role_id', '=', 'mr.role_id')->where('s.permission', '=', $permission))
            ->where('mr.tenant_id', app(TenantContext::class)->id())
            ->where('mr.model_type', (new User)->getMorphClass())
            ->where('p.name', $permission);
        $scope === AccessScope::All
            ? $query->where(fn ($q) => $q->whereNull('s.scope')->orWhere('s.scope', AccessScope::All->value))
            : $query->where('s.scope', $scope->value);
        if ($userIds !== null) {
            $query->whereIn('mr.model_id', $userIds);
        }

        return $query->distinct()->pluck('mr.model_id')->map(fn ($id) => (string) $id)->all();
    }

    public static function forget(): void
    {
        self::$memo = [];
    }

    private static function resolve(User $user, string $permission): ?AccessScope
    {
        if (! $user->isActive() || ! $user->checkPermissionTo($permission)) {
            return null;
        }

        $rows = DB::table('model_has_roles as mr')
            ->join('role_has_permissions as rp', 'rp.role_id', '=', 'mr.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->leftJoin(self::TABLE.' as s', fn ($join) => $join->on('s.role_id', '=', 'mr.role_id')->where('s.permission', '=', $permission))
            ->where('mr.tenant_id', app(TenantContext::class)->id())
            ->where('mr.model_type', $user->getMorphClass())
            ->where('mr.model_id', $user->getKey())
            ->where('p.name', $permission)
            ->pluck('s.scope');

        $widest = null;
        foreach ($rows as $value) {
            $scope = AccessScope::tryFrom((string) $value) ?? AccessScope::All;
            // A scope never offered for this right (« Ses séances » on a CRM
            // right…) is never applied: the narrowest ordinary one instead.
            if ($scope === AccessScope::Trainer && ! Permissions::allowsScope($permission, $scope)) {
                $scope = AccessScope::Own;
            }
            if ($widest === null || $scope->rank() > $widest->rank()) {
                $widest = $scope;
            }
        }

        return $widest ?? AccessScope::All;
    }
}
