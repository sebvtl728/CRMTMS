<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Gdpr\Anonymiser;
use App\Modules\Crm\Gdpr\PersonDataExport;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Opportunities\OpportunityStage;
use App\Modules\Crm\Opportunities\StageChange;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    fakeImportDisk();
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

/**
 * @return list<string> person ids of the contacts of an opportunity
 */
function ogContacts(Opportunity $opportunity): array
{
    return DB::table('opportunity_contacts')->where('opportunity_id', $opportunity->id)->orderBy('person_id')->pluck('person_id')->all();
}

it('merge moves opportunities of an entreprise, trashed ones and their history too', function () {
    $this->actingAs(commercial());
    $keep = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $drop = Organisation::query()->create(['name' => 'Hotel du lac']);
    $own = makeOpportunity($keep, ['title' => 'Déjà là']);
    $moved = makeOpportunity($drop, ['title' => 'HACCP']);
    $trashed = makeOpportunity($drop, ['title' => 'Supprimée']);
    $trashed->delete();

    $this->post('/crm/merge/organisation', ['keep' => $keep->id, 'drop' => $drop->id, 'fields' => []])
        ->assertRedirect(route('crm.organisations.show', $keep));

    expect($moved->refresh()->organisation_id)->toBe($keep->id)
        ->and(Opportunity::withTrashed()->findOrFail($trashed->id)->organisation_id)->toBe($keep->id)
        ->and($own->refresh()->organisation_id)->toBe($keep->id)
        ->and(Opportunity::withTrashed()->where('organisation_id', $drop->id)->count())->toBe(0);

    $titles = collect($this->getJson("/crm/organisations/{$keep->id}/timeline")->json('entries'))
        ->where('type', 'opportunity')->pluck('title')->all();
    expect($titles)->toContain('Opportunité créée : « HACCP »');
});

it('merge moves the opportunities and deduplicated contacts of a person to the kept one', function () {
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $keep = makePerson('Dupont', ['first_name' => 'Jean']);
    $drop = makePerson('Dupond', ['first_name' => 'Jean', 'is_individual' => true]);
    affiliate($keep, $org);
    affiliate($drop, $org);

    $both = makeOpportunity($org, ['title' => 'Les deux']);
    app(OpportunityStage::class)->syncContacts($both, [$keep->id, $drop->id]);
    $dropOnly = makeOpportunity($org, ['title' => 'Absorbé seul']);
    app(OpportunityStage::class)->syncContacts($dropOnly, [$drop->id]);
    $individual = makeOpportunity($drop, ['title' => 'Particulier']);

    $this->post('/crm/merge/person', ['keep' => $keep->id, 'drop' => $drop->id, 'fields' => []])
        ->assertRedirect(route('crm.people.show', $keep));

    expect(ogContacts($both))->toBe([$keep->id])
        ->and(ogContacts($dropOnly))->toBe([$keep->id])
        ->and($individual->refresh()->person_id)->toBe($keep->id)
        ->and(ogContacts($individual))->toBe([$keep->id])
        ->and(DB::table('opportunity_contacts')->where('person_id', $drop->id)->count())->toBe(0)
        // An opportunity account is a particulier: the kept person becomes one.
        ->and($keep->refresh()->is_individual)->toBeTrue();
});

it('anonymisation clears the loss comment and the contacts, keeps the opportunity for statistics', function () {
    $this->actingAs(commercial());
    $person = makePerson('Zébulon', ['first_name' => 'Anatole', 'is_individual' => true]);
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    affiliate($person, $org);
    $own = makeOpportunity($person, ['title' => 'BTS', 'amount' => 4500]);
    app(OpportunityStage::class)->move($own, pipelineStage('Perdu'), crmItem(ReferenceList::LossReason, 'Prix')->id, 'Anatole trouve cela trop cher', null);
    $asContact = makeOpportunity($org, ['title' => 'HACCP', 'amount' => 1200]);
    app(OpportunityStage::class)->syncContacts($asContact, [$person->id]);
    $otherLost = makeOpportunity($org, ['title' => 'Autre']);
    app(OpportunityStage::class)->move($otherLost, pipelineStage('Perdu'), crmItem(ReferenceList::LossReason, 'Prix')->id, 'Commentaire entreprise', null);

    app(Anonymiser::class)->anonymise($person, 'request');

    $own->refresh();
    expect($own->loss_comment)->toBeNull()
        ->and($own->amount)->toBe('4500.00')
        ->and($own->stage_id)->toBe(pipelineStage('Perdu')->id)
        ->and($own->loss_reason_id)->toBe(crmItem(ReferenceList::LossReason, 'Prix')->id)
        ->and($own->person_id)->toBe($person->id)
        ->and($own->trashed())->toBeFalse()
        ->and(StageChange::query()->where('opportunity_id', $own->id)->count())->toBe(2)
        ->and(DB::table('opportunity_contacts')->where('person_id', $person->id)->count())->toBe(0)
        ->and($asContact->refresh()->title)->toBe('HACCP')
        ->and($otherLost->refresh()->loss_comment)->toBe('Commentaire entreprise')
        ->and(Person::withTrashed()->findOrFail($person->id)->last_name)->toBe(Anonymiser::NAME);
    expect(personalTraces(['Anatole trouve cela trop cher']))->toBe([]);
});

