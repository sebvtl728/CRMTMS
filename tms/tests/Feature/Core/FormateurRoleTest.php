<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Access\PermissionSync;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\RoleCatalogue;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsServiceProvider;
use App\Modules\Sessions\SessionsVisibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/../Sessions/Support/lot2a.php';

beforeEach(function () {
    inTenant();
});

it('declares the planning rights of the Sessions module', function () {
    expect([Permissions::SEANCES_VIEW, Permissions::SEANCES_MANAGE, Permissions::ATTENDANCE_MANAGE, Permissions::ATTENDANCE_CLOSE, Permissions::TRAINERS_MANAGE, Permissions::ROOMS_MANAGE])
        ->toBe(['sessions.seances.view', 'sessions.seances.manage', 'sessions.attendance.manage', 'sessions.attendance.close', 'sessions.trainers.manage', 'sessions.rooms.manage'])
        ->and(CorePermissions::all())->toContain(Permissions::SEANCES_VIEW, Permissions::ROOMS_MANAGE);

    expect(CorePermissions::scopesFor(Permissions::SEANCES_VIEW))->toBe([AccessScope::Own, AccessScope::Das, AccessScope::All, AccessScope::Trainer])
        ->and(CorePermissions::scopesFor(Permissions::ATTENDANCE_MANAGE))->toContain(AccessScope::Trainer)
        ->and(CorePermissions::scopesFor(Permissions::SEANCES_MANAGE))->toBe([AccessScope::Own, AccessScope::Das, AccessScope::All])
        ->and(CorePermissions::scopesFor(Permissions::SESSIONS_VIEW))->not->toContain(AccessScope::Trainer)
        ->and(CorePermissions::scopesFor('crm.fiches.view'))->not->toContain(AccessScope::Trainer)
        ->and(CorePermissions::isScoped(Permissions::TRAINERS_MANAGE))->toBeTrue()
        ->and(CorePermissions::isScoped(Permissions::ROOMS_MANAGE))->toBeFalse()
        ->and(AccessScope::Trainer->value)->toBe('trainer')
        ->and(AccessScope::Trainer->label())->toBe('Ses séances');
});

it('creates the Formateur system role in every organisme, 2FA off by default, never deleted', function () {
    $other = Tenant::factory()->create();

    foreach ([tenant(), $other] as $tenant) {
        app(TenantContext::class)->run($tenant, function (): void {
            $role = RoleModel::forSystem(Role::Formateur);
            expect($role->system)->toBeTrue()
                ->and($role->label())->toBe('Formateur')
                ->and($role->requires2fa())->toBeFalse();
        });
    }
    inTenant();

    expect(fn () => app(RoleCatalogue::class)->delete(RoleModel::forSystem(Role::Formateur)))->toThrow(Exception::class);
    expect(RoleModel::forSystem(Role::Formateur)->exists)->toBeTrue();

    app(RoleCatalogue::class)->setRequires2fa(RoleModel::forSystem(Role::Formateur), true);
    expect(RoleModel::forSystem(Role::Formateur)->requires2fa())->toBeTrue()
        ->and(RoleModel::forSystem(Role::Direction)->requires2fa())->toBeTrue()
        ->and(RoleModel::forSystem(Role::AdminOrganisme)->requires2fa())->toBeTrue();
});

it('gives the Formateur his séances and their attendance only, on « Ses séances »', function () {
    $formateur = userWithRole(Role::Formateur);
    $granted = RoleModel::forSystem(Role::Formateur)->permissions()->pluck('name')->sort()->values()->all();

    // Lot 1e: his own actions too (the follow-up calls), on « Ses fiches ».
    expect($granted)->toBe(['admissions.booklet.manage', 'crm.activities.update', 'crm.activities.view', 'sessions.attendance.manage', 'sessions.seances.view'])
        ->and(Scopes::of($formateur, Permissions::SEANCES_VIEW))->toBe(AccessScope::Trainer)
        ->and(Scopes::of($formateur, Permissions::ATTENDANCE_MANAGE))->toBe(AccessScope::Trainer);

    foreach (CorePermissions::all() as $permission) {
        if ((str_starts_with($permission, 'crm.') && ! in_array($permission, Scopes::TRAINER_OWN_EXTRAS, true)) || str_starts_with($permission, 'catalogue.') || $permission === CorePermissions::EXPORTS_RUN
            || str_starts_with($permission, 'sessions.dossiers.') || str_starts_with($permission, 'sessions.sessions.')) {
            expect($formateur->can($permission), $permission)->toBeFalse();
        }
    }
});

