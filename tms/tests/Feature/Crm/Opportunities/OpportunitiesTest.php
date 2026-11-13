<?php

use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Opportunities\StageChange;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Support\LinkedData;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function opportunityPayload(array $overrides = []): array
{
    return [
        'title' => 'Formation HACCP 3 jours',
        'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id,
        'stage_id' => pipelineStage('Nouveau')->id,
        ...$overrides,
    ];
}

it('creates an opportunity from an entreprise with its interlocuteurs', function () {
    $user = commercial();
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $chef = makePerson('Chef');
    affiliate($chef, $organisation);
    $stranger = makePerson('Ailleurs');

    $this->actingAs($user)->get("/crm/opportunities/create?organisation={$organisation->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('crm/opportunities/form')
            ->where('account.type', 'organisation')
            ->where('account.id', $organisation->id)
            ->has('contactOptions', 1));

    $this->post('/crm/opportunities', opportunityPayload([
        'organisation_id' => $organisation->id,
        'amount' => '12 500,00',
        'expected_close_on' => '2026-12-15',
        'priority_id' => crmItem(ReferenceList::Priority, 'Élevé')->id,
        'contact_ids' => [$chef->id],
    ]))->assertRedirect();

    $opportunity = Opportunity::query()->sole();
    expect($opportunity->organisation_id)->toBe($organisation->id)
        ->and($opportunity->person_id)->toBeNull()
        ->and($opportunity->probability)->toBe(10)
        ->and($opportunity->probability_overridden)->toBeFalse()
        ->and($opportunity->amount)->toBe('12500.00')
        ->and($opportunity->fresh()?->weighted_amount)->toBe('1250.00')
        ->and($opportunity->expected_close_on?->toDateString())->toBe('2026-12-15')
        ->and($opportunity->responsible_user_id)->toBe($user->id)
        ->and($opportunity->created_by)->toBe($user->id)
        ->and($opportunity->contacts()->pluck('people.id')->all())->toBe([$chef->id])
        ->and($opportunity->isIndividual())->toBeFalse();

    // A person who is not an interlocuteur of the entreprise cannot be linked.
    $this->postJson('/crm/opportunities', opportunityPayload(['organisation_id' => $organisation->id, 'contact_ids' => [$stranger->id]]))
        ->assertJsonValidationErrors('contact_ids.0');
});

it('creates an opportunity for a particulier with his funding mode prefilled', function () {
    $cpf = crmItem(ReferenceList::FundingMode, 'CPF');
    $person = makePerson('Martin', ['is_individual' => true, 'funding_mode_id' => $cpf->id]);

    $this->actingAs(commercial())->get("/crm/opportunities/create?person={$person->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('account.type', 'person')
            ->where('defaults.funding_mode_id', $cpf->id));

    $this->post('/crm/opportunities', opportunityPayload([
        'person_id' => $person->id,
        'stage_id' => pipelineStage('Proposition')->id,
        'funding_mode_id' => $cpf->id,
    ]))->assertRedirect();

    $opportunity = Opportunity::query()->sole();
    expect($opportunity->person_id)->toBe($person->id)
        ->and($opportunity->isIndividual())->toBeTrue()
        ->and($opportunity->probability)->toBe(60)
        ->and($opportunity->funding_mode_id)->toBe($cpf->id)
        // The particulier himself is the interlocuteur.
        ->and($opportunity->contacts()->pluck('people.id')->all())->toBe([$person->id]);

    $this->get("/crm/opportunities/{$opportunity->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('crm/opportunities/show')
            ->where('opportunity.target', 'individual')
            ->where('opportunity.funding_mode.label', 'CPF')
            ->where('opportunity.probability', 60));
});

it('requires exactly one account', function () {
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $person = makePerson('Martin', ['is_individual' => true]);

    $this->actingAs(commercial())->postJson('/crm/opportunities', opportunityPayload())->assertJsonValidationErrors('account');
    $this->postJson('/crm/opportunities', opportunityPayload(['organisation_id' => $organisation->id, 'person_id' => $person->id]))
        ->assertJsonValidationErrors('account');
    expect(Opportunity::count())->toBe(0);

    // The database enforces it too (CHECK).
    $row = fn (array $account) => DB::table('opportunities')->insert([
        'id' => (string) str()->uuid7(), 'tenant_id' => tenant()->id, 'title' => 'X',
        'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id, 'stage_id' => pipelineStage('Nouveau')->id,
        'probability' => 10, ...$account,
    ]);
    expect(fn () => DB::transaction(fn () => $row([])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => $row(['organisation_id' => $organisation->id, 'person_id' => $person->id])))->toThrow(QueryException::class);
});

