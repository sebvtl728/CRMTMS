<?php

use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Quotes\QuoteStatus;
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
        ->and(CorePermissions::all())->not->toContain('crm.view', 'crm.create', 'crm.update', 'crm.delete');

    // Themes of the « Rôles et droits » screen, in the order of the plan.
    $this->actingAs(admin(twoFactor: true))->get('/admin/roles')->assertInertia(fn (Assert $page) => $page
        ->where('groups', fn ($groups) => collect($groups)->pluck('label')->all() === [
            'Entreprises et personnes', 'Opportunités', 'Devis', 'Actions et agenda', "Chiffre d'affaires", 'Catalogue', 'Dossiers', 'Sessions et inscriptions', 'Imports, exports et RGPD', 'Administration',
        ]));
});

it('deleting an action needs activities.delete', function () {
    $org = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $activity = fn () => Activity::query()->create(['organisation_id' => $org->id, 'type_id' => crmItem(ReferenceList::ActionType, 'Appel')->id, 'subject' => 'Appel', 'done_at' => now()]);
    $kept = $activity();

    $this->actingAs(userWithPermissions([Permissions::FICHES_VIEW, Permissions::ACTIVITIES_VIEW, Permissions::ACTIVITIES_UPDATE]));
    $this->getJson("/crm/activities/{$kept->id}")->assertOk()->assertJsonPath('can.update', true)->assertJsonPath('can.delete', false);
    $this->delete("/crm/activities/{$kept->id}")->assertForbidden();
    expect($kept->fresh()->trashed())->toBeFalse();

    $this->actingAs(userWithPermissions([Permissions::FICHES_VIEW, Permissions::ACTIVITIES_VIEW, Permissions::ACTIVITIES_DELETE]));
    $removed = $activity();
    $this->delete("/crm/activities/{$removed->id}")->assertRedirect();
    expect(Activity::query()->whereKey($removed->id)->exists())->toBeFalse();

    // The assistant keeps removing actions, as before 0.5.0.
    expect(Permissions::defaultsFor(Role::Assistant))->toContain(Permissions::ACTIVITIES_DELETE);
});

it('wins with a quote only when the quote is visible', function () {
    $opportunity = makeOpportunity(Organisation::query()->create(['name' => 'Hôtel du Parc']), ['amount' => '500.00']);
    $quote = makeQuote($opportunity);
    $today = now('Europe/Paris')->toDateString();
    $quote->forceFill(['status' => QuoteStatus::Accepted, 'accepted_on' => $today])->save();
    $won = ['stage_id' => pipelineStage('Gagné')->id, 'won_on' => $today, 'quote_id' => $quote->id];

    $this->actingAs(userWithPermissions([Permissions::OPPORTUNITIES_VIEW, Permissions::OPPORTUNITIES_UPDATE]));
    $this->post("/crm/opportunities/{$opportunity->id}/stage", $won)->assertForbidden();
    expect($opportunity->fresh()->stage_id)->toBe(pipelineStage('Nouveau')->id)
        ->and($opportunity->fresh()->amount)->toBe('500.00');

    $this->actingAs(userWithPermissions([Permissions::OPPORTUNITIES_VIEW, Permissions::OPPORTUNITIES_UPDATE, Permissions::QUOTES_VIEW]));
    $this->post("/crm/opportunities/{$opportunity->id}/stage", $won)->assertSessionHasNoErrors()->assertRedirect();
    expect($opportunity->fresh()->amount)->toBe('1000.00');
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

it('quote rights alone never reach the creation of a quote on an opportunity', function () {
    $user = userWithPermissions([Permissions::QUOTES_VIEW, Permissions::QUOTES_UPDATE]);
    $org = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $opportunity = makeOpportunity($org);
    $company = Company::factory()->default()->create();

    $this->actingAs($user);
    $status = $this->get("/crm/opportunities/{$opportunity->id}/quotes/create")->status();
    expect($status)->toBeIn([403, 404]);
    $this->post('/crm/quotes', quotePayload($opportunity, $company))->assertSessionHasErrors('opportunity_id');
    expect(Quote::query()->count())->toBe(0);
});