it('grants the planning rights per role', function () {
    $planning = [Permissions::SEANCES_VIEW, Permissions::SEANCES_MANAGE, Permissions::ATTENDANCE_MANAGE, Permissions::ATTENDANCE_CLOSE, Permissions::TRAINERS_MANAGE, Permissions::ROOMS_MANAGE];
    $managers = array_values(array_diff($planning, [Permissions::ATTENDANCE_CLOSE]));
    $only = fn (Role $role) => array_values(array_intersect(Permissions::defaultsFor($role), $planning));

    expect($only(Role::AdminOrganisme))->toEqualCanonicalizing($planning)
        ->and($only(Role::Direction))->toEqualCanonicalizing($planning)
        ->and($only(Role::Commercial))->toEqualCanonicalizing($managers)
        ->and($only(Role::ChargeRelations))->toEqualCanonicalizing($managers)
        ->and($only(Role::Assistant))->toEqualCanonicalizing($managers)
        ->and($only(Role::ResponsableDas))->toEqualCanonicalizing([Permissions::SEANCES_VIEW, Permissions::TRAINERS_MANAGE])
        ->and($only(Role::Formateur))->toEqualCanonicalizing([Permissions::SEANCES_VIEW, Permissions::ATTENDANCE_MANAGE]);

    expect(Scopes::of(userWithRole(Role::ResponsableDas), Permissions::SEANCES_VIEW))->toBe(AccessScope::Das)
        ->and(Scopes::of(userWithRole(Role::ResponsableDas), Permissions::TRAINERS_MANAGE))->toBe(AccessScope::Das)
        ->and(Scopes::of(userWithRole(Role::Assistant), Permissions::SEANCES_MANAGE))->toBe(AccessScope::All);
});

it('never applies « Ses séances » to another right', function () {
    $user = userWithPermissions([Permissions::SESSIONS_VIEW, Permissions::SEANCES_VIEW]);
    $role = $user->roles()->firstOrFail();
    DB::table(Scopes::TABLE)->insert([
        ['tenant_id' => tenant()->id, 'role_id' => $role->id, 'permission' => Permissions::SESSIONS_VIEW, 'scope' => 'trainer'],
        ['tenant_id' => tenant()->id, 'role_id' => $role->id, 'permission' => Permissions::SEANCES_VIEW, 'scope' => 'trainer'],
    ]);
    Scopes::forget();

    expect(Scopes::of($user, Permissions::SESSIONS_VIEW))->toBe(AccessScope::Own)
        ->and(Scopes::of($user, Permissions::SEANCES_VIEW))->toBe(AccessScope::Trainer);
});

