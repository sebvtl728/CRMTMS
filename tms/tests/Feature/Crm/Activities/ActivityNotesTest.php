<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\Activities\Events\ActivityChanged;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Gdpr\Anonymiser;
use App\Modules\Crm\Gdpr\PersonDataExport;
use App\Modules\Crm\Organisations\Organisation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

// « Motif » (purpose) and « Suite à donner » (next_steps) of an action:
// typed in the quick entry, « Modifier », « Fait » and the meeting report,
// shown in À faire, the planning and the histories.

beforeEach(function () {
    fakeImportDisk();
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function anAt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Europe/Paris');
}

function anType(string $label): string
{
    return crmItem(ReferenceList::ActionType, $label)->id;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function anTodo(User $responsible, array $attributes = []): Activity
{
    return Activity::query()->create([
        'type_id' => anType('Appel'),
        'subject' => 'Rappeler le chef',
        'due_at' => anAt('2026-11-18 09:00'),
        'organisation_id' => Organisation::query()->firstOrCreate(['name' => 'Hôtel du Lac'])->id,
        'responsible_user_id' => $responsible->id,
        ...$attributes,
    ]);
}

it('records the purpose and the next steps of an action to do from the quick entry', function () {
    $this->travelTo(anAt('2026-11-16 10:00'));
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);

    $this->post('/crm/activities', [
        'type_id' => anType('Appel'),
        'organisation_id' => $org->id,
        'done' => false,
        'due_at' => '2026-11-18T09:00',
        'purpose' => '  Relancer le devis HACCP  ',
        'next_steps' => 'Envoyer le programme détaillé',
    ])->assertSessionHasNoErrors();

    $activity = Activity::query()->sole();
    expect($activity->purpose)->toBe('Relancer le devis HACCP')
        ->and($activity->next_steps)->toBe('Envoyer le programme détaillé')
        ->and($activity->done_at)->toBeNull();
});

it('keeps them optional and validates their length in French', function () {
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);

    $this->post('/crm/activities', ['type_id' => anType('Appel'), 'organisation_id' => $org->id, 'purpose' => '', 'next_steps' => ''])
        ->assertSessionHasNoErrors();
    expect(Activity::query()->sole())->purpose->toBeNull()->next_steps->toBeNull();

    $this->post('/crm/activities', [
        'type_id' => anType('Appel'),
        'organisation_id' => $org->id,
        'purpose' => str_repeat('a', 2001),
        'next_steps' => str_repeat('b', 2001),
    ])->assertSessionHasErrors([
        'purpose' => 'Le motif ne peut pas dépasser 2 000 caractères.',
        'next_steps' => 'La suite à donner ne peut pas dépasser 2 000 caractères.',
    ]);
});

it('edits them on an action to do and on a done action', function () {
    $this->travelTo(anAt('2026-11-16 10:00'));
    $me = commercial();
    $this->actingAs($me);
    $todo = anTodo($me, ['purpose' => 'Ancien motif']);
    $done = anTodo($me, ['due_at' => null, 'done_at' => anAt('2026-11-14 11:00')]);

    $this->put("/crm/activities/{$todo->id}", ['type_id' => anType('Appel'), 'subject' => 'Rappeler le chef',
        'purpose' => 'Nouveau motif', 'next_steps' => 'Proposer une date'])->assertSessionHasNoErrors();
    $this->put("/crm/activities/{$done->id}", ['type_id' => anType('Appel'), 'subject' => 'Appel fait',
        'purpose' => 'Présenter la formation', 'next_steps' => 'Rappeler en janvier'])->assertSessionHasNoErrors();

    expect($todo->refresh())->purpose->toBe('Nouveau motif')->next_steps->toBe('Proposer une date')
        ->and($done->refresh())->purpose->toBe('Présenter la formation')->next_steps->toBe('Rappeler en janvier');

    // Cleared when emptied.
    $this->put("/crm/activities/{$todo->id}", ['type_id' => anType('Appel'), 'subject' => 'Rappeler le chef', 'purpose' => '', 'next_steps' => ''])
        ->assertSessionHasNoErrors();
    expect($todo->refresh())->purpose->toBeNull()->next_steps->toBeNull();

    $this->getJson("/crm/activities/{$done->id}")->assertOk()
        ->assertJsonPath('activity.purpose', 'Présenter la formation')
        ->assertJsonPath('activity.next_steps', 'Rappeler en janvier');
});

