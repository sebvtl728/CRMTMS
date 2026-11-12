<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Duplicates\DuplicateCandidate;
use App\Modules\Crm\Duplicates\DuplicateFinder;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Rules\Siret;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function dupSiret(int $seed = 7328293200007): string
{
    $base = str_pad((string) $seed, 13, '0', STR_PAD_LEFT);
    for ($check = 0; $check <= 9; $check++) {
        if (Siret::isValid($base.$check)) {
            return $base.$check;
        }
    }
    throw new RuntimeException('unreachable');
}

/** @param array<string, mixed> $attributes */
function dupOrg(string $name, array $attributes = []): Organisation
{
    return Organisation::query()->create(['name' => $name, ...$attributes]);
}

function finder(): DuplicateFinder
{
    return app(DuplicateFinder::class);
}

/**
 * @param  iterable<DuplicateCandidate>  $candidates
 * @return list<array{0: string, 1: string}>
 */
function idsAndReasons(iterable $candidates): array
{
    return collect($candidates)->map(fn (DuplicateCandidate $c) => [$c->id, $c->reason])->values()->all();
}

it('finds an entreprise with the same siret', function () {
    $existing = dupOrg('Hôtel du Lac', ['siret' => dupSiret()]);
    dupOrg('Autre');

    $found = finder()->forOrganisation(['name' => 'Tout autre nom', 'siret' => dupSiret()]);

    expect($found)->toHaveCount(1)
        ->and($found->first())->toBeInstanceOf(DuplicateCandidate::class)
        ->and($found->first()->id)->toBe($existing->id)
        ->and($found->first()->label)->toContain('Hôtel du Lac')
        ->and($found->first()->reason)->toBe('siret')
        ->and($found->first()->score)->toBe(1.0);
});

it('finds an entreprise with the same e-mail, whatever the case', function () {
    $existing = dupOrg('Hôtel du Lac', ['email' => 'Contact@Hotel-Lac.fr']);

    $found = finder()->forOrganisation(['name' => 'Rien à voir', 'email' => 'contact@hotel-lac.FR']);

    expect(idsAndReasons($found))->toBe([[$existing->id, 'email']])->and($found->first()->score)->toBe(0.95);
});

it('finds an entreprise with the same phone number, whatever its format', function () {
    $typed = dupOrg('Hôtel du Lac', ['phone' => '04 72 00 11 22']);
    $international = dupOrg('Autre hôtel', ['phone' => '+33 4 72 00 11 22']);
    dupOrg('Sans rapport', ['phone' => '04 72 00 11 23']);

    $found = finder()->forOrganisation(['name' => 'Nouveau', 'phone' => '+33472001122']);

    expect(collect($found)->pluck('id')->sort()->values()->all())->toBe(collect([$typed->id, $international->id])->sort()->values()->all())
        ->and(collect($found)->pluck('reason')->unique()->all())->toBe(['phone'])
        ->and($found->first()->score)->toBe(0.9);
});

it('finds a similar entreprise name only in the same postal code or city', function () {
    $sameCp = dupOrg('Hôtel du Lac', ['postal_code' => '74000', 'city' => 'Annecy']);
    $sameCity = dupOrg('Hotel du lac SARL', ['postal_code' => '74001', 'city' => 'ANNECY']);
    dupOrg('Hôtel du Lac', ['postal_code' => '69001', 'city' => 'Lyon']);
    dupOrg('Hôtel des Alpes', ['postal_code' => '74000', 'city' => 'Annecy']);
    dupOrg('Hôtel du Lac'); // no address at all

    $found = finder()->forOrganisation(['name' => 'hotel du LAC', 'postal_code' => '74000', 'city' => 'Ännecy']);

    expect(collect($found)->pluck('id')->all())->toBe([$sameCp->id, $sameCity->id])
        ->and(collect($found)->pluck('reason')->unique()->all())->toBe(['name'])
        ->and($found->first()->score)->toBe(1.0)
        ->and($found->last()->score)->toBeGreaterThanOrEqual(0.6)->toBeLessThan(1.0);

    // A name alone (quick entry) is not enough to suspect a duplicate.
    expect(finder()->forOrganisation(['name' => 'Hôtel du Lac']))->toBeEmpty();
});