it('offers « Ses séances » on the « Rôles et droits » screen for the two planned rights only', function () {
    $admin = admin(twoFactor: true);
    $this->actingAs($admin)->get('/admin/roles')->assertInertia(fn (Assert $page) => $page
        ->where('scopeOptions', fn ($options) => collect($options)->pluck('value')->all() === ['own', 'das', 'all', 'trainer'])
        ->where('groups', function ($groups): bool {
            $rows = collect($groups)->flatMap(fn ($g) => $g['permissions'])->keyBy('name');
            $withTrainer = $rows->filter(fn ($p) => in_array('trainer', $p['scope_options'], true))->keys()->sort()->values()->all();

            return $withTrainer === ['admissions.booklet.manage', 'sessions.attendance.manage', 'sessions.seances.view']
                && $rows['sessions.sessions.view']['scope_options'] === ['own', 'das', 'all']
                && $rows['sessions.trainers.manage']['scope_options'] === ['own', 'das', 'all'] && $rows['sessions.rooms.manage']['scope_options'] === [];
        }));

    $assistant = RoleModel::forSystem(Role::Assistant);
    $this->actingAs($admin)->post("/admin/roles/{$assistant->id}", ['permission' => Permissions::SESSIONS_VIEW, 'scope' => 'trainer'])
        ->assertStatus(422);
    $this->actingAs($admin)->post("/admin/roles/{$assistant->id}", ['permission' => Permissions::SEANCES_VIEW, 'scope' => 'trainer'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table(Scopes::TABLE)->where('role_id', $assistant->id)->pluck('scope', 'permission')->all())
        ->toMatchArray([Permissions::SEANCES_VIEW => 'trainer'])
        ->not->toHaveKey(Permissions::SESSIONS_VIEW);
});

it('denies a Formateur the CRM, dossiers, enrolments, catalogue, exports and the sessions without a séance of his (Review Focus 4)', function () {
    CrmServiceProvider::registerReferenceUsage();
    SessionsServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $session = l2Session();
    $organisation = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $dossier = l2Dossier($organisation);
    $enrolment = l2Enrol($dossier, $session, makePerson('Stagiaire'));
    $formateur = userWithRole(Role::Formateur);

    $this->actingAs($formateur)->get("/sessions/{$session->id}")->assertNotFound();
    $this->actingAs($formateur)->get("/dossiers/{$dossier->id}")->assertNotFound();
    $this->actingAs($formateur)->put("/enrolments/{$enrolment->id}", ['planned_hours' => '1'])->assertNotFound();
    // No CRM right at all: refused before any fiche is looked up.
    $this->actingAs($formateur)->get("/crm/organisations/{$organisation->id}")->assertForbidden();
    $this->actingAs($formateur)->get('/crm/organisations')->assertForbidden();
    $this->actingAs($formateur)->get('/crm/people')->assertForbidden();
    $this->actingAs($formateur)->get('/crm/quotes')->assertForbidden();
    $this->actingAs($formateur)->get('/sessions')->assertForbidden();
    $this->actingAs($formateur)->get('/dossiers')->assertForbidden();
    $this->actingAs($formateur)->get('/catalogue')->assertForbidden();
    $this->actingAs($formateur)->get('/dossiers/export')->assertForbidden();
    $this->actingAs($formateur)->get('/trainers')->assertForbidden();
    $this->actingAs($formateur)->get('/admin/sessions-settings')->assertForbidden();

    // « Ses séances »: a session with no séance of his is out of reach (the séances come with task 3).
    expect(SessionsVisibility::sessions(TrainingSession::query(), $formateur, Permissions::SEANCES_VIEW)->count())->toBe(0)
        ->and(SessionsVisibility::allows($formateur, Permissions::SEANCES_VIEW, $session))->toBeFalse()
        ->and(SessionsVisibility::sessions(TrainingSession::query(), $formateur)->count())->toBe(0);
});

it('reduces the navigation of a trainer-only user to « Mon planning »', function () {
    $formateur = userWithRole(Role::Formateur);

    $this->actingAs($formateur)->get('/dashboard')->assertRedirect('/mon-planning');
    $this->actingAs($formateur)->get('/mon-planning')->assertInertia(fn (Assert $page) => $page
        ->where('auth.trainer_only', true)
        ->where('auth.permissions', fn ($held) => collect($held)->sort()->values()->all() === ['admissions.booklet.manage', 'crm.activities.update', 'crm.activities.view', Permissions::ATTENDANCE_MANAGE, Permissions::SEANCES_VIEW]));

    $this->actingAs(commercial())->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.trainer_only', false));
});

/**
 * Rights of the planning (séances, attendance, trainers, rooms) per system role of $tenant.
 *
 * @return array<string, list<string>>
 */
function planningMatrix(Tenant $tenant): array
{
    return app(TenantContext::class)->run($tenant, function (): array {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return RoleModel::query()->where('system', true)->with('permissions')->get()
            ->mapWithKeys(fn (RoleModel $r) => [$r->name => $r->permissions->pluck('name')
                ->filter(fn (string $p) => preg_match('/^sessions\.(seances|attendance|trainers|rooms)\./', $p) === 1)->sort()->values()->all()])
            ->sortKeys()->all();
    });
}

it('creates the Formateur role and offers the planning rights to an existing organisme once, on upgrade', function () {
    $tenant = Tenant::factory()->create();
    $fresh = planningMatrix($tenant);
    $planning = '/^sessions\.(seances|attendance|trainers|rooms)\./';
    // As before 0.8.0: no Formateur role, no planning right, scope or offer recorded.
    app(TenantContext::class)->run($tenant, function () use ($planning): void {
        RoleModel::forSystem(Role::Formateur)->delete();
        foreach (RoleModel::all() as $role) {
            $role->revokePermissionTo($role->permissions->pluck('name')->filter(fn (string $p) => preg_match($planning, $p) === 1)->values()->all());
        }
        DB::table(Scopes::TABLE)->where('permission', 'like', 'sessions.seances.%')->orWhere('permission', 'like', 'sessions.attendance.%')->delete();
        DB::table(PermissionSync::TABLE)->where('role', 'formateur')
            ->orWhere(fn ($q) => $q->where('permission', 'like', 'sessions.seances.%')->orWhere('permission', 'like', 'sessions.attendance.%')
                ->orWhere('permission', 'like', 'sessions.trainers.%')->orWhere('permission', 'like', 'sessions.rooms.%'))->delete();
    });
    expect(planningMatrix($tenant))->not->toHaveKey('formateur');

    PermissionSync::run();
    expect(planningMatrix($tenant))->toBe($fresh)
        ->and($fresh['formateur'])->toBe(['sessions.attendance.manage', 'sessions.seances.view'])
        ->and(app(TenantContext::class)->run($tenant, fn () => DB::table(Scopes::TABLE)->where('role_id', RoleModel::forSystem(Role::Formateur)->id)->pluck('scope', 'permission')->sortKeys()->all()))
        ->toBe(['admissions.booklet.manage' => 'trainer', 'crm.activities.update' => 'own', 'crm.activities.view' => 'own', 'sessions.attendance.manage' => 'trainer', 'sessions.seances.view' => 'trainer']);

    // A right an administrator revoked stays revoked on the next sync.
    app(TenantContext::class)->run($tenant, fn () => RoleModel::forSystem(Role::Commercial)->revokePermissionTo(Permissions::ROOMS_MANAGE));
    PermissionSync::run();
    expect(planningMatrix($tenant)['commercial'])->not->toContain(Permissions::ROOMS_MANAGE);
});

it('refuses a custom role labelled like the Formateur system role', function () {
    expect(fn () => app(RoleCatalogue::class)->create('formateur', false))->toThrow(ValidationException::class);
});