it('validates the fields', function () {
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $base = opportunityPayload(['organisation_id' => $organisation->id]);

    $this->actingAs(commercial())->postJson('/crm/opportunities', [...$base, 'title' => ''])->assertJsonValidationErrors('title');
    $this->postJson('/crm/opportunities', [...$base, 'title' => str_repeat('a', 201)])->assertJsonValidationErrors('title');
    $this->postJson('/crm/opportunities', [...$base, 'das_id' => null])->assertJsonValidationErrors('das_id');
    $this->postJson('/crm/opportunities', [...$base, 'das_id' => pipelineStage('Nouveau')->id])->assertJsonValidationErrors('das_id');
    $this->postJson('/crm/opportunities', [...$base, 'amount' => 'beaucoup'])->assertJsonValidationErrors('amount');
    $this->postJson('/crm/opportunities', [...$base, 'amount' => '-5'])->assertJsonValidationErrors('amount');
    $this->postJson('/crm/opportunities', [...$base, 'probability' => 120])->assertJsonValidationErrors('probability');
    $this->postJson('/crm/opportunities', [...$base, 'stage_id' => pipelineStage('Gagné')->id])->assertJsonValidationErrors('stage_id');
    $this->postJson('/crm/opportunities', [...$base, 'expected_close_on' => '15/12/2026'])->assertJsonValidationErrors('expected_close_on');
    expect(Opportunity::count())->toBe(0);
});

it('accepts amounts typed the French way', function (string $typed, string $stored) {
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);

    $this->actingAs(commercial())->post('/crm/opportunities', opportunityPayload(['organisation_id' => $organisation->id, 'amount' => $typed]))
        ->assertSessionHasNoErrors();

    expect(Opportunity::query()->sole()->amount)->toBe($stored);
})->with([
    ['12 500,00', '12500.00'],
    ["12\u{202F}500,5 €", '12500.50'],
    ['800', '800.00'],
    ['1500.25', '1500.25'],
]);

it('records a manual probability as overridden and edits the opportunity', function () {
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $opportunity = makeOpportunity($organisation);

    $this->actingAs(commercial())->put("/crm/opportunities/{$opportunity->id}", opportunityPayload([
        'title' => 'HACCP renouvellement', 'probability' => 35, 'amount' => '2 000,00',
    ]))->assertRedirect("/crm/opportunities/{$opportunity->id}");

    $opportunity->refresh();
    expect($opportunity->title)->toBe('HACCP renouvellement')
        ->and($opportunity->probability)->toBe(35)
        ->and($opportunity->probability_overridden)->toBeTrue()
        ->and($opportunity->stage_id)->toBe(pipelineStage('Nouveau')->id)
        ->and($opportunity->weighted_amount)->toBe('700.00');

    // Back to the stage default: no longer an override.
    $this->put("/crm/opportunities/{$opportunity->id}", opportunityPayload(['probability' => 10]));
    expect($opportunity->refresh()->probability_overridden)->toBeFalse();
});

it('lets only crm.delete remove an opportunity', function () {
    $opportunity = makeOpportunity(Organisation::query()->create(['name' => 'Le Bistrot']));

    $this->actingAs(assistant())->delete("/crm/opportunities/{$opportunity->id}")->assertForbidden();
    expect(Opportunity::query()->count())->toBe(1);

    $this->actingAs(commercial())->delete("/crm/opportunities/{$opportunity->id}")->assertRedirect('/crm/opportunities');
    expect(Opportunity::query()->count())->toBe(0)
        ->and(Opportunity::withTrashed()->count())->toBe(1);
    $this->get("/crm/opportunities/{$opportunity->id}")->assertNotFound();
});