it('records them with « Fait » and with the meeting report', function () {
    $this->travelTo(anAt('2026-11-18 16:00'));
    $me = commercial();
    $this->actingAs($me);
    $call = anTodo($me, ['purpose' => 'Relancer le devis']);
    $meeting = anTodo($me, ['type_id' => anType('Rendez-vous physique'), 'subject' => 'RDV', 'due_at' => anAt('2026-11-18 14:00')]);

    $this->post("/crm/activities/{$call->id}/done", ['report' => 'Devis accepté', 'next_steps' => 'Préparer la convention'])
        ->assertSessionHasNoErrors();
    expect($call->refresh())->done_at->not->toBeNull()
        ->purpose->toBe('Relancer le devis')
        ->next_steps->toBe('Préparer la convention');

    $this->post('/crm/report', ['activity_id' => $meeting->id, 'report' => 'Besoin confirmé',
        'purpose' => 'Découvrir les besoins', 'next_steps' => 'Envoyer un devis'])->assertSessionHasNoErrors();
    expect($meeting->refresh())->done_at->not->toBeNull()
        ->purpose->toBe('Découvrir les besoins')
        ->next_steps->toBe('Envoyer un devis');
});

it('shows them in À faire, the planning and the history of the fiche', function () {
    $this->travelTo(anAt('2026-11-18 08:00'));
    $me = commercial();
    $this->actingAs($me);
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    anTodo($me, ['organisation_id' => $org->id, 'purpose' => 'Relancer le devis', 'next_steps' => 'Proposer une date', 'location' => 'Annecy']);

    $todo = $this->get('/crm/todo')->assertOk()->viewData('page')['props']['sections']['today'][0];
    expect($todo['purpose'])->toBe('Relancer le devis')->and($todo['next_steps'])->toBe('Proposer une date');

    $planning = collect($this->get('/crm/planning')->assertOk()->viewData('page')['props']['days'])->flatMap(fn ($d) => $d['items'])->sole();
    expect($planning['purpose'])->toBe('Relancer le devis');

    $entry = collect($this->getJson("/crm/organisations/{$org->id}/timeline")->assertOk()->json('entries'))->firstWhere('type', 'activity');
    expect($entry['body'])->toBe("Lieu : Annecy\n\nMotif : Relancer le devis\n\nSuite à donner : Proposer une date");
});

it('masks them in the audit', function () {
    $this->actingAs(commercial());
    $person = makePerson('Dupont', ['first_name' => 'Jean']);

    $this->post('/crm/activities', ['type_id' => anType('Appel'), 'person_id' => $person->id,
        'purpose' => 'Jean veut se reconvertir', 'next_steps' => 'Rappeler sa conseillère'])->assertSessionHasNoErrors();
    $activity = Activity::query()->sole();
    $activity->update(['purpose' => 'Motif secret', 'next_steps' => 'Suite secrète']);

    $logs = AuditLog::query()->where('auditable_id', $activity->id)->get();
    expect($logs->firstWhere('event', 'created')->new_values)->toMatchArray(['purpose' => '[donnée personnelle]', 'next_steps' => '[donnée personnelle]'])
        ->and(json_encode($logs->toArray(), JSON_UNESCAPED_UNICODE))->not->toContain('reconvertir')->not->toContain('conseillère')->not->toContain('secret');
});

it('clears them on anonymisation and lists them in the data export', function () {
    $this->actingAs(commercial());
    $person = makePerson('Zébulon', ['first_name' => 'Anatole']);
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $mine = Activity::query()->create(['type_id' => anType('Appel'), 'subject' => 'Appel', 'person_id' => $person->id,
        'done_at' => anAt('2026-11-10 10:00'), 'purpose' => 'Anatole change de métier', 'next_steps' => 'Rappeler Anatole']);
    $other = Activity::query()->create(['type_id' => anType('Appel'), 'subject' => 'Appel', 'organisation_id' => $org->id,
        'done_at' => anAt('2026-11-10 10:00'), 'purpose' => 'Motif entreprise', 'next_steps' => 'Suite entreprise']);

    $export = PersonDataExport::for($person->refresh());
    expect($export['actions'][0])->toMatchArray(['motif' => 'Anatole change de métier', 'suite_a_donner' => 'Rappeler Anatole']);

    app(Anonymiser::class)->anonymise($person, 'request');

    expect($mine->refresh())->purpose->toBeNull()->next_steps->toBeNull()
        ->and($other->refresh())->purpose->toBe('Motif entreprise')->next_steps->toBe('Suite entreprise');
    expect(personalTraces(['change de métier', 'Rappeler Anatole']))->toBe([]);
});

it('announces a new purpose to the calendars, not new next steps', function () {
    $me = commercial();
    $this->actingAs($me);
    $todo = anTodo($me);
    Event::fake([ActivityChanged::class]);

    $todo->update(['next_steps' => 'Proposer une date']);
    Event::assertNotDispatched(ActivityChanged::class);

    $todo->update(['purpose' => 'Relancer le devis']);
    Event::assertDispatched(ActivityChanged::class, fn (ActivityChanged $e) => $e->activityId === $todo->id);
});
