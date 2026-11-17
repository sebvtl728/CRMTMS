<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Access\PermissionSync;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Sessions\Permissions;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

it('declares the rights of the Sessions module, scoped but for trainers and rooms', function () {
    expect(Permissions::all())->toBe([
        'sessions.dossiers.view', 'sessions.dossiers.update', 'sessions.dossiers.delete',
        'sessions.sessions.view', 'sessions.sessions.update', 'sessions.sessions.delete',
        'sessions.seances.view', 'sessions.seances.manage', 'sessions.attendance.manage', 'sessions.attendance.close',
        'sessions.trainers.manage', 'sessions.rooms.manage',
    ])
        ->and(Permissions::DOSSIERS_VIEW)->toBe('sessions.dossiers.view')
        ->and(Permissions::SESSIONS_VIEW)->toBe('sessions.sessions.view')
        ->and(CorePermissions::all())->toContain(...Permissions::all());

    foreach (Permissions::all() as $permission) {
        $label = CorePermissions::label($permission);
        expect(CorePermissions::isScoped($permission), $permission)->toBe($permission !== Permissions::ROOMS_MANAGE)
            ->and($label, $permission)->not->toBeNull()
            ->and($label['label'] ?? '', $permission)->not->toBe('')
            ->and($label['description'] ?? '', $permission)->not->toBe('');
    }

    expect(CorePermissions::label(Permissions::DOSSIERS_UPDATE)['group'] ?? null)->toBe('Dossiers')
        ->and(CorePermissions::label(Permissions::SESSIONS_DELETE)['group'] ?? null)->toBe('Sessions et inscriptions');
});

it('grants the default rights and scopes per role', function () {
    $view = [Permissions::DOSSIERS_VIEW, Permissions::SESSIONS_VIEW];
    $viewUpdate = [Permissions::DOSSIERS_VIEW, Permissions::DOSSIERS_UPDATE, Permissions::SESSIONS_VIEW, Permissions::SESSIONS_UPDATE];
    // Dossiers and sessions only (the planning rights: FormateurRoleTest).
    $lot2a = fn (Role $role) => array_values(array_filter(Permissions::defaultsFor($role), fn (string $p) => preg_match('/^sessions\.(dossiers|sessions)\./', $p) === 1));

    expect(Permissions::defaultsFor(Role::AdminOrganisme))->toEqualCanonicalizing(Permissions::all())
        ->and($lot2a(Role::Direction))->toEqualCanonicalizing($view)
        ->and($lot2a(Role::Commercial))->toEqualCanonicalizing($viewUpdate)
        ->and($lot2a(Role::ChargeRelations))->toEqualCanonicalizing($viewUpdate)
        ->and($lot2a(Role::Assistant))->toEqualCanonicalizing($viewUpdate)
        ->and($lot2a(Role::ResponsableDas))->toEqualCanonicalizing($view)
        ->and($lot2a(Role::Formateur))->toBe([]);

    // Seeded roles of a tenant: the whole organisme, the Responsable DAS his DAS.
    foreach ([Role::AdminOrganisme, Role::Direction, Role::Commercial, Role::ChargeRelations, Role::Assistant] as $role) {
        $user = userWithRole($role);
        expect(Scopes::of($user, Permissions::SESSIONS_VIEW), $role->value)->toBe(AccessScope::All)
            ->and(Scopes::of($user, Permissions::DOSSIERS_VIEW), $role->value)->toBe(AccessScope::All);
    }
    $das = userWithRole(Role::ResponsableDas);
    expect(Scopes::of($das, Permissions::SESSIONS_VIEW))->toBe(AccessScope::Das)
        ->and(Scopes::of($das, Permissions::DOSSIERS_VIEW))->toBe(AccessScope::Das)
        ->and(Scopes::of($das, Permissions::SESSIONS_UPDATE))->toBeNull()
        ->and(Scopes::of(userWithRole(Role::Commercial), Permissions::DOSSIERS_DELETE))->toBeNull();
});

