<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Crm\Activities\ActivitiesProvider;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Gdpr\PersonDataExport;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Support\LinkedData;
use App\Modules\Crm\Visibility\CrmVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function actionType(string $label): string
{
    return crmItem(ReferenceList::ActionType, $label)->id;
}

function actionResult(string $label): string
{
    return crmItem(ReferenceList::ActionResult, $label)->id;
}

function parisTime(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Europe/Paris');
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makeActivity(array $attributes = []): Activity
{
    return Activity::query()->create([
        'type_id' => actionType('Appel'),
        'subject' => 'Appel',
        'done_at' => now(),
        ...$attributes,
    ]);
}

it('creates a quick action from an entreprise in one request with only the type and the fiche', function () {
    $this->travelTo(parisTime('2026-11-14 10:15:00'));
    $user = commercial();
    $this->actingAs($user);
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);

    $this->from("/crm/organisations/{$org->id}")
        ->post('/crm/activities', ['type_id' => actionType('Appel'), 'organisation_id' => $org->id])
        ->assertSessionHasNoErrors()
        ->assertRedirect("/crm/organisations/{$org->id}");

    $activity = Activity::query()->sole();
    expect($activity->subject)->toBe('Appel – Hôtel du Lac')
        ->and($activity->organisation_id)->toBe($org->id)
        ->and($activity->done_at?->equalTo(parisTime('2026-11-14 10:15:00')))->toBeTrue()
        ->and($activity->due_at)->toBeNull()
        ->and($activity->responsible_user_id)->toBe($user->id)
        ->and($activity->created_by)->toBe($user->id)
        ->and($activity->status())->toBe('done')
        ->and($org->refresh()->last_contact_at?->equalTo(parisTime('2026-11-14 10:15:00')))->toBeTrue();
});

it('a done action sets the last contact of the person and of her active entreprise', function () {
    $this->travelTo(parisTime('2026-11-14 16:00:00'));
    $this->actingAs(commercial());
    $current = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $former = Organisation::query()->create(['name' => 'Ancien employeur']);
    $person = makePerson('Dupont', ['first_name' => 'Jean']);
    affiliate($person, $current);
    affiliate($person, $former, ['ended_on' => '2025-01-01']);

    $this->post('/crm/activities', [
        'type_id' => actionType('Appel'),
        'person_id' => $person->id,
        'done_at' => '2026-11-14T14:30',
        'result_id' => actionResult('Réussi'),
        'report' => 'Intéressé par le HACCP',
        'duration_minutes' => 15,
    ])->assertSessionHasNoErrors();

    $activity = Activity::query()->sole();
    expect($activity->subject)->toBe('Appel – Jean Dupont')
        ->and($activity->done_at?->equalTo(parisTime('2026-11-14 14:30')))->toBeTrue()
        ->and($activity->duration_minutes)->toBe(15)
        ->and($person->refresh()->last_contact_at?->equalTo(parisTime('2026-11-14 14:30')))->toBeTrue()
        ->and($current->refresh()->last_contact_at?->equalTo(parisTime('2026-11-14 14:30')))->toBeTrue()
        ->and($former->refresh()->last_contact_at)->toBeNull();
});

it('an action on an opportunity is attached to its fiche and records the contact there', function () {
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Le Bistrot']);
    $opportunity = makeOpportunity($org, ['title' => 'HACCP']);

    $this->post('/crm/activities', ['type_id' => actionType('E-mail'), 'opportunity_id' => $opportunity->id])->assertSessionHasNoErrors();

    $activity = Activity::query()->sole();
    expect($activity->opportunity_id)->toBe($opportunity->id)
        ->and($activity->organisation_id)->toBe($org->id)
        ->and($activity->subject)->toBe('E-mail – Le Bistrot')
        ->and($org->refresh()->last_contact_at)->not->toBeNull();

    // A fiche other than the opportunity's is refused.
    $other = Organisation::query()->create(['name' => 'Autre']);
    $this->post('/crm/activities', ['type_id' => actionType('E-mail'), 'opportunity_id' => $opportunity->id, 'organisation_id' => $other->id])
        ->assertSessionHasErrors(['opportunity_id' => 'L\'action doit concerner la fiche de cette opportunité.']);
});

