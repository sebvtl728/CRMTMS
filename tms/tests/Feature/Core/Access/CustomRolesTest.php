<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Permissions;
use App\Modules\Core\Access\PermissionSync;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\RoleCatalogue;
use App\Modules\Core\Access\RoleMatrix;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\Invitation;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Permissions as CrmPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @return array{permissions: list<string>, scopes: array<string, string>}
 */
function roleRights(RoleModel $role): array
{
    return [
        'permissions' => $role->permissions()->pluck('name')->sort()->values()->all(),
        'scopes' => DB::table('role_permission_scopes')->where('role_id', $role->getKey())->orderBy('permission')->pluck('scope', 'permission')->all(),
    ];
}

function customRole(string $label = 'Formateur', bool $requires2fa = false, ?RoleModel $copyFrom = null): RoleModel
{
    tenant();

    return app(RoleCatalogue::class)->create($label, $requires2fa, $copyFrom);
}

it('admin creates a role copied from commercial', function () {
    $this->actingAs(admin(twoFactor: true));
    $commercial = RoleModel::forSystem(Role::Commercial);
    app(RoleMatrix::class)->setScope($commercial, CrmPermissions::FICHES_VIEW, AccessScope::Own);

    $this->post('/admin/roles', ['label' => '  Formateur ', 'requires_2fa' => true, 'copy_from' => $commercial->id])
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('status', 'Rôle « Formateur » créé.');

    $role = RoleModel::query()->where('label', 'Formateur')->sole();
    expect($role->name)->toStartWith('custom_')
        ->and($role->system)->toBeFalse()
        ->and($role->requires2fa())->toBeTrue()
        ->and($role->label())->toBe('Formateur')
        ->and($role->systemRole())->toBeNull()
        ->and(roleRights($role))->toBe(roleRights($commercial))
        ->and(roleRights($role)['scopes'])->toHaveKey(CrmPermissions::FICHES_VIEW, 'own');

    $log = AuditLog::query()->where('event', 'roles.created')->sole();
    expect($log->new_values)->toMatchArray(['label' => 'Formateur', 'requires_2fa' => true, 'copied_from' => 'commercial']);
});

it('creates an empty role by default and orders the roles system first', function () {
    $role = customRole('Agent', true);
    customRole('Bénévole', false);

    expect(roleRights($role))->toBe(['permissions' => [], 'scopes' => []])
        ->and(RoleModel::query()->ordered()->get()->map->label()->all())->toBe([
            ...array_map(fn (Role $r) => $r->label(), Role::cases()), 'Agent', 'Bénévole',
        ]);
});

it('duplicate label refused ignoring case and accents', function () {
    customRole('Formateur');

    foreach (['formateur', 'Formatéur', ' FORMATEUR '] as $label) {
        expect(fn () => customRole($label))->toThrow(ValidationException::class, 'Un rôle porte déjà ce libellé.');
    }
    // System labels are taken too, and the length is checked.
    expect(fn () => customRole('commercial'))->toThrow(ValidationException::class, 'Un rôle porte déjà ce libellé.')
        ->and(fn () => customRole('A'))->toThrow(ValidationException::class)
        ->and(fn () => customRole(str_repeat('a', 81)))->toThrow(ValidationException::class);

    $other = customRole('Tuteur');
    expect(fn () => app(RoleCatalogue::class)->rename($other, 'FORMATÉUR'))->toThrow(ValidationException::class, 'Un rôle porte déjà ce libellé.');
    app(RoleCatalogue::class)->rename($other, 'Tuteurs');
    expect($other->fresh()->label())->toBe('Tuteurs')
        ->and(AuditLog::query()->where('event', 'roles.renamed')->sole()->new_values)->toBe(['label' => 'Tuteurs']);

    // Another organisme may use the same label.
    $tenant = Tenant::factory()->create();
    PermissionSync::forTenant($tenant);
    app(TenantContext::class)->run($tenant, fn () => app(RoleCatalogue::class)->create('Formateur', false));
    expect(app(TenantContext::class)->run($tenant, fn () => RoleModel::query()->where('label', 'Formateur')->count()))->toBe(1);
});