it('lists the opportunities of a person in her data export', function () {
    $this->actingAs(commercial());
    $person = makePerson('Durand', ['is_individual' => true]);
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    affiliate($person, $org);
    makeOpportunity($person, ['title' => 'BTS', 'amount' => 4500]);
    $contact = makeOpportunity($org, ['title' => 'HACCP', 'amount' => 1200]);
    app(OpportunityStage::class)->syncContacts($contact, [$person->id]);
    makeOpportunity($org, ['title' => 'Sans elle']);

    $data = PersonDataExport::for($person->refresh());

    expect($data['opportunites'])->toBe([
        ['titre' => 'BTS', 'role' => 'Compte (particulier)', 'etape' => 'Nouveau', 'montant' => '4500.00', 'supprimee' => false],
        ['titre' => 'HACCP', 'role' => 'Contact', 'etape' => 'Nouveau', 'montant' => '1200.00', 'supprimee' => false],
    ]);
});

it('shows the opportunity events in the history of the fiche', function () {
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Le Bistrot']);
    $stages = app(OpportunityStage::class);
    $this->travel(-10)->minutes();
    $opportunity = makeOpportunity($org, ['title' => 'HACCP']);
    $this->travel(1)->minutes();
    $stages->move($opportunity, pipelineStage('Étude'), null, null, null);
    $this->travel(1)->minutes();
    $stages->move($opportunity, pipelineStage('Proposition'), null, null, null);
    $this->travel(1)->minutes();
    $stages->move($opportunity, pipelineStage('Gagné'), null, null, null);
    $this->travel(1)->minutes();
    $other = makeOpportunity($org, ['title' => 'Cuisine']);
    $this->travel(1)->minutes();
    $stages->move($other, pipelineStage('Perdu'), crmItem(ReferenceList::LossReason, 'Prix')->id, 'secret', null);
    $this->travelBack();

    $entries = collect($this->getJson("/crm/organisations/{$org->id}/timeline")->assertOk()->json('entries'))->where('type', 'opportunity')->values();

    expect($entries->pluck('title')->all())->toBe([
        'Opportunité perdue : « Cuisine »',
        'Opportunité créée : « Cuisine »',
        'Opportunité gagnée : « HACCP »',
        'Opportunité « HACCP » : Étude → Proposition',
        'Opportunité « HACCP » : Nouveau → Étude',
        'Opportunité créée : « HACCP »',
    ])->and($entries[0]['body'])->toBe('Motif : Prix')
        ->and($entries[0]['meta']['opportunity_id'])->toBe($other->id)
        ->and(json_encode($entries->all()))->not->toContain('secret');

    // A person's history shows the opportunities she holds as particulier.
    $person = makePerson('Durand', ['is_individual' => true]);
    makeOpportunity($person, ['title' => 'BTS']);
    expect(collect($this->getJson("/crm/people/{$person->id}/timeline")->json('entries'))->where('type', 'opportunity')->pluck('title')->all())
        ->toBe(['Opportunité créée : « BTS »']);
});

it('keeps the reason of each loss in the history', function () {
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Le Bistrot']);
    $stages = app(OpportunityStage::class);
    $opportunity = makeOpportunity($org, ['title' => 'HACCP']);
    $this->travel(1)->minutes();
    $stages->move($opportunity, pipelineStage('Perdu'), crmItem(ReferenceList::LossReason, 'Prix')->id, null, null);
    $this->travel(1)->minutes();
    $stages->move($opportunity, pipelineStage('Étude'), null, null, null);

    $lost = collect($this->getJson("/crm/organisations/{$org->id}/timeline")->json('entries'))->firstWhere('title', 'Opportunité perdue : « HACCP »');
    expect($lost['body'])->toBe('Motif : Prix');
});

it('hides from a Responsable DAS the history of opportunities outside his DAS', function () {
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $other = crmItem(ReferenceList::Das, 'Métiers supports');
    $org = Organisation::query()->create(['name' => 'Le Bistrot']);
    $org->das()->sync([$mine->id => ['tenant_id' => tenant()->id]]);
    makeOpportunity($org, ['title' => 'Visible', 'das_id' => $mine->id]);
    makeOpportunity($org, ['title' => 'Cachée', 'das_id' => $other->id]);

    $responsable = userWithRole(Role::ResponsableDas);
    followDas($responsable, $mine);

    $titles = collect($this->actingAs($responsable)->getJson("/crm/organisations/{$org->id}/timeline")->assertOk()->json('entries'))
        ->where('type', 'opportunity')->pluck('title')->all();
    expect($titles)->toBe(['Opportunité créée : « Visible »']);
});
