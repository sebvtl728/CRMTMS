<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Permissions;
use App\Modules\Core\Access\PermissionSync;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Replays the 0.5.0 split on an organisme as it was in 0.4.2 (down() brings
 * the matrix back to the old names; checked against the literal 0.4.2 matrix).
 */
function splitMigration(): object
{
    return require database_path('migrations/2026_12_06_000002_split_crm_permissions.php');
}

/**
 * @return array<string, list<string>> role name => sorted CRM / catalogue permissions
 */
function crmMatrix(): array
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return RoleModel::query()->with('permissions')->get()
        ->mapWithKeys(fn (RoleModel $r) => [$r->name => $r->permissions->pluck('name')
            ->filter(fn (string $p) => str_starts_with($p, 'crm.') || str_starts_with($p, 'catalogue.'))->sort()->values()->all()])
        ->all();
}

/**
 * @return array<string, string> CRM permission => scope of a role
 */
function scopesOfRole(Role $role): array
{
    $id = RoleModel::query()->where('name', $role->value)->sole()->getKey();

    return DB::table(Scopes::TABLE)->where('role_id', $id)->where('permission', 'like', 'crm.%')->orderBy('permission')->pluck('scope', 'permission')->all();
}

function backTo042(): Tenant
{
    $tenant = inTenant();
    // A 0.4.2 organisme: the trainer's own actions (lot 1e) did not exist yet (down() would merge them into crm.view / crm.update).
    RoleModel::query()->where('name', Role::Formateur->value)->sole()->revokePermissionTo(Scopes::TRAINER_OWN_EXTRAS);
    // Nor the dashboards right (lot 1e).
    // (The administrator keeps every right whatever its age.)
    RoleModel::query()->where('name', '!=', Role::AdminOrganisme->value)->get()->each(fn (RoleModel $r) => $r->revokePermissionTo('crm.dashboards.view'));
    splitMigration()->down();
    Scopes::forget();

    return $tenant;
}

const MATRIX_042 = [
    // The administrator holds every right, the dashboards one (lot 1e) included.
    'admin_organisme' => ['catalogue.manage', 'catalogue.view', 'crm.create', 'crm.dashboards.view', 'crm.delete', 'crm.export', 'crm.gdpr', 'crm.import', 'crm.merge', 'crm.update', 'crm.view'],
    'direction' => ['catalogue.manage', 'catalogue.view', 'crm.export', 'crm.gdpr', 'crm.view'],
    'commercial' => ['catalogue.view', 'crm.create', 'crm.delete', 'crm.export', 'crm.import', 'crm.merge', 'crm.update', 'crm.view'],
    'charge_relations' => ['catalogue.view', 'crm.create', 'crm.delete', 'crm.export', 'crm.import', 'crm.merge', 'crm.update', 'crm.view'],
    'assistant' => ['catalogue.view', 'crm.create', 'crm.update', 'crm.view'],
    'responsable_das' => ['catalogue.view', 'crm.export', 'crm.view'],
    // 0.8.0: no CRM nor catalogue right.
    'formateur' => [],
];

