<?php

use App\Modules\Core\Access\PermissionModel;
use App\Modules\Core\Access\Permissions;
use App\Modules\Core\Access\PrimaryRoleChanged;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Access\RoleSeeder;
use App\Modules\Core\Tenancy\QueueTenancy;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\Invitation;
use App\Modules\Core\Users\User;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

// ---------------------------------------------------------------- catalogue

it('defines the seven roles with French labels and the 2FA requirement', function () {
    expect(array_map(fn (Role $r) => $r->value, Role::cases()))->toBe([
        'admin_organisme', 'direction', 'commercial', 'charge_relations', 'assistant', 'responsable_das', 'formateur',
    ])
        ->and(Role::AdminOrganisme->label())->toBe('Administrateur de l\'organisme')
        ->and(Role::ChargeRelations->label())->toBe('Chargé de relations')
        ->and(array_filter(Role::cases(), fn (Role $r) => $r->requires2fa()))
        ->toBe([Role::AdminOrganisme, Role::Direction]);
});

it('lists the core permission catalogue, then the modules\' permissions', function () {
    expect(array_slice(Permissions::all(), 0, 8))->toBe([
        'users.manage', 'roles.manage', 'companies.manage', 'references.manage', 'connectors.manage',
        'audit.view', 'settings.manage', 'exports.run',
    ])
        // The CRM themes, the Catalogue, Sessions, Finance, Admissions, then « Imports, exports et RGPD » (CrmServiceProvider).
        ->and(array_slice(Permissions::all(), 8))->toBe([
            ...array_values(array_diff(App\Modules\Crm\Permissions::all(), App\Modules\Crm\Permissions::GROUPS[App\Modules\Crm\Permissions::DATA_GROUP])),
            ...App\Modules\Catalogue\Permissions::all(),
            ...App\Modules\Sessions\Permissions::all(),
            ...App\Modules\Finance\Permissions::all(),
            ...App\Modules\Admissions\Permissions::all(),
            ...App\Modules\Qualite\Permissions::all(),
            ...App\Modules\Documents\Permissions::all(),
            ...App\Modules\Questionnaires\Permissions::all(),
            ...App\Modules\Crm\Permissions::GROUPS[App\Modules\Crm\Permissions::DATA_GROUP],
        ]);
});

it('seeds the roles and the default matrix for a tenant', function () {
    $tenant = Tenant::factory()->create(); // the factory seeds its roles
    RoleSeeder::seedFor($tenant); // idempotent

    app(TenantContext::class)->run($tenant, function () {
        expect(RoleModel::query()->orderBy('name')->pluck('name')->all())->toBe([
            'admin_organisme', 'assistant', 'charge_relations', 'commercial', 'direction', 'formateur', 'responsable_das',
        ]);

        // Core permissions only (the modules' defaults are tested with PermissionSync).
        $matrix = RoleModel::query()->with('permissions')->get()
            ->mapWithKeys(fn (RoleModel $r) => [$r->name => $r->permissions->pluck('name')->reject(fn ($p) => str_starts_with($p, 'crm.') || str_starts_with($p, 'catalogue.') || str_starts_with($p, 'sessions.') || str_starts_with($p, 'finance.') || str_starts_with($p, 'admissions.') || str_starts_with($p, 'qualite.') || str_starts_with($p, 'documents.') || str_starts_with($p, 'questionnaires.'))->sort()->values()->all()]);

        expect($matrix['admin_organisme'])->toBe(collect(Permissions::all())->reject(fn ($p) => str_starts_with($p, 'crm.') || str_starts_with($p, 'catalogue.') || str_starts_with($p, 'sessions.') || str_starts_with($p, 'finance.') || str_starts_with($p, 'admissions.') || str_starts_with($p, 'qualite.') || str_starts_with($p, 'documents.') || str_starts_with($p, 'questionnaires.'))->sort()->values()->all())
            ->and($matrix['direction'])->toBe(['audit.view', 'exports.run'])
            ->and($matrix['commercial'])->toBe([])
            ->and($matrix['charge_relations'])->toBe([])
            ->and($matrix['assistant'])->toBe([])
            ->and($matrix['responsable_das'])->toBe([]);
    });
});