it('reports a candidate once, with its best reason, and at most 5 sorted by score', function () {
    $both = dupOrg('Hôtel du Lac', ['siret' => dupSiret(), 'email' => 'lac@hotel.fr', 'postal_code' => '74000']);
    foreach (range(1, 6) as $i) {
        dupOrg("Hôtel du Lac {$i}", ['postal_code' => '74000']);
    }

    $found = finder()->forOrganisation(['name' => 'Hôtel du Lac', 'siret' => dupSiret(), 'email' => 'lac@hotel.fr', 'postal_code' => '74000']);

    expect($found)->toHaveCount(5)
        ->and($found->first()->id)->toBe($both->id)
        ->and($found->first()->reason)->toBe('siret')
        ->and(collect($found)->pluck('id')->duplicates())->toBeEmpty();
    $scores = collect($found)->pluck('score')->all();
    $sorted = $scores;
    rsort($sorted);
    expect($scores)->toBe($sorted);
});

it('ignores the fiche being edited and the trashed ones', function () {
    $self = dupOrg('Hôtel du Lac', ['email' => 'lac@hotel.fr']);
    $trashed = dupOrg('Hôtel du Lac bis', ['email' => 'lac@hotel.fr']);
    $trashed->delete();

    expect(finder()->forOrganisation(['name' => 'Hôtel du Lac', 'email' => 'lac@hotel.fr'], $self->id))->toBeEmpty();
});

it('duplicate finder ignores other tenants', function () {
    $other = Tenant::factory()->create();
    app(TenantContext::class)->run($other, function () {
        dupOrg('Hôtel du Lac', ['siret' => dupSiret(), 'email' => 'lac@hotel.fr', 'phone' => '0472001122', 'postal_code' => '74000']);
        Person::query()->create(['last_name' => 'Dupont', 'first_name' => 'Jean', 'email' => 'jean@dupont.fr', 'mobile' => '0612345678', 'city' => 'Annecy']);
    });
    inTenant();

    expect(finder()->forOrganisation(['name' => 'Hôtel du Lac', 'siret' => dupSiret(), 'email' => 'lac@hotel.fr', 'phone' => '0472001122', 'postal_code' => '74000']))->toBeEmpty()
        ->and(finder()->forPerson(['last_name' => 'Dupont', 'first_name' => 'Jean', 'email' => 'jean@dupont.fr', 'mobile' => '0612345678', 'city' => 'Annecy']))->toBeEmpty();
});

it('finds a person with the same e-mail in either e-mail field', function () {
    $main = makePerson('Dupont', ['email' => 'Jean@Dupont.fr']);
    $secondary = makePerson('Martin', ['email_secondary' => 'jean@dupont.fr']);

    $found = finder()->forPerson(['last_name' => 'Autre', 'email' => 'JEAN@dupont.fr']);
    expect(collect($found)->pluck('id')->sort()->values()->all())->toBe(collect([$main->id, $secondary->id])->sort()->values()->all())
        ->and($found->first()->reason)->toBe('email')
        ->and($found->first()->score)->toBe(0.95);

    expect(idsAndReasons(finder()->forPerson(['last_name' => 'Autre', 'email_secondary' => 'jean@dupont.fr'])))->toHaveCount(2);
});

it('finds a person with the same normalised phone or mobile', function () {
    $mobile = makePerson('Dupont', ['mobile' => '06 12 34 56 78']);
    $phone = makePerson('Martin', ['phone' => '+33 6 12 34 56 78']);

    $found = finder()->forPerson(['last_name' => 'Autre', 'phone' => '0612345678']);

    expect(collect($found)->pluck('id')->sort()->values()->all())->toBe(collect([$mobile->id, $phone->id])->sort()->values()->all())
        ->and($found->first()->reason)->toBe('phone')
        ->and($found->first()->score)->toBe(0.9);
});

