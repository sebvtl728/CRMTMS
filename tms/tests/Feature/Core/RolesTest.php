<?php

use App\Modules\Core\Access\Permissions;
use App\Modules\Core\Access\PermissionSync;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return list<string> sorted permissions of a role in the current tenant
 */
function rolePermissions(Role $role): array
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return RoleModel::query()->where('name', $role->value)->sole()->permissions()->pluck('name')->sort()->values()->all();
}

function roleId(Role $role): string
{
    return RoleModel::forSystem($role)->id;
}

it('gives every permission a French label and a theme', function () {
    foreach (Permissions::all() as $permission) {
        $label = Permissions::label($permission);
        expect($label, $permission)->not->toBeNull()
            ->and($label['label'] ?? '', $permission)->not->toBe('')
            ->and($label['description'] ?? '', $permission)->not->toBe('')
            ->and($label['group'] ?? '', $permission)->not->toBe('');
    }
});

it('grants roles.manage to the administrator only', function () {
    expect(Permissions::all())->toContain(Permissions::ROLES_MANAGE)
        ->and(Permissions::defaultsFor(Role::AdminOrganisme))->toContain(Permissions::ROLES_MANAGE);
    foreach (Role::cases() as $role) {
        if ($role !== Role::AdminOrganisme) {
            expect(Permissions::defaultsFor($role))->not->toContain(Permissions::ROLES_MANAGE);
        }
    }

    $this->actingAs(direction(twoFactor: true))->get('/admin/roles')->assertForbidden();
});

it('shows the matrix with the default of each cell', function () {
    $this->actingAs(admin(twoFactor: true));

    $this->get('/admin/roles')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/roles/index')
        ->where('roles.0.admin', true)
        ->where('roles.0.locked_2fa', true)
        ->where('roles.1.label', Role::Direction->label())
        ->where('roles.1.admin', false)
        ->where('roles.1.permissions', fn ($granted) => collect($granted)->sort()->values()->all() === collect(Permissions::defaultsFor(Role::Direction))->sort()->values()->all())
        ->where('roles.1.defaults', fn ($d) => collect($d)->sort()->values()->all() === collect(Permissions::defaultsFor(Role::Direction))->sort()->values()->all())
        ->where('groups', fn ($groups) => collect($groups)->flatMap(fn ($g) => collect($g['permissions'])->pluck('name'))->sort()->values()->all() === collect(Permissions::all())->sort()->values()->all())
        ->where('groups', fn ($groups) => collect($groups)->flatMap(fn ($g) => $g['permissions'])->where('locked', true)->pluck('name')->sort()->values()->all() === collect(Permissions::LOCKED)->sort()->values()->all()));
});

it('grants and revokes a permission of a role, journaled, effective at the next request', function () {
    $this->actingAs(admin(twoFactor: true));
    $assistant = assistant();
    expect($assistant->can('crm.export'))->toBeFalse();

    $this->from('/admin/roles')->post('/admin/roles/'.roleId(Role::Assistant), ['permission' => 'crm.export', 'granted' => true])
        ->assertRedirect('/admin/roles')->assertSessionHas('status', 'Droit « Exporter vers Excel » accordé au rôle Assistant.');
    expect(rolePermissions(Role::Assistant))->toContain('crm.export');
    $log = AuditLog::query()->where('event', 'roles.permission_granted')->sole();
    expect($log->new_values)->toEqual(['role' => 'assistant', 'role_label' => 'Assistant', 'permission' => 'crm.export']);

    $this->actingAs($assistant->fresh())->get('/crm/organisations/export/check')->assertOk();

    $this->actingAs(admin(twoFactor: true));
    $this->post('/admin/roles/'.roleId(Role::Assistant), ['permission' => 'crm.quotes.update', 'granted' => false])->assertSessionHasNoErrors();
    expect(rolePermissions(Role::Assistant))->not->toContain('crm.quotes.update')
        ->and(AuditLog::query()->where('event', 'roles.permission_revoked')->sole()->new_values)->toEqual(['role' => 'assistant', 'role_label' => 'Assistant', 'permission' => 'crm.quotes.update']);
});

