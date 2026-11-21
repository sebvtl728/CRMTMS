<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Gdpr\Anonymiser;
use App\Modules\Crm\Gdpr\PersonDataExport;
use App\Modules\Crm\Organisations\Organisation;
use Carbon\CarbonImmutable;

beforeEach(function () {
    fakeImportDisk();
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

/**
 * @param  array<string, mixed>  $attributes
 */
function gdprActivity(array $attributes): Activity
{
    return Activity::query()->create([
        'type_id' => crmItem(ReferenceList::ActionType, 'Appel')->id,
        'subject' => 'Appel',
        'done_at' => now(),
        ...$attributes,
    ]);
}

it('merge moves actions of an entreprise, trashed ones too', function () {
    $this->actingAs(commercial());
    $keep = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $drop = Organisation::query()->create(['name' => 'Hotel du lac']);
    $moved = gdprActivity(['organisation_id' => $drop->id, 'subject' => 'Appel – Hotel du lac']);
    $trashed = gdprActivity(['organisation_id' => $drop->id]);
    $trashed->delete();

    $this->post('/crm/merge/organisation', ['keep' => $keep->id, 'drop' => $drop->id, 'fields' => []])
        ->assertRedirect(route('crm.organisations.show', $keep));

    expect($moved->refresh()->organisation_id)->toBe($keep->id)
        ->and(Activity::withTrashed()->findOrFail($trashed->id)->organisation_id)->toBe($keep->id)
        ->and(Activity::withTrashed()->where('organisation_id', $drop->id)->count())->toBe(0);

    $titles = collect($this->getJson("/crm/organisations/{$keep->id}/timeline")->json('entries'))->where('type', 'activity')->pluck('title')->all();
    expect($titles)->toBe(['Appel – Hotel du lac']);
});

it('merge moves actions of a person', function () {
    $this->actingAs(commercial());
    $keep = makePerson('Dupont', ['first_name' => 'Jean']);
    $drop = makePerson('Dupond', ['first_name' => 'Jean']);
    $activity = gdprActivity(['person_id' => $drop->id, 'done_at' => null, 'due_at' => now()->addDay()]);

    $this->post('/crm/merge/person', ['keep' => $keep->id, 'drop' => $drop->id, 'fields' => []])
        ->assertRedirect(route('crm.people.show', $keep));

    expect($activity->refresh()->person_id)->toBe($keep->id);
});

it('anonymisation scrubs action reports', function () {
    $this->actingAs(commercial());
    $person = makePerson('Zébulon', ['first_name' => 'Anatole', 'is_individual' => true]);
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    affiliate($person, $org);
    $done = CarbonImmutable::parse('2026-11-10 10:00', 'Europe/Paris');

    $this->post('/crm/activities', [
        'type_id' => crmItem(ReferenceList::ActionType, 'Rendez-vous physique')->id,
        'person_id' => $person->id,
        'organisation_id' => $org->id,
        'done_at' => $done->format('Y-m-d\TH:i'),
        'report' => 'Anatole veut changer de métier',
        'location' => 'Chez Anatole, 4 impasse Verte',
        'result_id' => crmItem(ReferenceList::ActionResult, 'Réussi')->id,
    ])->assertSessionHasNoErrors();
    $mine = Activity::query()->sole();
    expect($mine->subject)->toBe('Rendez-vous physique – Anatole Zébulon');
    // Through her opportunity as particulier.
    $viaOpportunity = gdprActivity(['opportunity_id' => makeOpportunity($person)->id, 'person_id' => null, 'organisation_id' => $org->id,
        'subject' => 'Devis pour Anatole', 'report' => 'Anatole a reçu le devis']);
    $other = gdprActivity(['organisation_id' => $org->id, 'report' => 'Compte rendu entreprise']);

    app(Anonymiser::class)->anonymise($person, 'request');

    $mine->refresh();
    expect($mine->report)->toBeNull()
        ->and($mine->location)->toBeNull()
        ->and($mine->subject)->toBe('Rendez-vous physique')
        ->and($mine->done_at?->equalTo($done))->toBeTrue()
        ->and($mine->result_id)->toBe(crmItem(ReferenceList::ActionResult, 'Réussi')->id)
        ->and($mine->person_id)->toBe($person->id)
        ->and($viaOpportunity->refresh()->report)->toBeNull()
        ->and($viaOpportunity->subject)->toBe('Appel')
        ->and($other->refresh()->report)->toBe('Compte rendu entreprise');
    expect(personalTraces(['Anatole', 'impasse Verte', 'changer de métier']))->toBe([]);
});

it('lists the actions of a person in her data export', function () {
    // Fixed clock: the reminder due on 20/11 must stay « À faire », not « En retard ».
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Europe/Paris'));
    $this->actingAs(commercial());
    $person = makePerson('Durand', ['first_name' => 'Claire']);
    gdprActivity(['person_id' => $person->id, 'subject' => 'Appel – Claire Durand', 'report' => 'Rappeler lundi',
        'done_at' => CarbonImmutable::parse('2026-11-10 10:00', 'Europe/Paris'), 'result_id' => crmItem(ReferenceList::ActionResult, 'Réussi')->id]);
    gdprActivity(['person_id' => $person->id, 'type_id' => crmItem(ReferenceList::ActionType, 'Rappel')->id, 'subject' => 'Rappel – Claire Durand',
        'done_at' => null, 'due_at' => CarbonImmutable::parse('2026-11-20 09:00', 'Europe/Paris')]);
    gdprActivity(['organisation_id' => Organisation::query()->create(['name' => 'X'])->id, 'subject' => 'Sans elle']);

    $data = PersonDataExport::for($person->refresh());

    expect($data['actions'])->toBe([
        ['date' => '2026-11-10T10:00:00+01:00', 'type' => 'Appel', 'objet' => 'Appel – Claire Durand', 'statut' => 'Faite', 'resultat' => 'Réussi', 'motif' => null, 'compte_rendu' => 'Rappeler lundi', 'suite_a_donner' => null, 'lieu' => null, 'supprimee' => false],
        ['date' => '2026-11-20T09:00:00+01:00', 'type' => 'Rappel', 'objet' => 'Rappel – Claire Durand', 'statut' => 'À faire', 'resultat' => null, 'motif' => null, 'compte_rendu' => null, 'suite_a_donner' => null, 'lieu' => null, 'supprimee' => false],
    ]);

    // The file is always complete: a Responsable DAS (who would not see them) cannot run it.
    expect(PersonDataExport::allowedFor(userWithRole(Role::ResponsableDas)))->toBeFalse()
        ->and(PersonDataExport::allowedFor(direction()))->toBeTrue();
});