it('creates the next follow-up when a done action is saved, linked to the same fiches and responsible', function () {
    $this->travelTo(parisTime('2026-11-14 10:00:00'));
    $user = commercial();
    $responsible = commercial();
    $this->actingAs($user);
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $person = makePerson('Dupont', ['first_name' => 'Jean']);
    affiliate($person, $org);
    $opportunity = makeOpportunity($org);

    $this->post('/crm/activities', [
        'type_id' => actionType('Appel'),
        'organisation_id' => $org->id,
        'person_id' => $person->id,
        'opportunity_id' => $opportunity->id,
        'responsible_user_id' => $responsible->id,
        'next_type_id' => actionType('Rappel'),
        'next_due_at' => '2026-11-21',
    ])->assertSessionHasNoErrors();

    $next = Activity::query()->whereNull('done_at')->sole();
    expect(Activity::query()->count())->toBe(2)
        ->and($next->type_id)->toBe(actionType('Rappel'))
        ->and($next->subject)->toBe('Rappel – Jean Dupont')
        ->and($next->due_at?->equalTo(parisTime('2026-11-21 09:00')))->toBeTrue()
        ->and($next->organisation_id)->toBe($org->id)
        ->and($next->person_id)->toBe($person->id)
        ->and($next->opportunity_id)->toBe($opportunity->id)
        ->and($next->responsible_user_id)->toBe($responsible->id)
        ->and($next->status())->toBe('todo');

    // The follow-up needs both its type and its date, never in the past.
    $this->post('/crm/activities', ['type_id' => actionType('Appel'), 'organisation_id' => $org->id, 'next_type_id' => actionType('Rappel')])
        ->assertSessionHasErrors('next_due_at');
    $this->post('/crm/activities', ['type_id' => actionType('Appel'), 'organisation_id' => $org->id, 'next_type_id' => actionType('Rappel'), 'next_due_at' => '2026-11-13'])
        ->assertSessionHasErrors('next_due_at');
});

it('completes a pending action with result, report and follow-up', function () {
    $this->travelTo(parisTime('2026-11-14 11:00:00'));
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $pending = makeActivity(['organisation_id' => $org->id, 'done_at' => null, 'due_at' => parisTime('2026-11-14 09:00')]);
    expect($org->refresh()->last_contact_at)->toBeNull();

    $this->post("/crm/activities/{$pending->id}/done", [
        'result_id' => actionResult('En attente'),
        'report' => 'Rappeler après le comité',
        'next_type_id' => actionType('Suivi'),
        'next_due_at' => '2026-12-14T10:30',
    ])->assertSessionHasNoErrors();

    $pending->refresh();
    expect($pending->done_at?->equalTo(parisTime('2026-11-14 11:00')))->toBeTrue()
        ->and($pending->result_id)->toBe(actionResult('En attente'))
        ->and($pending->report)->toBe('Rappeler après le comité')
        ->and($org->refresh()->last_contact_at?->equalTo(parisTime('2026-11-14 11:00')))->toBeTrue();
    $next = Activity::query()->whereKeyNot($pending->id)->sole();
    expect($next->due_at?->equalTo(parisTime('2026-12-14 10:30')))->toBeTrue()
        ->and($next->subject)->toBe('Suivi – Hôtel du Lac');

    // Already done: refused.
    $this->post("/crm/activities/{$pending->id}/done", [])->assertSessionHasErrors(['done_at' => 'Cette action est déjà faite.']);
});