it('does not overwrite the matrix of an existing role when seeding again', function () {
    $tenant = tenant();
    RoleModel::findByName('direction')->revokePermissionTo('exports.run');

    RoleSeeder::seedFor($tenant);

    expect(RoleModel::findByName('direction')->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(['audit.view', 'crm.fiches.view', 'crm.opportunities.view', 'crm.quotes.view', 'crm.activities.view', 'crm.activities.team',
            'crm.revenue.view', 'crm.revenue.manage', 'crm.export', 'crm.gdpr', 'crm.dashboards.view', 'catalogue.view', 'catalogue.manage', 'catalogue.delete',
            'sessions.dossiers.view', 'sessions.sessions.view', 'sessions.seances.view', 'sessions.seances.manage', 'sessions.attendance.manage',
            'sessions.attendance.close', 'sessions.trainers.manage', 'sessions.rooms.manage',
            'finance.invoices.view', 'finance.invoices.manage', 'finance.invoices.validate', 'finance.payments.manage',
            'admissions.candidatures.view', 'admissions.sensitive.view', 'qualite.view', 'qualite.manage', 'documents.view', 'documents.manage', 'questionnaires.view', 'questionnaires.manage']);
});

// ---------------------------------------------------------------- authorization

it('assistant cannot manage users', fn () => $this->actingAs(assistant())->get('/admin/users')->assertForbidden());

it('an administrator manages users', function () {
    $this->actingAs(admin(twoFactor: true))->get('/admin/users')->assertOk();
});

it('direction can view the audit and run exports but not manage users', function () {
    $direction = direction(twoFactor: true);

    expect($direction->can('audit.view'))->toBeTrue()
        ->and($direction->can('exports.run'))->toBeTrue()
        ->and($direction->can('users.manage'))->toBeFalse()
        ->and($direction->can('settings.manage'))->toBeFalse();
});

it('refuses everything to a deactivated user, whatever the role', function () {
    $admin = admin(twoFactor: true);
    expect($admin->can('users.manage'))->toBeTrue();

    $admin->deactivate();

    expect($admin->can('users.manage'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('audit.view'))->toBeFalse();
});

// ---------------------------------------------------------------- primary role

it('gives each user one primary role and replaces it on assignment', function () {
    Event::fake([PrimaryRoleChanged::class]);
    $user = commercial();

    expect($user->role()?->systemRole())->toBe(Role::Commercial);
    Event::assertDispatched(PrimaryRoleChanged::class, fn ($e) => $e->user->is($user) && $e->previous === null && $e->role->systemRole() === Role::Commercial);

    $user->assignPrimaryRole(Role::Direction);

    expect($user->role()?->systemRole())->toBe(Role::Direction)
        ->and($user->fresh()->role()?->systemRole())->toBe(Role::Direction)
        ->and($user->roles()->count())->toBe(1);
    Event::assertDispatched(PrimaryRoleChanged::class, fn ($e) => $e->previous?->systemRole() === Role::Commercial && $e->role->systemRole() === Role::Direction);

    // Same role again: nothing changes, nothing to audit.
    $user->assignPrimaryRole(Role::Direction);
    Event::assertDispatchedTimes(PrimaryRoleChanged::class, 2);
});

it('a user without role has no role and no permission', function () {
    tenant();
    $user = User::factory()->create();

    expect($user->role())->toBeNull()
        ->and($user->can('users.manage'))->toBeFalse();
});

it('refuses to assign a role to a user of another tenant than the current one', function () {
    $other = Tenant::factory()->create();
    $user = userIn($other);
    tenant();

    $user->assignPrimaryRole(Role::AdminOrganisme);
})->throws(LogicException::class);

it('assigns the invited role on activation', function () {
    tenant();
    $invitation = Invitation::issue('nouveau@cap.test', 'Nouveau', RoleModel::forSystem(Role::ChargeRelations));

    $this->post("/activation/{$invitation->plainToken}", [
        'password' => 'un-mot-de-passe-solide',
        'password_confirmation' => 'un-mot-de-passe-solide',
    ])->assertRedirect('/login');

    expect(User::query()->where('email', 'nouveau@cap.test')->sole()->role()?->systemRole())->toBe(Role::ChargeRelations);
});

// ---------------------------------------------------------------- tenant isolation

it('roles are isolated per tenant', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $adminA = $context->run($a, fn () => User::factory()->withRole(Role::AdminOrganisme)->create());
    $commercialB = $context->run($b, fn () => User::factory()->withRole(Role::Commercial)->create());
    $adminB = $context->run($b, fn () => User::factory()->withRole(Role::AdminOrganisme)->create());

    // A's admin role grants users.manage in A (this also warms A's permission cache)…
    expect($context->run($a, fn () => $adminA->fresh()->can('users.manage')))->toBeTrue();

    $context->run($b, function () use ($a, $adminA, $commercialB, $adminB) {
        // …but nothing of A is visible from B: roles, assignments, matrix.
        expect(RoleModel::withoutGlobalScopes()->count())->toBe(count(Role::cases()))
            ->and(RoleModel::withoutGlobalScopes()->where('tenant_id', $a->id)->count())->toBe(0)
            ->and(DB::table('model_has_roles')->where('model_id', $adminA->id)->count())->toBe(0)
            ->and(DB::table('model_has_roles')->count())->toBe(2)
            ->and(DB::table('role_has_permissions')->count())->toBe(collect(Role::cases())->sum(fn (Role $r) => count(Permissions::defaultsFor($r))));

        // A's user (loaded in A) has no role here, hence no permission.
        $adminA->unsetRelation('roles')->unsetRelation('permissions');
        expect($adminA->roles()->count())->toBe(0)
            ->and($adminA->can('users.manage'))->toBeFalse();

        // B's own matrix applies (the permission cache is per tenant).
        expect($commercialB->can('users.manage'))->toBeFalse()
            ->and($adminB->can('users.manage'))->toBeTrue();
    });
});

it('cannot attach a role of another tenant', function () {
    $other = Tenant::factory()->create();
    $foreignRoleId = app(TenantContext::class)->run($other, fn () => RoleModel::findByName('admin_organisme')->id);
    $user = commercial();

    DB::table('model_has_roles')->insert([
        'role_id' => $foreignRoleId, 'model_type' => $user->getMorphClass(), 'model_id' => $user->id, 'tenant_id' => tenant()->id,
    ]);
})->throws(QueryException::class);

it('hides the roles and assignments without tenant context (RLS)', function () {
    commercial();
    app(TenantContext::class)->clear();

    expect(RoleModel::withoutGlobalScopes()->count())->toBe(0)
        ->and(DB::table('model_has_roles')->count())->toBe(0)
        ->and(DB::table('role_has_permissions')->count())->toBe(0);
});

it('keeps the permission team in step with the tenant context', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $context->set($a);
    expect(getPermissionsTeamId())->toBe($a->id);

    $context->run($b, fn () => expect(getPermissionsTeamId())->toBe($b->id));
    expect(getPermissionsTeamId())->toBe($a->id);

    $context->clear();
    expect(getPermissionsTeamId())->toBeNull();
});

