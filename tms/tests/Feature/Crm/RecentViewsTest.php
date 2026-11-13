<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Search\RecentView;
use App\Modules\Crm\Search\RecentViews;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

it('records the fiches a user opens and lists the latest five, most recent first', function () {
    $user = commercial();
    $this->actingAs($user);
    $organisations = collect(range(1, 6))->map(fn ($i) => Organisation::query()->create(['name' => "Entreprise {$i}"]));

    foreach ($organisations as $organisation) {
        $this->travel(1)->seconds();
        $this->get("/crm/organisations/{$organisation->id}")->assertOk();
    }
    // Opening an old one again brings it back to the top, without a duplicate row.
    $this->travel(1)->seconds();
    $this->get("/crm/organisations/{$organisations[2]->id}")->assertOk();

    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('recent_views.0.name', 'Entreprise 3')
        ->where('recent_views.0.url', "/crm/organisations/{$organisations[2]->id}")
        ->where('recent_views.0.type', 'organisation')
        ->where('recent_views.4.name', 'Entreprise 2')
        ->has('recent_views', 5));
    expect(RecentView::query()->where('user_id', $user->id)->count())->toBe(6);
});

it('keeps the last 20 per user and nothing of other users', function () {
    $user = commercial();
    $other = commercial();
    $this->actingAs($user);
    $people = collect(range(1, 22))->map(fn ($i) => Person::query()->create(['last_name' => "Nom{$i}"]));

    foreach ($people as $person) {
        $this->get("/crm/people/{$person->id}")->assertOk();
    }
    $this->actingAs($other)->get("/crm/people/{$people[0]->id}")->assertOk();

    expect(RecentView::query()->where('user_id', $user->id)->count())->toBe(RecentViews::KEEP)
        ->and(RecentView::query()->where('user_id', $other->id)->count())->toBe(1);

    $this->actingAs($other)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->has('recent_views', 1)->where('recent_views.0.name', 'Nom1'));
});

it('hides fiches that were deleted, merged or are no longer visible', function () {
    $user = commercial();
    $this->actingAs($user);
    $kept = Organisation::query()->create(['name' => 'Gardée']);
    $deleted = Organisation::query()->create(['name' => 'Supprimée']);
    foreach ([$kept, $deleted] as $organisation) {
        $this->get("/crm/organisations/{$organisation->id}")->assertOk();
    }
    $deleted->delete();

    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->has('recent_views', 1)->where('recent_views.0.name', 'Gardée'));
});

it('hides the fiches of a DAS the Responsable DAS no longer follows', function () {
    $user = userWithRole(Role::ResponsableDas);
    $this->actingAs($user);
    $das = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    followDas($user, $das);
    $organisation = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    DB::table('organisation_das')->insert(['tenant_id' => tenant()->id, 'organisation_id' => $organisation->id, 'das_id' => $das->id]);
    $contact = makePerson('Martin');
    affiliate($contact, $organisation);
    $this->get("/crm/organisations/{$organisation->id}")->assertOk();
    $this->get("/crm/people/{$contact->id}")->assertOk();
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->has('recent_views', 2));

    DB::table('user_das')->where('user_id', $user->id)->delete();

    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('recent_views', []));
});

it('hides a fiche merged into another one and shows the surviving fiche once opened', function () {
    $this->actingAs(commercial());
    $keep = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $drop = Organisation::query()->create(['name' => 'Hotel du Lac']);
    $this->get("/crm/organisations/{$drop->id}")->assertOk();

    $this->post('/crm/merge/organisation', ['keep' => $keep->id, 'drop' => $drop->id, 'fields' => []])->assertRedirect();

    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('recent_views', []));
    $this->get("/crm/organisations/{$keep->id}")->assertOk();
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->has('recent_views', 1)->where('recent_views.0.url', "/crm/organisations/{$keep->id}"));
});

it('does not record a fiche the user may not open', function () {
    $user = commercial();
    $this->actingAs($user);

    $this->get('/crm/organisations/'.fake()->uuid())->assertNotFound();

    expect(RecentView::query()->count())->toBe(0);
});

it('gives the dashboard counters and an empty recent list to a user who opened nothing', function () {
    $this->actingAs(assistant());
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('recent_views', [])->has('counters.organisations')->has('counters.people'));
});