it('refuses a done action dated in the future', function () {
    $this->travelTo(parisTime('2026-11-14 10:00:00'));
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);

    $this->post('/crm/activities', ['type_id' => actionType('Appel'), 'organisation_id' => $org->id, 'done_at' => '2026-11-14T10:30'])
        ->assertSessionHasErrors(['done_at' => 'Une action faite ne peut pas être datée dans le futur.']);
    $pending = makeActivity(['organisation_id' => $org->id, 'done_at' => null, 'due_at' => now()]);
    $this->post("/crm/activities/{$pending->id}/done", ['done_at' => '2026-11-15T08:00'])
        ->assertSessionHasErrors(['done_at' => 'Une action faite ne peut pas être datée dans le futur.']);
    $this->put("/crm/activities/{$pending->id}", ['type_id' => actionType('Appel'), 'subject' => 'Appel', 'done_at' => '2026-11-15T08:00'])
        ->assertSessionHasErrors('done_at');

    expect(Activity::query()->whereNotNull('done_at')->count())->toBe(0)
        ->and($org->refresh()->last_contact_at)->toBeNull();
});

it('validates the quick entry in French', function () {
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);

    $this->post('/crm/activities', ['organisation_id' => $org->id])->assertSessionHasErrors(['type_id' => 'Choisissez le type d\'action.']);
    $this->post('/crm/activities', ['type_id' => actionType('Appel')])
        ->assertSessionHasErrors(['fiche' => 'Choisissez une entreprise, une personne ou une opportunité.']);
    $this->post('/crm/activities', ['type_id' => actionType('Appel'), 'organisation_id' => $org->id, 'done' => false])
        ->assertSessionHasErrors(['due_at' => 'Indiquez l\'échéance de l\'action à faire.']);
    $this->post('/crm/activities', ['type_id' => crmItem(ReferenceList::Priority, 'Urgent')->id, 'organisation_id' => $org->id])
        ->assertSessionHasErrors('type_id');
    $this->post('/crm/activities', ['type_id' => actionType('Appel'), 'organisation_id' => $org->id, 'due_at' => 'demain', 'done' => false])
        ->assertSessionHasErrors(['due_at' => 'Indiquez une date valide.']);

    // A to-do with its due date: not done, no contact recorded.
    $this->post('/crm/activities', ['type_id' => actionType('Rappel'), 'organisation_id' => $org->id, 'done' => false, 'due_at' => '2026-12-01T14:00'])
        ->assertSessionHasNoErrors();
    expect(Activity::query()->sole()->done_at)->toBeNull()
        ->and($org->refresh()->last_contact_at)->toBeNull();
});

it('derives the status in Europe/Paris: due today is not overdue before midnight', function () {
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $today = makeActivity(['organisation_id' => $org->id, 'done_at' => null, 'due_at' => parisTime('2026-11-14 23:30')]);
    $yesterday = makeActivity(['organisation_id' => $org->id, 'done_at' => null, 'due_at' => parisTime('2026-11-13 23:30')]);
    $done = makeActivity(['organisation_id' => $org->id, 'due_at' => parisTime('2026-11-01 09:00'), 'done_at' => parisTime('2026-11-02 09:00')]);

    // 23:45 Paris is 22:45 UTC (same day); 00:30 Paris is still the previous day in UTC.
    $now = parisTime('2026-11-14 23:45');
    expect($today->status($now))->toBe('todo')
        ->and($yesterday->status($now))->toBe('overdue')
        ->and($done->status($now))->toBe('done')
        ->and($today->status(parisTime('2026-11-15 00:30')))->toBe('overdue')
        ->and(Activity::STATUS_LABELS[$yesterday->status($now)])->toBe('En retard');
});

it('updates and deletes an action, the last contact following', function () {
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $older = makeActivity(['organisation_id' => $org->id, 'done_at' => parisTime('2026-11-01 10:00')]);
    $latest = makeActivity(['organisation_id' => $org->id, 'done_at' => parisTime('2026-11-10 10:00')]);
    expect($org->refresh()->last_contact_at?->equalTo(parisTime('2026-11-10 10:00')))->toBeTrue();

    $this->put("/crm/activities/{$latest->id}", [
        'type_id' => actionType('Rendez-vous physique'),
        'subject' => 'RDV au restaurant',
        'done_at' => '2026-11-05T10:00',
        'location' => 'Annecy',
        'report' => 'Visite des cuisines',
    ])->assertSessionHasNoErrors();
    $latest->refresh();
    expect($latest->subject)->toBe('RDV au restaurant')
        ->and($latest->location)->toBe('Annecy')
        ->and($org->refresh()->last_contact_at?->equalTo(parisTime('2026-11-05 10:00')))->toBeTrue();

    $this->delete("/crm/activities/{$latest->id}")->assertSessionHasNoErrors()->assertRedirect();
    expect(Activity::query()->find($latest->id))->toBeNull()
        ->and(Activity::withTrashed()->find($latest->id))->not->toBeNull()
        ->and($org->refresh()->last_contact_at?->equalTo(parisTime('2026-11-01 10:00')))->toBeTrue();

    $older->delete();
    expect($org->refresh()->last_contact_at)->toBeNull();
});

