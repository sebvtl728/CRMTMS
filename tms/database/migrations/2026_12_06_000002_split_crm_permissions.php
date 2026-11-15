<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * 0.5.0: the four global CRM rights become rights per domain (fiches,
 * opportunités, devis, actions), without changing anybody's access:
 *
 *  - crm.view   -> the four *.view; crm.update -> the four *.update (creating
 *    is part of « Modifier »); crm.delete -> the four *.delete; crm.create
 *    has no right of its own any more;
 *  - catalogue.manage also gives the new catalogue.delete;
 *  - new rights follow the default matrix: crm.activities.team (replaces the
 *    « administrator or direction » test of « À faire » and the planning),
 *    crm.revenue.view (scoped), crm.revenue.manage;
 *  - the Responsable DAS keeps working in his DAS: his scoped rights get the
 *    « das » scope; the commercial roles see their own revenue (« own »).
 *
 * Every new name is recorded as offered (role_permission_defaults_applied)
 * for every role: tms:permissions:sync, run right after (ops/migrate.sh),
 * grants nothing more, and a right an administrator had removed (e.g.
 * crm.delete) is not granted again under its new names.
 *
 * Literal names (as of 0.5.0), never the module constants. Run per tenant
 * (FORCE RLS); the permissions catalogue is global.
 */
