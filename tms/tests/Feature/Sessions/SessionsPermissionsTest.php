<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\Scopes;
use App\Modules\Sessions\Permissions;
use Inertia\Testing\AssertableInertia as Assert;

it('declares the six scoped rights of the Sessions module', function () {
    expect(Permissions::all())->toBe([
        'sessions.dossiers.view', 'sessions.dossiers.update', 'sessions.dossiers.delete',
        'sessions.sessions.view', 'sessions.sessions.update', 'sessions.sessions.delete',
    ])
        ->and(Permissions::DOSSIERS_VIEW)->toBe('sessions.dossiers.view')
        ->and(Permissions::SESSIONS_VIEW)->toBe('sessions.sessions.view')
        ->and(CorePermissions::all())->toContain(...Permissions::all());

    foreach (Permissions::all() as $permission) {
        $label = CorePermissions::label($permission);
        expect(CorePermissions::isScoped($permission), $permission)->toBeTrue()
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

    expect(Permissions::defaultsFor(Role::AdminOrganisme))->toEqualCanonicalizing(Permissions::all())
        ->and(Permissions::defaultsFor(Role::Direction))->toEqualCanonicalizing($view)
        ->and(Permissions::defaultsFor(Role::Commercial))->toEqualCanonicalizing($viewUpdate)
        ->and(Permissions::defaultsFor(Role::ChargeRelations))->toEqualCanonicalizing($viewUpdate)
        ->and(Permissions::defaultsFor(Role::Assistant))->toEqualCanonicalizing($viewUpdate)
        ->and(Permissions::defaultsFor(Role::ResponsableDas))->toEqualCanonicalizing($view);

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