it('masks the report, subject and place in the audit', function () {
    $this->actingAs(commercial());
    $person = makePerson('Dupont', ['first_name' => 'Jean']);

    $this->post('/crm/activities', [
        'type_id' => actionType('Rendez-vous physique'),
        'person_id' => $person->id,
        'report' => 'Jean hésite à cause de sa santé',
        'location' => '12 rue des Lilas',
    ])->assertSessionHasNoErrors();
    $activity = Activity::query()->sole();
    $activity->update(['report' => 'Autre compte rendu secret']);

    $logs = AuditLog::query()->where('auditable_id', $activity->id)->get();
    $created = $logs->firstWhere('event', 'created');
    expect($created->new_values['report'])->toBe('[donnée personnelle]')
        ->and($created->new_values['subject'])->toBe('[donnée personnelle]')
        ->and($created->new_values['location'])->toBe('[donnée personnelle]')
        ->and($created->new_values['type_id'])->toBe(actionType('Rendez-vous physique'))
        ->and(json_encode($logs->toArray(), JSON_UNESCAPED_UNICODE))->not->toContain('santé')->not->toContain('Lilas')->not->toContain('secret')->not->toContain('Dupont');
});

it('answers 404 for the action of a fiche a Responsable DAS cannot see', function () {
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $other = crmItem(ReferenceList::Das, 'Métiers supports');
    $visibleOrg = Organisation::query()->create(['name' => 'Visible']);
    $visibleOrg->das()->sync([$mine->id => ['tenant_id' => tenant()->id]]);
    $hiddenOrg = Organisation::query()->create(['name' => 'Cachée']);
    $hiddenOrg->das()->sync([$other->id => ['tenant_id' => tenant()->id]]);
    $this->actingAs(commercial());
    $visible = makeActivity(['organisation_id' => $visibleOrg->id]);
    $hidden = makeActivity(['organisation_id' => $hiddenOrg->id]);
    // Visible through its opportunity (DAS followed) even on a hidden fiche.
    $viaOpportunity = makeActivity(['organisation_id' => $hiddenOrg->id, 'opportunity_id' => makeOpportunity($hiddenOrg, ['das_id' => $mine->id])->id]);

    $responsable = userWithRole(Role::ResponsableDas);
    followDas($responsable, $mine);

    expect(CrmVisibility::activities(Activity::query(), $responsable)->orderBy('id')->pluck('id')->all())->toBe(collect([$visible->id, $viaOpportunity->id])->sort()->values()->all());

    $this->actingAs($responsable);
    $this->getJson("/crm/activities/{$visible->id}")->assertOk()->assertJsonPath('activity.subject', 'Appel');
    $this->getJson("/crm/activities/{$viaOpportunity->id}")->assertOk();
    $this->getJson("/crm/activities/{$hidden->id}")->assertNotFound();
    $this->put("/crm/activities/{$hidden->id}", ['type_id' => actionType('Appel'), 'subject' => 'x'])->assertNotFound();
    $this->delete("/crm/activities/{$hidden->id}")->assertNotFound();
    $this->post("/crm/activities/{$hidden->id}/done")->assertNotFound();
    $this->getJson('/crm/activities/not-a-uuid')->assertNotFound();

    // No creation on a fiche he cannot see (even when granted crm.activities.update).
    $responsable->givePermissionTo('crm.activities.update');
    $this->post('/crm/activities', ['type_id' => actionType('Appel'), 'organisation_id' => $hiddenOrg->id])->assertSessionHasErrors('organisation_id');
});

