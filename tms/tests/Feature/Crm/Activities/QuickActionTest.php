<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

it('gives the quick entry its options and the fiche of the current page', function () {
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);

    $json = $this->getJson("/crm/activities/options?organisation={$org->id}")->assertOk()->json();

    expect(array_column($json['types'], 'label'))->toBe(['Prise de rendez-vous', 'Rappel', 'Envoi de nouveauté', 'Suivi', 'Rendez-vous physique', 'Envoi de devis', 'Appel', 'E-mail', 'Note'])
        ->and(collect($json['types'])->firstWhere('label', 'Appel')['code'])->toBe('appel')
        ->and(array_column($json['results'], 'label'))->toBe(['Réussi', 'Échec', 'En attente', 'À replanifier'])
        ->and($json['context'])->toBe([
            'organisation' => ['id' => $org->id, 'name' => 'Hôtel du Lac'],
            'person' => null,
            'opportunity' => null,
        ]);
});

it('prefills the fiche of an opportunity and of a person', function () {
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Le Bistrot']);
    $opportunity = makeOpportunity($org, ['title' => 'HACCP']);
    $person = makePerson('Dupont', ['first_name' => 'Jean']);

    expect($this->getJson("/crm/activities/options?opportunity={$opportunity->id}")->json('context'))->toBe([
        'organisation' => ['id' => $org->id, 'name' => 'Le Bistrot'],
        'person' => null,
        'opportunity' => ['id' => $opportunity->id, 'name' => 'HACCP'],
    ])->and($this->getJson("/crm/activities/options?person={$person->id}")->json('context.person'))->toBe(['id' => $person->id, 'name' => 'Jean Dupont']);
});

it('leaves out a fiche the user may not use', function () {
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $hidden = Organisation::query()->create(['name' => 'Cachée']);
    $trashed = Organisation::query()->create(['name' => 'Supprimée']);
    $trashed->delete();

    $this->actingAs(commercial());
    expect($this->getJson("/crm/activities/options?organisation={$trashed->id}")->json('context.organisation'))->toBeNull()
        ->and($this->getJson('/crm/activities/options?organisation=pas-un-id')->assertOk()->json('context.organisation'))->toBeNull();

    $responsable = userWithRole(Role::ResponsableDas);
    followDas($responsable, $mine);
    $this->actingAs($responsable)->getJson("/crm/activities/options?organisation={$hidden->id}")->assertForbidden();
    $responsable->givePermissionTo('crm.activities.update');
    expect($this->getJson("/crm/activities/options?organisation={$hidden->id}")->json('context.organisation'))->toBeNull();
});

it('quick entry needs crm.activities.update', function () {
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $this->actingAs(direction(twoFactor: true));

    $this->post('/crm/activities', ['type_id' => crmItem(ReferenceList::ActionType, 'Appel')->id, 'organisation_id' => $org->id])->assertForbidden();
    expect(Activity::query()->count())->toBe(0);
});

it('an assistant records an action and its follow-up in one go', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00', 'Europe/Paris'));
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $this->actingAs(assistant());

    $this->post('/crm/activities', [
        'type_id' => crmItem(ReferenceList::ActionType, 'Appel')->id,
        'organisation_id' => $org->id,
        'done' => true,
        'result_id' => crmItem(ReferenceList::ActionResult, 'Réussi')->id,
        'report' => 'OK pour un devis',
        'next_type_id' => crmItem(ReferenceList::ActionType, 'Envoi de devis')->id,
        'next_due_at' => '2026-11-15',
    ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Action enregistrée, prochaine relance prévue.');

    expect(Activity::query()->count())->toBe(2);
});