it('finds a person with a similar name in the same postal code or city', function () {
    $close = makePerson('Dupond', ['first_name' => 'Jean', 'city' => 'Annecy']);
    makePerson('Dupond', ['first_name' => 'Jean', 'city' => 'Lyon']);
    makePerson('Durand', ['first_name' => 'Marie', 'city' => 'Annecy']);

    $found = finder()->forPerson(['last_name' => 'Dupont', 'first_name' => 'Jean', 'city' => 'annecy']);

    expect(idsAndReasons($found))->toBe([[$close->id, 'name']])
        ->and($found->first()->score)->toBeGreaterThanOrEqual(0.6)
        ->and($found->first()->label)->toContain('Jean Dupond');
});

it('answers 409 with the candidates, then creates the entreprise once confirmed', function () {
    $existing = dupOrg('Hôtel du Lac', ['email' => 'lac@hotel.fr']);
    $this->actingAs(commercial());

    $response = $this->postJson('/crm/organisations', ['name' => 'Hôtel du Lac', 'email' => 'lac@hotel.fr'])->assertStatus(409);

    expect($response->json('duplicates.0.id'))->toBe($existing->id)
        ->and($response->json('duplicates.0.reason'))->toBe('email')
        ->and($response->json('duplicates.0.label'))->toContain('Hôtel du Lac')
        ->and($response->json('message'))->toBe('Des fiches similaires existent déjà.')
        ->and(Organisation::count())->toBe(1);

    $this->postJson('/crm/organisations', ['name' => 'Hôtel du Lac', 'email' => 'lac@hotel.fr', 'confirm_duplicate' => 1])->assertRedirect();
    expect(Organisation::count())->toBe(2);
});

it('gives an inertia form a validation-style error and the candidates as a page prop', function () {
    $existing = makePerson('Dupont', ['first_name' => 'Jean', 'mobile' => '0612345678']);
    $this->actingAs(commercial());

    $this->from('/crm/people/create')
        ->post('/crm/people', ['last_name' => 'Martin', 'phone' => '06 12 34 56 78'], ['X-Inertia' => 'true'])
        ->assertRedirect('/crm/people/create')
        ->assertSessionHasErrors('duplicates');
    expect(Person::count())->toBe(1);

    $this->get('/crm/people/create')->assertInertia(fn (Assert $page) => $page
        ->component('crm/people/form')
        ->where('duplicates.0.id', $existing->id)
        ->where('duplicates.0.reason', 'phone')
        ->where('duplicates.0.reason_label', 'Même téléphone')
        ->where('canMerge', true));

    $this->post('/crm/people', ['last_name' => 'Martin', 'phone' => '06 12 34 56 78', 'confirm_duplicate' => '1'])->assertRedirect();
    expect(Person::count())->toBe(2);
});

it('checks again on edit only when an identifying field changes', function () {
    $existing = dupOrg('Hôtel du Lac', ['email' => 'lac@hotel.fr']);
    $edited = dupOrg('Hôtel du Lac (doublon connu)', ['email' => 'lac@hotel.fr']);
    $this->actingAs(commercial());

    // Same e-mail as before: the user already chose to keep both.
    $this->putJson("/crm/organisations/{$edited->id}", ['name' => 'Hôtel du Lac (doublon connu)', 'email' => 'lac@hotel.fr', 'city' => 'Annecy'])->assertRedirect();

    $other = dupOrg('Autre', ['phone' => '0450000000']);
    $this->putJson("/crm/organisations/{$other->id}", ['name' => 'Autre', 'email' => 'lac@hotel.fr', 'phone' => '0450000000'])
        ->assertStatus(409)->assertJsonPath('duplicates.0.reason', 'email');
    expect(collect($this->putJson("/crm/organisations/{$other->id}", ['name' => 'Autre', 'email' => 'lac@hotel.fr'])->json('duplicates'))->pluck('id'))
        ->toContain($existing->id)->not->toContain($other->id);
    expect($other->refresh()->email)->toBeNull();
});

it('only shows the candidates the user may see', function () {
    $das = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $user = userWithRole(Role::ResponsableDas);
    $user->givePermissionTo('crm.create');
    followDas($user, $das);
    dupOrg('Invisible', ['email' => 'lac@hotel.fr']);

    $this->actingAs($user)->postJson('/crm/organisations', ['name' => 'Nouveau', 'email' => 'lac@hotel.fr', 'das_ids' => [$das->id]])->assertRedirect();
});