it('reads the action of a trashed fiche only with crm.activities.delete, and never edits it', function () {
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Supprimée']);
    $activity = makeActivity(['organisation_id' => $org->id]);
    $org->delete();

    $this->getJson("/crm/activities/{$activity->id}")->assertOk()->assertJsonPath('can.update', false);
    $this->put("/crm/activities/{$activity->id}", ['type_id' => actionType('Appel'), 'subject' => 'x'])->assertForbidden();
    $this->delete("/crm/activities/{$activity->id}")->assertForbidden();
    $this->post('/crm/activities', ['type_id' => actionType('Appel'), 'organisation_id' => $org->id])->assertSessionHasErrors('organisation_id');

    $this->actingAs(assistant());
    $this->getJson("/crm/activities/{$activity->id}")->assertNotFound();
});

it('refuses an action on an anonymised person', function () {
    $this->actingAs(commercial());
    $person = makePerson('Anonyme');
    $person->forceFill(['anonymised_at' => now()])->save();

    $this->post('/crm/activities', ['type_id' => actionType('Appel'), 'person_id' => $person->id])
        ->assertSessionHasErrors(['person_id' => 'Cette personne n\'existe pas ou n\'est plus disponible.']);
});

it('shows the actions in the history of the fiche and of the people concerned', function () {
    $this->travelTo(parisTime('2026-11-14 12:00'));
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $person = makePerson('Dupont', ['first_name' => 'Jean']);
    affiliate($person, $org);
    makeActivity(['organisation_id' => $org->id, 'person_id' => $person->id, 'subject' => 'Appel – Jean Dupont', 'report' => 'Très intéressé',
        'result_id' => actionResult('Réussi'), 'done_at' => parisTime('2026-11-14 10:00')]);
    makeActivity(['organisation_id' => $org->id, 'type_id' => actionType('Rappel'), 'subject' => 'Rappel – Hôtel du Lac',
        'done_at' => null, 'due_at' => parisTime('2026-11-13 09:00')]);
    $opportunity = makeOpportunity($org, ['title' => 'HACCP']);
    makeActivity(['opportunity_id' => $opportunity->id, 'organisation_id' => $org->id, 'subject' => 'Envoi', 'done_at' => parisTime('2026-11-12 10:00')]);

    $entries = collect($this->getJson("/crm/organisations/{$org->id}/timeline")->assertOk()->json('entries'))->where('type', 'activity')->values();
    expect($entries->pluck('title')->all())->toBe(['Appel – Jean Dupont', 'Rappel – Hôtel du Lac', 'Envoi'])
        ->and($entries[0]['body'])->toBe('Très intéressé')
        ->and($entries[0]['meta']['status'])->toBe('done')
        ->and($entries[0]['meta']['result'])->toBe('Réussi')
        ->and($entries[0]['meta']['type'])->toBe('Appel')
        ->and($entries[1]['meta']['status'])->toBe('overdue')
        ->and($entries[1]['meta']['status_label'])->toBe('En retard')
        ->and($entries[2]['meta']['opportunity_id'])->toBe($opportunity->id);

    $personTitles = collect($this->getJson("/crm/people/{$person->id}/timeline")->json('entries'))->where('type', 'activity')->pluck('title')->all();
    expect($personTitles)->toBe(['Appel – Jean Dupont']);
});

it('the history provider fails closed without a signed-in user', function () {
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    makeActivity(['organisation_id' => $org->id]);
    auth()->logout();

    expect(app(ActivitiesProvider::class)->entriesFor($org, null, 30))->toHaveCount(0);
});

it('registers actions as linked data and their reference items as used', function () {
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $person = makePerson('Dupont');
    expect(LinkedData::any($org))->toBeFalse();

    $activity = makeActivity(['organisation_id' => $org->id, 'person_id' => $person->id, 'result_id' => actionResult('Échec')]);
    $activity->delete();

    expect(LinkedData::keys())->toContain('crm.activities')
        ->and(LinkedData::any($org))->toBeTrue()
        ->and(LinkedData::any($person))->toBeTrue()
        ->and(ReferenceUsage::isInUse(crmItem(ReferenceList::ActionType, 'Appel')))->toBeTrue()
        ->and(ReferenceUsage::isInUse(crmItem(ReferenceList::ActionResult, 'Échec')))->toBeTrue()
        ->and(ReferenceUsage::isInUse(crmItem(ReferenceList::ActionResult, 'Réussi')))->toBeFalse();
});