it('opens Horizon to platform administrators only', function () {
    $admin = admin(twoFactor: true);
    $platform = User::factory()->create();
    $platform->forceFill(['is_platform_admin' => true])->save();

    expect(Gate::forUser($admin)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser($platform)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse();
});

it('reloads the permissions of the same organisme at each queued job (long-running worker)', function () {
    $user = assistant();
    inTenant();
    expect($user->can('crm.export'))->toBeFalse();

    // Granted by another process (the web): rows written and the shared cache dropped there,
    // while this worker keeps its in-memory copy (loaded by the check above).
    $role = RoleModel::query()->where('name', 'assistant')->sole();
    $permission = PermissionModel::query()->where('name', 'crm.export')->sole();
    DB::table('role_has_permissions')->insert(['role_id' => $role->id, 'permission_id' => $permission->id]);
    Cache::forget(config('permission.cache.key').'.'.tenant()->id);
    expect($user->fresh()->can('crm.export'))->toBeFalse(); // stale

    event(new JobProcessing('redis', Mockery::mock(Job::class, ['payload' => [QueueTenancy::PAYLOAD_KEY => tenant()->id], 'getConnectionName' => 'redis', 'getQueue' => 'default'])->shouldIgnoreMissing()));

    expect($user->fresh()->can('crm.export'))->toBeTrue();
});