it('lists, filters and sorts the opportunities', function () {
    $user = commercial();
    $bistrot = Organisation::query()->create(['name' => 'Le Bistrot']);
    $particulier = makePerson('Martin', ['is_individual' => true]);
    makeOpportunity($bistrot, ['title' => 'Petit', 'amount' => 1000, 'expected_close_on' => '2026-12-01', 'responsible_user_id' => $user->id]);
    makeOpportunity($bistrot, ['title' => 'Gros', 'amount' => 5000, 'stage_id' => pipelineStage('Proposition')->id, 'expected_close_on' => '2027-02-01']);
    makeOpportunity($particulier, ['title' => 'Reconversion', 'amount' => 3000, 'stage_id' => pipelineStage('Négociation')->id, 'priority_id' => crmItem(ReferenceList::Priority, 'Urgent')->id]);

    $titles = fn (string $query = '') => collect($this->get('/crm/opportunities'.$query)->original->getData()['page']['props']['opportunities']['data'])->pluck('title')->all();

    $this->actingAs($user);
    expect($titles())->toHaveCount(3)
        ->and($titles('?target=individual'))->toBe(['Reconversion'])
        ->and($titles('?target=organisation&sort=amount&dir=desc'))->toBe(['Gros', 'Petit'])
        ->and($titles('?sort=weighted_amount&dir=desc'))->toBe(['Gros', 'Reconversion', 'Petit'])
        ->and($titles('?stage='.pipelineStage('Proposition')->id))->toBe(['Gros'])
        ->and($titles('?priority='.crmItem(ReferenceList::Priority, 'Urgent')->id))->toBe(['Reconversion'])
        ->and($titles('?mine=1'))->toBe(['Petit'])
        ->and($titles('?close_from=2026-11-01&close_to=2026-12-31'))->toBe(['Petit'])
        ->and($titles('?q=bistrot&sort=amount'))->toBe(['Petit', 'Gros'])
        ->and($titles('?q=reconv'))->toBe(['Reconversion'])
        ->and($titles('?q[]=x&stage[]=y&sort=hack'))->toHaveCount(3);

    $this->get('/crm/opportunities')->assertInertia(fn (Assert $page) => $page->component('crm/opportunities/index')
        ->where('opportunities.total', 3)
        ->where('totals.amount', '9000.00'));
});

it('shows the opportunities tab of the entreprise and the person', function () {
    $bistrot = Organisation::query()->create(['name' => 'Le Bistrot']);
    $particulier = makePerson('Martin', ['is_individual' => true]);
    makeOpportunity($bistrot, ['title' => 'HACCP']);
    makeOpportunity($particulier, ['title' => 'Reconversion']);

    $this->actingAs(commercial())->get("/crm/organisations/{$bistrot->id}")
        ->assertInertia(fn (Assert $page) => $page->has('opportunities', 1)->where('opportunities.0.title', 'HACCP')->where('can.createOpportunity', true));
    $this->get("/crm/people/{$particulier->id}")
        ->assertInertia(fn (Assert $page) => $page->has('opportunities', 1)->where('opportunities.0.title', 'Reconversion'));
});

it('registers the opportunities as linked data and their references as used', function () {
    $bistrot = Organisation::query()->create(['name' => 'Le Bistrot']);
    $contact = makePerson('Chef');
    $affiliation = affiliate($contact, $bistrot);
    $priority = crmItem(ReferenceList::Priority, 'Bas');
    expect(LinkedData::any($bistrot, [$affiliation->id]))->toBeFalse();

    $opportunity = makeOpportunity($bistrot, ['priority_id' => $priority->id]);
    $opportunity->contacts()->sync([$contact->id => ['tenant_id' => tenant()->id]]);

    expect(LinkedData::keys())->toContain('crm.opportunities')
        ->and(LinkedData::any($bistrot, [$affiliation->id]))->toBeTrue()
        ->and(LinkedData::any($contact, [$affiliation->id]))->toBeTrue()
        ->and(ReferenceUsage::isInUse($priority))->toBeTrue()
        ->and(ReferenceUsage::isInUse(pipelineStage('Nouveau')))->toBeTrue()
        ->and(StageChange::query()->count())->toBe(1);
});