it('enforces at least one fiche and a date in the database', function () {
    $this->actingAs(commercial());
    // Savepoints: the test transaction survives the refused inserts.
    expect(fn () => DB::transaction(fn () => makeActivity()))->toThrow(QueryException::class, 'activities_subject_check');
    $org = Organisation::query()->create(['name' => 'X']);
    expect(fn () => DB::transaction(fn () => makeActivity(['organisation_id' => $org->id, 'done_at' => null])))->toThrow(QueryException::class, 'activities_date_check');
});

it('lists the actions of an opportunity on its page', function () {
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Le Bistrot']);
    $opportunity = makeOpportunity($org, ['title' => 'HACCP']);
    makeActivity(['opportunity_id' => $opportunity->id, 'organisation_id' => $org->id, 'subject' => 'Devis envoyé', 'done_at' => parisTime('2026-11-10 10:00')]);
    makeActivity(['opportunity_id' => $opportunity->id, 'organisation_id' => $org->id, 'subject' => 'Relancer', 'done_at' => null, 'due_at' => parisTime('2026-11-20 09:00')]);
    makeActivity(['organisation_id' => $org->id, 'subject' => 'Hors opportunité']);

    $this->get("/crm/opportunities/{$opportunity->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->has('activities', 2)
            ->where('activities.0.subject', 'Relancer')
            ->where('activities.0.status', 'todo')
            ->where('activities.1.subject', 'Devis envoyé'));
});

it('hides from a Responsable DAS the actions of an opportunity outside his DAS, even on a fiche he sees', function () {
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $other = crmItem(ReferenceList::Das, 'Métiers supports');
    $org = Organisation::query()->create(['name' => 'Visible']);
    $org->das()->sync([$mine->id => ['tenant_id' => tenant()->id]]);
    $person = makePerson('Dupont', ['first_name' => 'Jean']);
    affiliate($person, $org);
    $this->actingAs(commercial());
    $foreign = makeOpportunity($org, ['title' => 'Autre DAS', 'das_id' => $other->id]);
    $hidden = makeActivity(['organisation_id' => $org->id, 'person_id' => $person->id, 'opportunity_id' => $foreign->id,
        'subject' => 'Action cachée', 'report' => 'Secret DAS B']);
    $plain = makeActivity(['organisation_id' => $org->id, 'person_id' => $person->id, 'subject' => 'Action visible']);

    $responsable = userWithRole(Role::ResponsableDas);
    followDas($responsable, $mine);
    expect(CrmVisibility::activities(Activity::query(), $responsable)->pluck('id')->all())->toBe([$plain->id]);

    $this->actingAs($responsable);
    $this->getJson("/crm/activities/{$hidden->id}")->assertNotFound();
    $this->put("/crm/activities/{$hidden->id}", ['type_id' => actionType('Appel'), 'subject' => 'x'])->assertNotFound();
    $this->delete("/crm/activities/{$hidden->id}")->assertNotFound();
    $this->post("/crm/activities/{$hidden->id}/done")->assertNotFound();
    $this->getJson("/crm/activities/{$plain->id}")->assertOk();

    foreach (["/crm/organisations/{$org->id}/timeline", "/crm/people/{$person->id}/timeline"] as $url) {
        $titles = collect($this->getJson($url)->assertOk()->json('entries'))->where('type', 'activity')->pluck('title')->all();
        expect($titles)->toBe(['Action visible']);
    }
    expect(array_column(PersonDataExport::for($person->refresh(), $responsable)['actions'], 'objet'))->toBe(['Action visible']);

    // System-level: the contact date still counts the hidden action.
    expect($org->refresh()->last_contact_at)->not->toBeNull();
});
