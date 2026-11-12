<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Search\GlobalSearch;
use App\Rules\Siret;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function gsSiret(): string
{
    for ($check = 0; $check <= 9; $check++) {
        if (Siret::isValid('7328293200007'.$check)) {
            return '7328293200007'.$check;
        }
    }
    throw new RuntimeException('unreachable');
}

it('finds an entreprise without caring for accents or case', function () {
    $user = commercial();
    $this->actingAs($user);
    Organisation::query()->create(['name' => 'Hôtel du Lac', 'city' => 'Annecy']);
    Organisation::query()->create(['name' => 'Garage Martin']);

    $result = app(GlobalSearch::class)->query('hotel', $user);

    expect(collect($result['organisations'])->pluck('name')->all())->toBe(['Hôtel du Lac'])
        ->and($result['people'])->toBe([]);
});

it('finds an entreprise by exact siret, with or without spaces', function () {
    $user = commercial();
    $this->actingAs($user);
    $siret = gsSiret();
    Organisation::query()->create(['name' => 'Alpha', 'siret' => $siret]);
    Organisation::query()->create(['name' => 'Beta']);
    $spaced = substr($siret, 0, 3).' '.substr($siret, 3, 3).' '.substr($siret, 6, 3).' '.substr($siret, 9);

    foreach ([$siret, $spaced] as $term) {
        expect(collect(app(GlobalSearch::class)->query($term, $user)['organisations'])->pluck('name')->all())->toBe(['Alpha']);
    }
});

it('finds an entreprise by exact e-mail and by phone typed in any format', function () {
    $user = commercial();
    $this->actingAs($user);
    Organisation::query()->create(['name' => 'Alpha', 'email' => 'Contact@Alpha.fr', 'phone' => '04 50 12 34 56']);
    Organisation::query()->create(['name' => 'Beta']);

    expect(collect(app(GlobalSearch::class)->query('contact@alpha.fr', $user)['organisations'])->pluck('name')->all())->toBe(['Alpha'])
        ->and(collect(app(GlobalSearch::class)->query('+33 4 50 12 34 56', $user)['organisations'])->pluck('name')->all())->toBe(['Alpha'])
        ->and(collect(app(GlobalSearch::class)->query('0450123456', $user)['organisations'])->pluck('name')->all())->toBe(['Alpha']);
});

it('finds a person by normalised phone typed with spaces', function () {
    $user = commercial();
    $this->actingAs($user);
    makePerson('Durand', ['first_name' => 'Léa', 'mobile' => '06 12 34 56 78']);
    makePerson('Autre');

    $result = app(GlobalSearch::class)->query('06 12 34 56 78', $user);

    expect(collect($result['people'])->pluck('name')->all())->toBe(['Léa Durand']);
});

it('finds a person by name and e-mail, showing her entreprise', function () {
    $user = commercial();
    $this->actingAs($user);
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $person = makePerson('Éléonore', ['first_name' => 'Marie', 'email' => 'marie@lac.fr']);
    affiliate($person, $org);

    foreach (['eleonore', 'marie@lac.fr'] as $term) {
        $people = app(GlobalSearch::class)->query($term, $user)['people'];
        expect($people)->toHaveCount(1)
            ->and($people[0]['name'])->toBe('Marie Éléonore')
            ->and($people[0]['subtitle'])->toBe('Hôtel du Lac')
            ->and($people[0]['url'])->toBe(route('crm.people.show', $person->id, false));
    }
});

it('limits each group and ignores deleted and merged fiches', function () {
    $user = commercial();
    $this->actingAs($user);
    foreach (range(1, 12) as $i) {
        Organisation::query()->create(['name' => 'Camping '.$i]);
    }
    $gone = Organisation::query()->create(['name' => 'Camping supprimé']);
    $gone->delete();
    $keep = Organisation::query()->create(['name' => 'Camping gardé']);
    $merged = Organisation::query()->create(['name' => 'Camping fusionné']);
    $merged->merged_into_id = $keep->id;
    $merged->save();
    $merged->delete();

    $names = collect(app(GlobalSearch::class)->query('camping', $user, 50)['organisations'])->pluck('name');

    expect(app(GlobalSearch::class)->query('camping', $user)['organisations'])->toHaveCount(10)
        ->and($names)->not->toContain('Camping supprimé')->not->toContain('Camping fusionné')->toContain('Camping gardé');
});

it('hides fiches outside the das of a responsable de das', function () {
    $user = userWithRole(Role::ResponsableDas);
    $this->actingAs($user);
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $other = crmItem(ReferenceList::Das, 'Métiers supports');
    followDas($user, $mine);
    $visible = Organisation::query()->create(['name' => 'Alpha visible']);
    $hidden = Organisation::query()->create(['name' => 'Alpha cachée']);
    DB::table('organisation_das')->insert([
        ['tenant_id' => tenant()->id, 'organisation_id' => $visible->id, 'das_id' => $mine->id],
        ['tenant_id' => tenant()->id, 'organisation_id' => $hidden->id, 'das_id' => $other->id],
    ]);
    $person = makePerson('Alpha-Dupont');
    affiliate($person, $hidden);

    $result = app(GlobalSearch::class)->query('alpha', $user);

    expect(collect($result['organisations'])->pluck('name')->all())->toBe(['Alpha visible'])
        ->and($result['people'])->toBe([]);
});

it('serves the search as json, with a minimum of two characters', function () {
    $user = commercial();
    $this->actingAs($user);
    Organisation::query()->create(['name' => 'Hôtel du Lac']);

    $this->getJson('/crm/search?q=h')->assertOk()->assertExactJson(['organisations' => [], 'people' => []]);
    $this->getJson('/crm/search?q=hotel')->assertOk()
        ->assertJsonPath('organisations.0.name', 'Hôtel du Lac')
        ->assertJsonStructure(['organisations' => [['id', 'name', 'subtitle', 'url']], 'people']);
    $this->getJson('/crm/search?q[]=x')->assertOk()->assertExactJson(['organisations' => [], 'people' => []]);
});

it('requires crm.view and a signed-in user', function () {
    $this->getJson('/crm/search?q=hotel')->assertUnauthorized();

    $this->actingAs(assistant());
    $this->getJson('/crm/search?q=hotel')->assertOk();
});

it('throttles the search to 60 requests a minute', function () {
    $this->actingAs(commercial());

    foreach (range(1, 60) as $i) {
        $this->getJson('/crm/search?q=zz')->assertOk();
    }
    $this->getJson('/crm/search?q=zz')->assertStatus(429);
});

it('never returns fiches of another tenant', function () {
    $user = commercial();
    $this->actingAs($user);
    $other = tenantOnHost('autre.test');
    app(TenantContext::class)->run($other, fn () => Organisation::query()->create(['name' => 'Hôtel étranger']));
    Person::query()->count();

    expect(app(GlobalSearch::class)->query('hotel', $user)['organisations'])->toBe([]);
});
