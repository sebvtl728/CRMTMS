<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Permissions;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\RoleCatalogue;
use App\Modules\Core\Access\RoleMatrix;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Permissions as CrmPermissions;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function screenRole(string $label = 'Tuteur', bool $requires2fa = false, ?RoleModel $copyFrom = null): RoleModel
{
    tenant();

    return app(RoleCatalogue::class)->create($label, $requires2fa, $copyFrom);
}

it('gives the screen the roles, system first then custom, with 2fa lock, users and scopes', function () {
    $this->actingAs(admin(twoFactor: true));
    $commercial = RoleModel::forSystem(Role::Commercial);
    app(RoleMatrix::class)->setScope($commercial, CrmPermissions::FICHES_VIEW, AccessScope::Own);
    $custom = screenRole('Tuteur', true, $commercial);
    $holder = User::factory()->create();
    $holder->assignPrimaryRole($custom);
    $holder->deactivate();

    $this->get('/admin/roles')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/roles/index')
        ->has('roles', count(Role::cases()) + 1)
        ->where('roles', function ($roles) use ($custom) {
            $roles = collect($roles);
            $system = $roles->take(count(Role::cases()));
            $byId = $roles->keyBy('id');
            $c = $byId[$custom->id];
            $scoped = collect(Permissions::all())->filter(fn ($p) => Permissions::isScoped($p))->values()->all();

            return $system->pluck('label')->all() === array_map(fn (Role $r) => $r->label(), Role::cases())
                && $roles->last()['id'] === $custom->id
                && $system->every(fn ($r) => $r['system'] === true)
                && $c['system'] === false && $c['admin'] === false
                && $c['label'] === 'Tuteur' && $c['requires_2fa'] === true && $c['locked_2fa'] === false
                && $c['users_count'] === 1
                && $roles->where('locked_2fa', true)->pluck('label')->sort()->values()->all() === [Role::AdminOrganisme->label(), Role::Direction->label()]
                && $roles->first()['admin'] === true
                && $roles->where('admin', true)->count() === 1
                && collect($c['scopes'])->keys()->sort()->values()->all() === collect($scoped)->sort()->values()->all()
                && $c['scopes'][CrmPermissions::FICHES_VIEW] === 'own'
                && $c['scopes'][CrmPermissions::OPPORTUNITIES_VIEW] === 'all'
                && in_array(CrmPermissions::FICHES_VIEW, $c['permissions'], true)
                && $byId[RoleModel::forSystem(Role::Assistant)->id]['users_count'] === 0;
        })
        ->where('scopeOptions', AccessScope::options())
        ->where('groups', function ($groups) {
            $all = collect($groups)->flatMap(fn ($g) => $g['permissions']);
            $view = $all->firstWhere('name', CrmPermissions::FICHES_VIEW);
            $locked = $all->firstWhere('name', Permissions::ROLES_MANAGE);

            return $view['scoped'] === true && $view['locked'] === false && $view['warning'] === null
                && $locked['locked'] === true && $locked['scoped'] === false
                && $all->every(fn ($p) => array_key_exists('description', $p) && array_key_exists('label', $p));
        }));
});

it('sets the scope of a granted scoped permission over HTTP', function () {
    $this->actingAs(admin(twoFactor: true));
    $role = screenRole('Tuteur', false, RoleModel::forSystem(Role::Assistant));

    $this->post("/admin/roles/{$role->id}", ['permission' => CrmPermissions::OPPORTUNITIES_VIEW, 'scope' => 'own'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Périmètre de « '.Permissions::label(CrmPermissions::OPPORTUNITIES_VIEW)['label'].' » pour le rôle Tuteur : Ses fiches.');

    expect(DB::table('role_permission_scopes')->where('role_id', $role->id)->where('permission', CrmPermissions::OPPORTUNITIES_VIEW)->value('scope'))->toBe('own')
        ->and(AuditLog::query()->where('event', 'roles.scope_changed')->sole()->new_values)->toMatchArray(['permission' => CrmPermissions::OPPORTUNITIES_VIEW, 'scope' => 'own']);
});

it('refuses a scope for an ungranted, unscoped or administrator permission, or an unknown scope', function () {
    $this->actingAs(admin(twoFactor: true));
    $role = screenRole('Tuteur', false, RoleModel::forSystem(Role::Assistant));
    $url = "/admin/roles/{$role->id}";

    // Not granted (Assistant has no delete): 422 with the server's message.
    $this->postJson($url, ['permission' => CrmPermissions::FICHES_DELETE, 'scope' => 'own'])->assertStatus(422);
    // Not a scoped permission.
    $this->post($url, ['permission' => Permissions::SETTINGS_MANAGE, 'scope' => 'own'])->assertForbidden();
    $this->postJson($url, ['permission' => CrmPermissions::OPPORTUNITIES_VIEW, 'scope' => 'everything'])->assertStatus(422)->assertJsonValidationErrors('scope');
    $this->post('/admin/roles/'.RoleModel::forSystem(Role::AdminOrganisme)->id, ['permission' => CrmPermissions::OPPORTUNITIES_VIEW, 'scope' => 'own'])->assertForbidden();
    // Neither granted nor scope.
    $this->postJson($url, ['permission' => CrmPermissions::OPPORTUNITIES_VIEW])->assertStatus(422);
});

it('gives the default scopes of a system role so that a changed scope is highlighted', function () {
    $this->actingAs(admin(twoFactor: true));
    $commercial = RoleModel::forSystem(Role::Commercial);
    app(RoleMatrix::class)->setScope($commercial, CrmPermissions::FICHES_VIEW, AccessScope::Own);
    $custom = screenRole('Tuteur', false, $commercial);

    $this->get('/admin/roles')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('roles', function ($roles) use ($commercial, $custom) {
            $byId = collect($roles)->keyBy('id');
            $c = $byId[$commercial->id];
            $das = $byId[RoleModel::forSystem(Role::ResponsableDas)->id];

            return $c['default_scopes'][CrmPermissions::FICHES_VIEW] === 'all'
                && $c['scopes'][CrmPermissions::FICHES_VIEW] === 'own'
                && $c['default_scopes'][CrmPermissions::REVENUE_VIEW] === 'own'
                && $das['default_scopes'][CrmPermissions::FICHES_VIEW] === 'das'
                && $byId[$custom->id]['default_scopes'] === [];
        }));
});

it('warns when a role without mandatory 2fa holds a sensitive right', function () {
    $this->actingAs(admin(twoFactor: true));
    $plain = screenRole('Tuteur', false);
    $sensitive = screenRole('Auditeur', false);
    app(RoleMatrix::class)->set($sensitive, Permissions::AUDIT_VIEW, true);
    $protected = screenRole('Contrôleur', true);
    app(RoleMatrix::class)->set($protected, Permissions::EXPORTS_RUN, true);

    $this->get('/admin/roles')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('roles', function ($roles) use ($plain, $sensitive, $protected) {
            $byId = collect($roles)->keyBy('id');

            return $byId[$plain->id]['two_factor_warning'] === null
                && $byId[$protected->id]['two_factor_warning'] === null
                && $byId[$sensitive->id]['two_factor_warning'] === "Ce rôle détient un droit sensible (Consulter le journal d'audit) sans double authentification obligatoire : activez-la."
                // Administrator and direction always have it.
                && $byId[RoleModel::forSystem(Role::Direction)->id]['two_factor_warning'] === null;
        }));
});
