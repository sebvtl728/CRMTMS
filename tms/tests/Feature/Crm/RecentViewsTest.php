<?php

use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Search\RecentView;
use App\Modules\Crm\Search\RecentViews;
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