it('migration maps every default role', function () {
    backTo042();
    expect(crmMatrix())->toEqualCanonicalizing(MATRIX_042)
        ->and(DB::table(Scopes::TABLE)->where('permission', 'like', 'crm.%')->count())->toBe(0);

    splitMigration()->up();
    Scopes::forget();

    foreach (Role::cases() as $role) {
        // The trainer's own actions (lot 1e) are granted later by tms:permissions:sync, not by this 0.5.0 migration.
        $expected = collect(Permissions::defaultsFor($role))
            ->filter(fn (string $p) => str_starts_with($p, 'crm.') || str_starts_with($p, 'catalogue.'))
            ->reject(fn (string $p) => ($role === Role::Formateur && in_array($p, Scopes::TRAINER_OWN_EXTRAS, true)) || ($role !== Role::AdminOrganisme && $p === 'crm.dashboards.view'))->sort()->values()->all();
        expect(crmMatrix()[$role->value], $role->value)->toBe($expected)
            // Like a new organisme: the default scope of each scoped right the role holds.
            ->and(scopesOfRole($role), $role->value)->toEqualCanonicalizing(array_map(fn (AccessScope $s) => $s->value,
                array_intersect_key(Permissions::defaultScopesFor($role), array_flip($expected))));
    }

    $old = ['crm.view', 'crm.create', 'crm.update', 'crm.delete'];
    expect(DB::table('permissions')->whereIn('name', $old)->count())->toBe(0)
        ->and(DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')->whereIn('permissions.name', $old)->count())->toBe(0)
        ->and(DB::table('role_permission_defaults_applied')->whereIn('permission', $old)->count())->toBe(0);

    // The assistant keeps removing actions (crm.update), and nothing else.
    expect(collect(crmMatrix()['assistant'])->filter(fn (string $p) => str_ends_with($p, '.delete'))->values()->all())->toBe(['crm.activities.delete']);

    // The administrator of the organisme keeps everything.
    $admin = admin();
    foreach (Permissions::all() as $permission) {
        expect($admin->fresh()->can($permission), $permission)->toBeTrue();
    }
});

it('migration keeps revoked rights revoked', function () {
    backTo042();
    RoleModel::query()->where('name', 'commercial')->sole()->revokePermissionTo('crm.delete');

    splitMigration()->up();
    // Removing an action was part of crm.update: kept; nothing else to delete.
    $deletes = fn () => array_values(array_filter(crmMatrix()['commercial'], fn (string $p) => str_ends_with($p, '.delete')));
    expect($deletes())->toBe(['crm.activities.delete'])
        ->and(crmMatrix()['commercial'])->toContain('crm.fiches.update', 'crm.quotes.view');

    // Every new name is recorded as offered: the sync right after grants nothing more.
    $before = crmMatrix();
    PermissionSync::run();
    expect($deletes())->toBe(['crm.activities.delete'])
        ->and(crmMatrix())->toBe($before);
});

it('migration restricts responsable das by scope', function () {
    backTo042();
    // A Responsable DAS the administrator allowed to edit: still within his DAS.
    RoleModel::query()->where('name', 'responsable_das')->sole()->givePermissionTo(['crm.update']);

    splitMigration()->up();

    expect(scopesOfRole(Role::ResponsableDas))->toBe([
        'crm.activities.delete' => 'das', 'crm.activities.update' => 'das', 'crm.activities.view' => 'das',
        'crm.fiches.update' => 'das', 'crm.fiches.view' => 'das',
        'crm.opportunities.update' => 'das', 'crm.opportunities.view' => 'das',
        'crm.quotes.update' => 'das', 'crm.quotes.view' => 'das',
        'crm.revenue.view' => 'das',
    ]);
    $responsable = userWithRole(Role::ResponsableDas);
    expect(Scopes::of($responsable, 'crm.fiches.view'))->toBe(AccessScope::Das)
        ->and(Scopes::of(commercial(), 'crm.fiches.view'))->toBe(AccessScope::All);
});

it('migration grants catalogue.delete with catalogue.manage', function () {
    backTo042();
    RoleModel::query()->where('name', 'direction')->sole()->revokePermissionTo('catalogue.manage');
    RoleModel::query()->where('name', 'assistant')->sole()->givePermissionTo('catalogue.manage');

    splitMigration()->up();

    expect(crmMatrix()['direction'])->not->toContain('catalogue.delete')
        ->and(crmMatrix()['assistant'])->toContain('catalogue.manage', 'catalogue.delete')
        ->and(crmMatrix()['admin_organisme'])->toContain('catalogue.delete');
});

it('new rights follow the defaults table', function () {
    backTo042();
    splitMigration()->up();
    $matrix = crmMatrix();

    foreach (['admin_organisme', 'direction'] as $role) {
        expect($matrix[$role], $role)->toContain('crm.activities.team', 'crm.revenue.view', 'crm.revenue.manage');
    }
    foreach (['commercial', 'charge_relations', 'responsable_das'] as $role) {
        expect($matrix[$role], $role)->toContain('crm.revenue.view')
            ->not->toContain('crm.activities.team')->not->toContain('crm.revenue.manage');
    }
    expect($matrix['assistant'])->not->toContain('crm.revenue.view')->not->toContain('crm.activities.team')
        ->and(scopesOfRole(Role::Commercial))->toBe(['crm.revenue.view' => 'own'])
        ->and(scopesOfRole(Role::ChargeRelations))->toBe(['crm.revenue.view' => 'own'])
        ->and(scopesOfRole(Role::ResponsableDas)['crm.revenue.view'])->toBe('das')
        ->and(scopesOfRole(Role::Direction))->toBe([]);
});

it('splits the rights granted to a user directly, and down() merges them back', function () {
    backTo042();
    $user = assistant();
    $user->givePermissionTo('crm.delete');
    $editor = userWithPermissions([]);
    $editor->givePermissionTo('crm.update');

    splitMigration()->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $direct = fn ($u) => $u->fresh()->getDirectPermissions()->pluck('name')->sort()->values()->all();
    expect($direct($user))->toBe(['crm.activities.delete', 'crm.fiches.delete', 'crm.opportunities.delete', 'crm.quotes.delete'])
        // Removing an action was part of crm.update.
        ->and($direct($editor))->toBe(['crm.activities.delete', 'crm.activities.update', 'crm.fiches.update', 'crm.opportunities.update', 'crm.quotes.update']);

    splitMigration()->down();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect($direct($user))->toBe(['crm.delete'])
        ->and($direct($editor))->toBe(['crm.create', 'crm.update'])
        ->and(collect(crmMatrix())->only(array_keys(MATRIX_042))->all())->toEqualCanonicalizing(MATRIX_042);
});

it('migrates every organisme in its own context', function () {
    $other = Tenant::factory()->create();
    backTo042();
    inTenant($other);
    RoleModel::query()->where('name', 'commercial')->sole()->revokePermissionTo('crm.delete');
    inTenant();

    splitMigration()->up();

    expect(crmMatrix()['commercial'])->toContain('crm.fiches.delete');
    inTenant($other);
    expect(crmMatrix()['commercial'])->not->toContain('crm.fiches.delete')->toContain('crm.quotes.view');
});

it('forgets the cached permissions of every organisme', function () {
    $tenant = backTo042();
    $key = config('permission.cache.key').'.'.$tenant->id;
    $store = app(PermissionRegistrar::class)->getCacheStore();
    $store->put($key, 'stale 0.4.2 catalogue', 3600);

    // As in « artisan migrate »: no tenant context.
    app(TenantContext::class)->clear();
    splitMigration()->up();

    expect($store->get($key))->toBeNull();
});