it('refuses to change the administrator, the locked permissions or an unknown one', function () {
    $this->actingAs(admin(twoFactor: true));

    $this->post('/admin/roles/'.roleId(Role::AdminOrganisme), ['permission' => 'crm.export', 'granted' => false])->assertForbidden();
    $this->post('/admin/roles/'.roleId(Role::AdminOrganisme).'/reset')->assertForbidden();
    $this->post('/admin/roles/'.roleId(Role::Direction), ['permission' => Permissions::USERS_MANAGE, 'granted' => true])->assertForbidden();
    $this->post('/admin/roles/'.roleId(Role::Direction), ['permission' => Permissions::ROLES_MANAGE, 'granted' => true])->assertForbidden();
    $this->post('/admin/roles/'.roleId(Role::Direction), ['permission' => 'nope.manage', 'granted' => true])->assertSessionHasErrors('permission');
    $this->post('/admin/roles/inconnu', ['permission' => 'crm.export', 'granted' => true])->assertNotFound();

    expect(rolePermissions(Role::AdminOrganisme))->toContain('crm.export')
        ->and(rolePermissions(Role::Direction))->not->toContain(Permissions::USERS_MANAGE);
});

it('resets a role to exactly its default permissions', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->post('/admin/roles/'.roleId(Role::Commercial), ['permission' => 'crm.fiches.delete', 'granted' => false]);
    $this->post('/admin/roles/'.roleId(Role::Commercial), ['permission' => 'catalogue.manage', 'granted' => true]);

    $this->post('/admin/roles/'.roleId(Role::Commercial).'/reset')->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Droits par défaut rétablis pour le rôle Commercial.');

    expect(rolePermissions(Role::Commercial))->toBe(collect(Permissions::defaultsFor(Role::Commercial))->sort()->values()->all())
        ->and(AuditLog::query()->where('event', 'roles.reset')->sole()->new_values)->toEqual(['role' => 'commercial', 'role_label' => 'Commercial']);
});

it('changes only the current organisme, and a later sync never grants a removed right again', function () {
    $this->actingAs(admin(twoFactor: true));
    $other = Tenant::factory()->create();
    PermissionSync::forTenant($other);

    $this->post('/admin/roles/'.roleId(Role::Commercial), ['permission' => 'crm.fiches.delete', 'granted' => false])->assertSessionHasNoErrors();
    PermissionSync::run();

    expect(rolePermissions(Role::Commercial))->not->toContain('crm.fiches.delete');
    $theirs = app(TenantContext::class)->run($other, fn () => rolePermissions(Role::Commercial));
    expect($theirs)->toContain('crm.fiches.delete');
});

it('never grants the connectors to another role (an SMTP relay would capture invitation links)', function () {
    $this->actingAs(admin(twoFactor: true));

    expect(Permissions::LOCKED)->toContain(Permissions::CONNECTORS_MANAGE);
    $this->post('/admin/roles/'.roleId(Role::Direction), ['permission' => Permissions::CONNECTORS_MANAGE, 'granted' => true])->assertForbidden();
    expect(rolePermissions(Role::Direction))->not->toContain(Permissions::CONNECTORS_MANAGE);
});

it('warns about the administration-level rights on the screen', function () {
    $this->actingAs(admin(twoFactor: true));

    $this->get('/admin/roles')->assertInertia(fn (Assert $page) => $page
        // Administration is the last theme.
        ->where('groups', fn ($groups) => collect($groups)->last()['label'] === 'Administration'
            && collect(collect($groups)->last()['permissions'])->whereNotNull('warning')->pluck('name')->sort()->values()->all() === ['exports.run', 'settings.manage']
            && collect($groups)->flatMap(fn ($g) => $g['permissions'])->whereNotNull('warning')->count() === 2
            && str_contains((string) collect(collect($groups)->last()['permissions'])->firstWhere('name', 'settings.manage')['description'], "modèles d'e-mails")));
});
