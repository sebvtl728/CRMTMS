<?php

use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Quotes\Quote;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

it('declares the rights per domain with their scope', function () {
    expect(Permissions::scoped())->toBe([
        'crm.fiches.view', 'crm.fiches.update', 'crm.fiches.delete',
        'crm.opportunities.view', 'crm.opportunities.update', 'crm.opportunities.delete',
        'crm.quotes.view', 'crm.quotes.update', 'crm.quotes.delete',
        'crm.activities.view', 'crm.activities.update', 'crm.activities.delete',
        'crm.revenue.view',
    ]);
    foreach (Permissions::scoped() as $permission) {
        expect(CorePermissions::isScoped($permission), $permission)->toBeTrue();
    }
    expect(CorePermissions::isScoped(Permissions::REVENUE_MANAGE))->toBeFalse()
        ->and(CorePermissions::all())->not->toContain('crm.view', 'crm.create', 'crm.update', 'crm.delete')
        ->and(collect(CorePermissions::all())->map(fn (string $p) => CorePermissions::label($p)['group'] ?? null)->unique()->values()->all())
        ->toBe(['Administration', 'Entreprises et personnes', 'Opportunités', 'Devis', 'Actions et agenda', "Chiffre d'affaires", 'Imports, exports et RGPD', 'Catalogue']);
});

it('devis without fiches rights', function () {
    $user = userWithPermissions([Permissions::QUOTES_VIEW]);
    $quote = makeQuote(makeOpportunity(Organisation::query()->create(['name' => 'Hôtel du Parc'])));

    $this->actingAs($user);
    $this->get('/crm/quotes')->assertOk();
    $this->get("/crm/quotes/{$quote->id}")->assertOk();
    $this->get('/crm/organisations')->assertForbidden();
    $this->get('/crm/opportunities')->assertForbidden();
    $this->get('/crm/todo')->assertForbidden();
    $this->getJson('/crm/search?q=abc')->assertOk();
    $this->get('/dashboard')->assertOk();
});

it('fiche update without quote update', function () {
    $user = userWithPermissions([Permissions::FICHES_VIEW, Permissions::FICHES_UPDATE, Permissions::OPPORTUNITIES_VIEW, Permissions::QUOTES_VIEW]);
    $org = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $opportunity = makeOpportunity($org);
    $company = Company::factory()->default()->create();

    $this->actingAs($user);
    $this->put("/crm/organisations/{$org->id}", ['name' => 'Nouveau nom'])->assertRedirect(route('crm.organisations.show', $org));
    expect($org->refresh()->name)->toBe('Nouveau nom');

    $this->get("/crm/opportunities/{$opportunity->id}/quotes/create")->assertForbidden();
    $this->post('/crm/quotes', quotePayload($opportunity, $company))->assertForbidden();
    $this->put("/crm/opportunities/{$opportunity->id}", ['title' => 'X'])->assertForbidden();
    expect(Quote::query()->count())->toBe(0);

    // The fiche page offers what the rights allow, nothing more.
    $this->get("/crm/organisations/{$org->id}")->assertInertia(fn (Assert $page) => $page
        ->where('can.update', true)->where('can.createPerson', true)->where('can.createOpportunity', false)->where('can.delete', false));
});

it('team todo needs activities.team', function () {
    $seller = commercial();
    $this->actingAs($seller)->get('/crm/todo?team=1')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('team', false)->where('can_team', false));
    $this->get('/crm/planning')->assertInertia(fn (Assert $p) => $p->where('can_team', false)->where('users', []));

    RoleModel::query()->where('name', 'commercial')->sole()->givePermissionTo(Permissions::ACTIVITIES_TEAM);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($seller->fresh())->get('/crm/todo?team=1')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('team', true)->where('can_team', true));
    $this->get('/crm/planning')->assertInertia(fn (Assert $p) => $p->where('can_team', true));

    // Direction holds it by default; taking it away hides the team view.
    $direction = direction(twoFactor: true);
    $this->actingAs($direction)->get('/crm/todo?team=1')->assertInertia(fn (Assert $p) => $p->where('team', true));
    RoleModel::query()->where('name', 'direction')->sole()->revokePermissionTo(Permissions::ACTIVITIES_TEAM);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($direction->fresh())->get('/crm/todo?team=1')->assertInertia(fn (Assert $p) => $p->where('team', false)->where('can_team', false));
});

it('sidebar shows only allowed domains', function () {
    $this->actingAs(userWithPermissions([Permissions::QUOTES_VIEW]))->get('/dashboard')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.permissions', fn ($p) => collect($p)->filter(fn (string $name) => str_starts_with($name, 'crm.'))->values()->all() === ['crm.quotes.view'])
            ->where('todo_count', 0));

    $this->actingAs(commercial())->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', fn ($p) => collect($p)->contains('crm.fiches.view')
            && collect($p)->contains('crm.activities.update') && ! collect($p)->contains('crm.activities.team')));
});

it('opens the CRM with any one view right', function () {
    expect(Permissions::anyView(userWithPermissions([Permissions::ACTIVITIES_VIEW])))->toBeTrue()
        ->and(Permissions::anyView(userWithPermissions([Permissions::EXPORT, Permissions::REVENUE_VIEW])))->toBeFalse();

    $this->actingAs(userWithPermissions([Permissions::EXPORT]));
    $this->getJson('/crm/search?q=abc')->assertForbidden();
    $this->get('/crm/organisations/export/check')->assertForbidden();
});