return new class extends Migration
{
    private const GUARD = 'web';

    private const DOMAINS = ['fiches', 'opportunities', 'quotes', 'activities'];

    /** Old right => its new names. */
    private const SPLIT = [
        'crm.view' => ['crm.fiches.view', 'crm.opportunities.view', 'crm.quotes.view', 'crm.activities.view'],
        'crm.update' => ['crm.fiches.update', 'crm.opportunities.update', 'crm.quotes.update', 'crm.activities.update'],
        'crm.delete' => ['crm.fiches.delete', 'crm.opportunities.delete', 'crm.quotes.delete', 'crm.activities.delete'],
        'catalogue.manage' => ['catalogue.delete'],
    ];

    private const OLD = ['crm.view', 'crm.create', 'crm.update', 'crm.delete'];

    /** New rights without an old counterpart, per system role (default matrix). */
    private const NEW_DEFAULTS = [
        'admin_organisme' => ['crm.activities.team', 'crm.revenue.view', 'crm.revenue.manage'],
        'direction' => ['crm.activities.team', 'crm.revenue.view', 'crm.revenue.manage'],
        'commercial' => ['crm.revenue.view'],
        'charge_relations' => ['crm.revenue.view'],
        'responsable_das' => ['crm.revenue.view'],
    ];

    /** Rights carrying a scope (own / das / all). */
    private const SCOPED = [
        'crm.fiches.view', 'crm.fiches.update', 'crm.fiches.delete',
        'crm.opportunities.view', 'crm.opportunities.update', 'crm.opportunities.delete',
        'crm.quotes.view', 'crm.quotes.update', 'crm.quotes.delete',
        'crm.activities.view', 'crm.activities.update', 'crm.activities.delete',
        'crm.revenue.view',
    ];

    private const NEW_EXTRA = ['crm.activities.team', 'crm.revenue.view', 'crm.revenue.manage'];

    public function up(): void
    {
        $new = $this->newNames();
        $ids = $this->ensurePermissions($new);

        $this->eachTenant(function (string $tenantId) use ($new, $ids): void {
            foreach (DB::table('roles')->get(['id', 'name']) as $role) {
                $held = $this->roleNames($role->id);
                $granted = [...$this->split($held), ...(self::NEW_DEFAULTS[$role->name] ?? [])];
                $this->grantToRole($tenantId, $role->id, $granted, $ids);
                $this->writeScopes($tenantId, $role->id, $role->name, $granted);

                DB::table('role_permission_defaults_applied')->insertOrIgnore(array_map(
                    fn (string $permission) => ['tenant_id' => $tenantId, 'role' => $role->name, 'permission' => $permission], $new));
            }
            $this->splitDirectPermissions($tenantId, $ids);

            $old = $this->idsOf(self::OLD);
            DB::table('role_has_permissions')->whereIn('permission_id', $old)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $old)->delete();
            DB::table('role_permission_defaults_applied')->whereIn('permission', self::OLD)->delete();
        });

        DB::table('permissions')->whereIn('name', self::OLD)->delete();
        $this->forgetCache();
    }

    public function down(): void
    {
        $new = $this->newNames();
        $ids = $this->ensurePermissions(self::OLD);

        $this->eachTenant(function (string $tenantId) use ($new, $ids): void {
            foreach (DB::table('roles')->get(['id', 'name']) as $role) {
                $this->grantToRole($tenantId, $role->id, $this->merge($this->roleNames($role->id)), $ids);

                DB::table('role_permission_defaults_applied')->insertOrIgnore(array_map(
                    fn (string $permission) => ['tenant_id' => $tenantId, 'role' => $role->name, 'permission' => $permission], self::OLD));
            }

            $direct = DB::table('model_has_permissions')->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
                ->get(['model_type', 'model_id', 'permissions.name'])->groupBy(fn (object $row) => $row->model_type.'|'.$row->model_id);
            foreach ($direct as $rows) {
                $this->grantDirect($tenantId, $rows->first()->model_type, $rows->first()->model_id, $this->merge($rows->pluck('name')->all()), $ids);
            }

            DB::table('role_permission_scopes')->whereIn('permission', $new)->delete();
            DB::table('role_permission_defaults_applied')->whereIn('permission', $new)->delete();
            $newIds = $this->idsOf($new);
            DB::table('role_has_permissions')->whereIn('permission_id', $newIds)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $newIds)->delete();
        });

        DB::table('permissions')->whereIn('name', $new)->delete();
        $this->forgetCache();
    }

    /**
     * @return list<string> every name this migration introduces
     */
    private function newNames(): array
    {
        return [...array_merge(...array_values(self::SPLIT)), ...self::NEW_EXTRA];
    }

    /**
     * @param  list<string>  $held
     * @return list<string> the new names matching the old rights held
     */
    private function split(array $held): array
    {
        $granted = [];
        foreach (self::SPLIT as $old => $names) {
            if (in_array($old, $held, true)) {
                array_push($granted, ...$names);
            }
        }

        return $granted;
    }

    /**
     * Inverse of split(): a « Consulter » of any domain gives crm.view, a
     * « Modifier » crm.create and crm.update, a « Supprimer » crm.delete.
     *
     * @param  list<string>  $held
     * @return list<string>
     */
    private function merge(array $held): array
    {
        $old = [];
        foreach (['view' => ['crm.view'], 'update' => ['crm.create', 'crm.update'], 'delete' => ['crm.delete']] as $action => $names) {
            foreach (self::DOMAINS as $domain) {
                if (in_array("crm.{$domain}.{$action}", $held, true)) {
                    array_push($old, ...$names);
                    break;
                }
            }
        }

        return $old;
    }

    /**
     * The Responsable DAS keeps to his DAS (« das » on every scoped right he
     * holds); the commercial roles see their own revenue. Anything else: no
     * row, the whole organisme.
     *
     * @param  list<string>  $granted
     */
    private function writeScopes(string $tenantId, string $roleId, string $roleName, array $granted): void
    {
        $scopes = match ($roleName) {
            'responsable_das' => array_fill_keys(self::SCOPED, 'das'),
            'commercial', 'charge_relations' => ['crm.revenue.view' => 'own'],
            default => [],
        };

        $rows = [];
        foreach (array_intersect_key($scopes, array_flip($granted)) as $permission => $scope) {
            $rows[] = ['tenant_id' => $tenantId, 'role_id' => $roleId, 'permission' => $permission, 'scope' => $scope];
        }
        DB::table('role_permission_scopes')->insertOrIgnore($rows);
    }

    /**
     * Permissions granted to a user directly (not through a role) follow
     * the same split.
     *
     * @param  array<string, string>  $ids
     */
    private function splitDirectPermissions(string $tenantId, array $ids): void
    {
        $direct = DB::table('model_has_permissions')->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->whereIn('permissions.name', array_keys(self::SPLIT))
            ->get(['model_type', 'model_id', 'permissions.name'])->groupBy(fn (object $row) => $row->model_type.'|'.$row->model_id);

        foreach ($direct as $rows) {
            $this->grantDirect($tenantId, $rows->first()->model_type, $rows->first()->model_id, $this->split($rows->pluck('name')->all()), $ids);
        }
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, string>  $ids
     */
    private function grantToRole(string $tenantId, string $roleId, array $names, array $ids): void
    {
        DB::table('role_has_permissions')->insertOrIgnore(array_map(
            fn (string $name) => ['permission_id' => $ids[$name], 'role_id' => $roleId, 'tenant_id' => $tenantId], array_values(array_unique($names))));
    }

    /**
     * @param  list<string>  $names
     * @param  array<string, string>  $ids
     */
    private function grantDirect(string $tenantId, string $modelType, string $modelId, array $names, array $ids): void
    {
        DB::table('model_has_permissions')->insertOrIgnore(array_map(
            fn (string $name) => ['permission_id' => $ids[$name], 'model_type' => $modelType, 'model_id' => $modelId, 'tenant_id' => $tenantId],
            array_values(array_unique($names))));
    }

    /**
     * @return list<string>
     */
    private function roleNames(string $roleId): array
    {
        return DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', $roleId)->pluck('permissions.name')->all();
    }

    /**
     * Creates the missing permissions of the global catalogue.
     *
     * @param  list<string>  $names
     * @return array<string, string> name => id, every name of $names and SPLIT
     */
    private function ensurePermissions(array $names): array
    {
        foreach ($names as $name) {
            if (! DB::table('permissions')->where('name', $name)->where('guard_name', self::GUARD)->exists()) {
                DB::table('permissions')->insert(['id' => (string) Str::uuid7(), 'name' => $name, 'guard_name' => self::GUARD, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return DB::table('permissions')->where('guard_name', self::GUARD)->pluck('id', 'name')->all();
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function idsOf(array $names): array
    {
        return DB::table('permissions')->whereIn('name', $names)->pluck('id')->all();
    }

    /**
     * Runs $fn in each tenant's RLS context (transaction-local setting).
     *
     * @param  callable(string): void  $fn
     */
    private function eachTenant(callable $fn): void
    {
        $previous = DB::selectOne("select current_setting('app.tenant_id', true) as v")->v ?? '';

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
            $fn((string) $tenantId);
        }

        DB::statement("select set_config('app.tenant_id', ?, true)", [$previous]);
    }

    private function forgetCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