it('renames, changes 2fa and deletes a role over HTTP', function () {
    $this->actingAs(admin(twoFactor: true));
    $role = customRole('Formateur');

    $this->patch("/admin/roles/{$role->id}", ['label' => 'Formatrice'])->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Rôle renommé : Formatrice.');
    $this->patch("/admin/roles/{$role->id}", ['requires_2fa' => true])->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Double authentification obligatoire pour le rôle Formatrice.');
    expect($role->fresh()->requires2fa())->toBeTrue()
        ->and(AuditLog::query()->where('event', 'roles.2fa_changed')->sole()->new_values)->toBe(['requires_2fa' => true]);

    $this->patch("/admin/roles/{$role->id}", ['label' => 'x'])->assertSessionHasErrors('label');

    $this->delete("/admin/roles/{$role->id}")->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Rôle « Formatrice » supprimé.');
    expect(RoleModel::query()->whereKey($role->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('event', 'roles.deleted')->sole()->old_values)->toMatchArray(['label' => 'Formatrice']);
});

it('role held by a deactivated user cannot be deleted', function () {
    $role = customRole('Formateur');
    $user = User::factory()->create();
    $user->assignPrimaryRole($role);
    $user->deactivate();

    expect(fn () => app(RoleCatalogue::class)->delete($role))
        ->toThrow(ValidationException::class, 'Ce rôle est attribué à 1 utilisateur(s) ou invitation(s) : changez-leur de rôle avant de le supprimer.');

    $this->actingAs(admin(twoFactor: true))->deleteJson("/admin/roles/{$role->id}")->assertStatus(422)
        ->assertJsonValidationErrors(['role' => 'Ce rôle est attribué à 1 utilisateur(s) ou invitation(s) : changez-leur de rôle avant de le supprimer.']);
    expect(RoleModel::query()->whereKey($role->id)->exists())->toBeTrue();
});

it('role targeted by a pending invitation cannot be deleted', function () {
    $role = customRole('Formateur');
    Invitation::issue('a@cap.test', 'A', $role);
    Invitation::issue('b@cap.test', 'B', $role);

    expect(fn () => app(RoleCatalogue::class)->delete($role))
        ->toThrow(ValidationException::class, 'Ce rôle est attribué à 2 utilisateur(s) ou invitation(s) : changez-leur de rôle avant de le supprimer.');
});

it('expired invitation does not block', function () {
    $role = customRole('Formateur');
    Invitation::issue('a@cap.test', 'A', $role);
    $this->travel(Invitation::VALIDITY_HOURS + 1)->hours();

    app(RoleCatalogue::class)->delete($role);

    expect(RoleModel::query()->whereKey($role->id)->exists())->toBeFalse();
});

it('system roles cannot be deleted nor have 2fa removed for admin and direction', function () {
    $this->actingAs(admin(twoFactor: true));
    $catalogue = app(RoleCatalogue::class);

    foreach (Role::cases() as $system) {
        $model = RoleModel::forSystem($system);
        expect($model->system)->toBeTrue()
            ->and($model->requires2fa())->toBe($system->requires2fa());
        $this->delete("/admin/roles/{$model->id}")->assertForbidden();
        expect(fn () => $catalogue->delete($model))->toThrow(HttpException::class);
    }

    foreach ([Role::AdminOrganisme, Role::Direction] as $system) {
        $model = RoleModel::forSystem($system);
        $this->patch("/admin/roles/{$model->id}", ['requires_2fa' => false])->assertForbidden();
        expect($model->fresh()->requires2fa())->toBeTrue();
    }
    // Even with the column cleared by hand, 2FA stays mandatory.
    DB::table('roles')->whereIn('name', ['admin_organisme', 'direction'])->update(['requires_2fa' => false]);
    expect(RoleModel::forSystem(Role::Direction)->requires2fa())->toBeTrue()
        ->and(RoleModel::forSystem(Role::AdminOrganisme)->requires2fa())->toBeTrue();

    // The administrator's role is untouchable; the other system roles may be renamed or set to 2FA.
    $admin = RoleModel::forSystem(Role::AdminOrganisme);
    $this->patch("/admin/roles/{$admin->id}", ['label' => 'Patron'])->assertForbidden();
    expect($admin->fresh()->label())->toBe(Role::AdminOrganisme->label());

    $assistant = RoleModel::forSystem(Role::Assistant);
    $this->patch("/admin/roles/{$assistant->id}", ['requires_2fa' => true, 'label' => 'Assistante'])->assertSessionHasNoErrors();
    expect($assistant->fresh()->requires2fa())->toBeTrue()
        ->and($assistant->fresh()->label())->toBe('Assistante');
});

it('locked permissions never granted to a custom role', function () {
    tenant();
    $copy = customRole('Copie admin', true, RoleModel::forSystem(Role::AdminOrganisme));
    $granted = roleRights($copy)['permissions'];

    expect($granted)->not->toBeEmpty();
    foreach (Permissions::LOCKED as $locked) {
        expect($granted)->not->toContain($locked);
        expect(fn () => app(RoleMatrix::class)->set($copy, $locked, true))->toThrow(HttpException::class);
    }

    $this->actingAs(admin(twoFactor: true));
    $this->post("/admin/roles/{$copy->id}", ['permission' => Permissions::USERS_MANAGE, 'granted' => true])->assertForbidden();
    expect($copy->permissions()->pluck('name'))->not->toContain(Permissions::USERS_MANAGE);
});

it('edits the matrix of a custom role and resets it to no right', function () {
    $this->actingAs(admin(twoFactor: true));
    $role = customRole('Formateur');

    $this->post("/admin/roles/{$role->id}", ['permission' => CrmPermissions::FICHES_VIEW, 'granted' => true])
        ->assertSessionHas('status', 'Droit « '.Permissions::label(CrmPermissions::FICHES_VIEW)['label'].' » accordé au rôle Formateur.');
    expect(roleRights($role)['permissions'])->toBe([CrmPermissions::FICHES_VIEW]);
    expect(RoleMatrix::editable($role))->toBeTrue()
        ->and(RoleMatrix::editable(RoleModel::forSystem(Role::AdminOrganisme)))->toBeFalse();

    $this->post("/admin/roles/{$role->id}/reset")->assertSessionHasNoErrors();
    expect(roleRights($role))->toBe(['permissions' => [], 'scopes' => []]);

    // PermissionSync never gives a custom role the defaults.
    PermissionSync::run();
    expect(roleRights($role)['permissions'])->toBe([]);
});

it('turning 2fa on forces enrolment at next request', function () {
    $role = customRole('Formateur', false);
    $user = User::factory()->create();
    $user->assignPrimaryRole($role);

    $this->actingAs($user)->get('/dashboard')->assertOk();

    app(RoleCatalogue::class)->setRequires2fa($role, true);

    $this->actingAs($user)->get('/dashboard')->assertRedirect('/two-factor/setup');
});

it('invitation to a custom role activates with that role', function () {
    $this->actingAs(admin(twoFactor: true));
    $role = customRole('Formateur');

    $this->get('/admin/users/invite')->assertInertia(fn (Assert $page) => $page
        ->where('roles', fn ($roles) => collect($roles)->contains(fn ($o) => $o['value'] === $role->name && $o['label'] === 'Formateur')));
    $this->post('/admin/users/invitations', ['email' => 'nouveau@cap.test', 'name' => 'Nouveau', 'role' => $role->name])
        ->assertSessionHasNoErrors();
    $invitation = Invitation::query()->pending()->where('email', 'nouveau@cap.test')->sole();
    expect($invitation->role)->toBe($role->name);

    $this->get('/admin/users')->assertInertia(fn (Assert $page) => $page
        ->where('invitations.0.role', 'Formateur'));

    $plain = Invitation::issue('nouveau@cap.test', 'Nouveau', $role)->plainToken;
    auth()->logout();
    $this->post("/activation/{$plain}", ['password' => 'un-mot-de-passe-solide', 'password_confirmation' => 'un-mot-de-passe-solide'])
        ->assertRedirect('/login');

    expect(User::query()->where('email', 'nouveau@cap.test')->sole()->role()?->is($role))->toBeTrue();
});

it('invitation whose role was deleted is refused', function () {
    $role = customRole('Formateur');
    $plain = Invitation::issue('nouveau@cap.test', 'Nouveau', $role)->plainToken;
    // Removed behind the catalogue's back (a deletion racing the invitation).
    RoleModel::query()->whereKey($role->id)->delete();

    $this->get("/activation/{$plain}")->assertStatus(410)->assertSee('Ce rôle n&#039;existe plus. Demandez une nouvelle invitation.', false);
    $this->post("/activation/{$plain}", ['password' => 'un-mot-de-passe-solide', 'password_confirmation' => 'un-mot-de-passe-solide'])
        ->assertStatus(410);

    expect(User::query()->where('email', 'nouveau@cap.test')->exists())->toBeFalse();
});

it('role of another tenant is 404', function () {
    $tenant = Tenant::factory()->create();
    PermissionSync::forTenant($tenant);
    $theirs = app(TenantContext::class)->run($tenant, fn () => app(RoleCatalogue::class)->create('Formateur', false));

    $this->actingAs(admin(twoFactor: true));
    $this->patch("/admin/roles/{$theirs->id}", ['label' => 'Pirate'])->assertNotFound();
    $this->delete("/admin/roles/{$theirs->id}")->assertNotFound();
    $this->post("/admin/roles/{$theirs->id}", ['permission' => CrmPermissions::FICHES_VIEW, 'granted' => true])->assertNotFound();
    $this->post("/admin/roles/{$theirs->id}/reset")->assertNotFound();
    $this->post('/admin/roles', ['label' => 'Copie', 'requires_2fa' => false, 'copy_from' => $theirs->id])->assertSessionHasErrors('copy_from');
    $this->patch('/admin/roles/inconnu', ['label' => 'X'])->assertNotFound();

    expect(app(TenantContext::class)->run($tenant, fn () => $theirs->fresh()->label()))->toBe('Formateur');
});

it('users page lists custom roles', function () {
    $this->actingAs(admin(twoFactor: true));
    $role = customRole('Formateur');
    $user = User::factory()->create(['name' => 'Zoé Formatrice']);
    $user->assignPrimaryRole($role);

    $this->get('/admin/users')->assertInertia(fn (Assert $page) => $page
        ->where('roleOptions', fn ($options) => collect($options)->pluck('label')->all() === [...array_map(fn (Role $r) => $r->label(), Role::cases()), 'Formateur']
            && collect($options)->last()['value'] === $role->name)
        ->where('users', fn ($users) => collect($users)->firstWhere('name', 'Zoé Formatrice')['role'] === $role->name));

    $this->get('/settings/profile')->assertInertia(fn (Assert $page) => $page->where('auth.user.role', Role::AdminOrganisme->label()));
    $this->actingAs($user)->get('/settings/profile')->assertInertia(fn (Assert $page) => $page->where('auth.user.role', 'Formateur'));
});

it('updateRole accepts a custom role of the organisme', function () {
    $this->actingAs(admin(twoFactor: true));
    $role = customRole('Formateur');
    $user = commercial();

    $this->patch("/admin/users/{$user->id}/role", ['role' => $role->name])->assertSessionHasNoErrors()
        ->assertSessionHas('status', "Rôle de {$user->name} modifié : Formateur.");
    expect($user->fresh()->role()?->is($role))->toBeTrue()
        ->and(AuditLog::query()->where('event', 'access.role_changed')->latest('id')->first()->new_values)->toBe(['role' => $role->name]);

    $tenant = Tenant::factory()->create();
    PermissionSync::forTenant($tenant);
    $theirs = app(TenantContext::class)->run($tenant, fn () => app(RoleCatalogue::class)->create('Formateur', false));
    $this->patch("/admin/users/{$user->id}/role", ['role' => $theirs->name])->assertSessionHasErrors('role');
    $this->patch("/admin/users/{$user->id}/role", ['role' => 'inconnu'])->assertSessionHasErrors('role');
    expect($user->fresh()->role()?->is($role))->toBeTrue();
});

it('empties the followed DAS when the matrix of their role drops « Ses DAS »', function () {
    $role = customRole('Suivi DAS');
    $matrix = app(RoleMatrix::class);
    $matrix->set($role, CrmPermissions::FICHES_VIEW, true);
    $matrix->setScope($role, CrmPermissions::FICHES_VIEW, AccessScope::Das);
    $matrix->set($role, CrmPermissions::OPPORTUNITIES_VIEW, true);
    $matrix->setScope($role, CrmPermissions::OPPORTUNITIES_VIEW, AccessScope::Das);
    $user = User::factory()->create();
    $user->assignPrimaryRole($role);
    CapFormationReferenceSeeder::run(tenant());
    $das = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    followDas($user, $das);

    // Still « Ses DAS » on the opportunities: kept.
    $matrix->setScope($role, CrmPermissions::FICHES_VIEW, AccessScope::All);
    expect(DB::table('user_das')->where('user_id', $user->id)->count())->toBe(1);

    // No right left with « Ses DAS »: emptied, audited.
    $matrix->set($role, CrmPermissions::OPPORTUNITIES_VIEW, false);
    expect(DB::table('user_das')->where('user_id', $user->id)->count())->toBe(0);
    $log = AuditLog::query()->where('event', 'users.das_changed')->sole();
    expect($log->old_values)->toBe(['das_ids' => [$das->id]])->and($log->new_values)->toBe(['das_ids' => []]);

    // A system role reset to its defaults without « Ses DAS » too.
    $responsable = RoleModel::forSystem(Role::ResponsableDas);
    $other = User::factory()->create();
    $other->assignPrimaryRole($responsable);
    followDas($other, $das);
    foreach (CrmPermissions::scoped() as $permission) {
        if ($responsable->permissions()->where('name', $permission)->exists()) {
            $matrix->setScope($responsable, $permission, AccessScope::All);
        }
    }
    expect(DB::table('user_das')->where('user_id', $other->id)->count())->toBe(0);
    expect(Scopes::roleUses($responsable, AccessScope::Das))->toBeFalse();
});