it('shows the two themes on the « Rôles et droits » screen', function () {
    $this->actingAs(admin(twoFactor: true))->get('/admin/roles')->assertInertia(fn (Assert $page) => $page
        ->where('groups', fn ($groups) => collect($groups)->pluck('label')->intersect(['Dossiers', 'Sessions et inscriptions'])->values()->all() === ['Dossiers', 'Sessions et inscriptions']));
});

/**
 * Rights and scopes of the Sessions module per system role of $tenant.
 *
 * @return array{rights: array<string, list<string>>, scopes: list<string>}
 */
function sessionsMatrix(Tenant $tenant): array
{
    return app(TenantContext::class)->run($tenant, function (): array {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $roles = RoleModel::query()->where('system', true)->with('permissions')->get();

        return [
            'rights' => $roles->mapWithKeys(fn (RoleModel $r) => [$r->name => $r->permissions->pluck('name')
                ->filter(fn (string $p) => str_starts_with($p, 'sessions.'))->sort()->values()->all()])->sortKeys()->all(),
            'scopes' => DB::table(Scopes::TABLE)->join('roles', 'roles.id', '=', Scopes::TABLE.'.role_id')
                ->where(Scopes::TABLE.'.permission', 'like', 'sessions.%')
                ->orderBy('roles.name')->orderBy(Scopes::TABLE.'.permission')
                ->get(['roles.name', Scopes::TABLE.'.permission', Scopes::TABLE.'.scope'])
                ->map(fn ($row) => "{$row->name} {$row->permission} {$row->scope}")->all(),
        ];
    });
}

it('offers the Sessions rights and their scopes to the roles of an existing organisme once, on upgrade', function () {
    $tenant = Tenant::factory()->create();
    $fresh = sessionsMatrix($tenant);
    // As before 0.6.0: no sessions.* right, scope or offer recorded.
    app(TenantContext::class)->run($tenant, function (): void {
        foreach (RoleModel::all() as $role) {
            $role->revokePermissionTo(array_values(array_intersect(Permissions::all(), $role->permissions->pluck('name')->all())));
        }
        DB::table(Scopes::TABLE)->where('permission', 'like', 'sessions.%')->delete();
        DB::table(PermissionSync::TABLE)->where('permission', 'like', 'sessions.%')->delete();
    });
    expect(sessionsMatrix($tenant)['rights'])->each->toBe([]);

    PermissionSync::run();

    $synced = sessionsMatrix($tenant);
    expect($synced)->toBe($fresh)
        ->and($synced['rights']['responsable_das'])->toBe(['sessions.dossiers.view', 'sessions.seances.view', 'sessions.sessions.view', 'sessions.trainers.manage'])
        ->and($synced['scopes'])->toContain('responsable_das sessions.dossiers.view das', 'responsable_das sessions.sessions.view das', 'responsable_das sessions.trainers.manage das');

    // Twice: nothing changes.
    $applied = app(TenantContext::class)->run($tenant, fn () => DB::table(PermissionSync::TABLE)->count());
    PermissionSync::run();
    expect(sessionsMatrix($tenant))->toBe($synced)
        ->and(app(TenantContext::class)->run($tenant, fn () => DB::table(PermissionSync::TABLE)->count()))->toBe($applied);

    // A right an administrator revoked, a scope he changed: kept on the next sync.
    app(TenantContext::class)->run($tenant, function (): void {
        $commercial = RoleModel::findByName('commercial');
        $commercial->revokePermissionTo(Permissions::DOSSIERS_UPDATE);
        DB::table(Scopes::TABLE)->insert(['tenant_id' => app(TenantContext::class)->id(), 'role_id' => $commercial->id, 'permission' => Permissions::SESSIONS_VIEW, 'scope' => AccessScope::Own->value]);
    });
    PermissionSync::run();
    $after = sessionsMatrix($tenant);
    expect($after['rights']['commercial'])->not->toContain(Permissions::DOSSIERS_UPDATE)
        ->and($after['scopes'])->toContain('commercial sessions.sessions.view own');
});
