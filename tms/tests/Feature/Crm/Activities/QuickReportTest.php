<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Opportunities\StageChange;
use App\Modules\Crm\Organisations\Organisation;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function reportAt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Europe/Paris');
}

/**
 * A meeting to do.
 *
 * @param  array<string, mixed>  $attributes
 */
function meeting(User $responsible, string $due, array $attributes = []): Activity
{
    return Activity::query()->create([
        'type_id' => crmItem(ReferenceList::ActionType, 'Rendez-vous physique')->id,
        'subject' => 'RDV '.$due,
        'due_at' => reportAt($due),
        'organisation_id' => $attributes['organisation_id'] ?? Organisation::query()->firstOrCreate(['name' => 'Hôtel du Lac'])->id,
        'responsible_user_id' => $responsible->id,
        ...$attributes,
    ]);
}

/**
 * What a report request may have changed.
 *
 * @return array<string, mixed>
 */
function reportSnapshot(Opportunity $opportunity): array
{
    return [
        'activities' => Activity::withTrashed()->orderBy('id')->get()->map(fn (Activity $a) => [$a->id, $a->done_at?->toIso8601String(), $a->report, $a->result_id])->all(),
        'stage' => $opportunity->fresh()?->stage_id,
        'changes' => StageChange::query()->count(),
    ];
}

it('lists my meetings of the day with the choices of the form', function () {
    $this->travelTo(reportAt('2026-11-18 16:00:00'));
    $user = commercial();
    $organisation = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $opportunity = makeOpportunity($organisation, ['title' => 'HACCP équipe cuisine']);
    meeting($user, '2026-11-18 14:00:00', ['subject' => 'RDV de 14 h', 'opportunity_id' => $opportunity->id]);
    meeting($user, '2026-11-18 09:00:00', ['subject' => 'RDV du matin', 'type_id' => crmItem(ReferenceList::ActionType, 'Prise de rendez-vous')->id]);
    meeting($user, '2026-11-19 09:00:00', ['subject' => 'Demain']);
    meeting($user, '2026-11-18 10:00:00', ['subject' => 'Appel', 'type_id' => crmItem(ReferenceList::ActionType, 'Appel')->id]);
    meeting($user, '2026-11-18 11:00:00', ['subject' => 'Déjà rendu', 'done_at' => reportAt('2026-11-18 12:00:00')]);
    meeting(commercial(), '2026-11-18 11:00:00', ['subject' => 'D\'un collègue']);

    $this->actingAs($user)->get('/crm/report')->assertOk()
        ->assertInertia(fn ($p) => $p->component('crm/report')
            ->has('meetings', 2)
            ->where('meetings.0.subject', 'RDV du matin')
            ->where('meetings.1.subject', 'RDV de 14 h')
            ->where('meetings.1.time', '14:00')
            ->where('meetings.1.opportunities.0.id', $opportunity->id)
            ->where('meetings.1.opportunities.0.stage_id', pipelineStage('Nouveau')->id)
            ->where('meetings.0.opportunities.0.id', $opportunity->id) // no opportunity on the meeting: those of its fiche
            ->has('results')->has('types')->has('stages', 7)->has('loss_reasons')
            ->where('selected', null));

    $chosen = Activity::query()->where('subject', 'RDV de 14 h')->value('id');
    $this->get('/crm/report?activity='.$chosen)->assertInertia(fn ($p) => $p->where('selected', $chosen));
});

it('records the meeting, moves the stage and plans the follow-up in one request', function () {
    $this->travelTo(reportAt('2026-11-18 16:00:00'));
    $user = commercial();
    $organisation = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $opportunity = makeOpportunity($organisation);
    $rdv = meeting($user, '2026-11-18 14:00:00', ['opportunity_id' => $opportunity->id, 'organisation_id' => $organisation->id]);

    $this->actingAs($user)->post('/crm/report', [
        'activity_id' => $rdv->id,
        'result_id' => crmItem(ReferenceList::ActionResult, 'Réussi')->id,
        'report' => 'Besoin confirmé pour 6 cuisiniers.',
        'opportunity_id' => $opportunity->id,
        'stage_id' => pipelineStage('Proposition')->id,
        'next_type_id' => crmItem(ReferenceList::ActionType, 'Appel')->id,
        'next_due_at' => '2026-11-25',
    ])->assertRedirect('/crm/report')->assertSessionHas('status', 'Compte rendu enregistré, prochaine relance prévue.');

    $rdv->refresh();
    expect($rdv->done_at?->equalTo(reportAt('2026-11-18 16:00:00')))->toBeTrue()
        ->and($rdv->report)->toBe('Besoin confirmé pour 6 cuisiniers.')
        ->and($rdv->result_id)->toBe(crmItem(ReferenceList::ActionResult, 'Réussi')->id)
        ->and($opportunity->fresh()->stage_id)->toBe(pipelineStage('Proposition')->id)
        ->and($organisation->fresh()->last_contact_at)->not->toBeNull();

    $next = Activity::query()->whereNull('done_at')->sole();
    expect($next->due_at->equalTo(reportAt('2026-11-25 09:00:00')))->toBeTrue()
        ->and($next->opportunity_id)->toBe($opportunity->id)
        ->and($next->responsible_user_id)->toBe($user->id)
        ->and($next->type_id)->toBe(crmItem(ReferenceList::ActionType, 'Appel')->id);
});

it('refuses lost without a reason with 422 and saves nothing', function () {
    $this->travelTo(reportAt('2026-11-18 16:00:00'));
    $user = commercial();
    $organisation = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $opportunity = makeOpportunity($organisation);
    $rdv = meeting($user, '2026-11-18 14:00:00', ['opportunity_id' => $opportunity->id, 'organisation_id' => $organisation->id]);
    $before = reportSnapshot($opportunity);

    $this->actingAs($user)->postJson('/crm/report', [
        'activity_id' => $rdv->id,
        'report' => 'Pas de budget.',
        'opportunity_id' => $opportunity->id,
        'stage_id' => pipelineStage('Perdu')->id,
        'next_type_id' => crmItem(ReferenceList::ActionType, 'Appel')->id,
        'next_due_at' => '2026-12-01',
    ])->assertStatus(422)->assertJsonValidationErrors(['loss_reason_id' => 'Le motif de perte est obligatoire.']);

    expect(reportSnapshot($opportunity))->toBe($before)
        ->and($rdv->fresh()->done_at)->toBeNull()
        ->and(Activity::query()->count())->toBe(1);

    // With the reason, the same report goes through.
    $this->postJson('/crm/report', [
        'activity_id' => $rdv->id,
        'report' => 'Pas de budget.',
        'opportunity_id' => $opportunity->id,
        'stage_id' => pipelineStage('Perdu')->id,
        'loss_reason_id' => crmItem(ReferenceList::LossReason, 'Prix')->id,
    ])->assertRedirect();
    expect($opportunity->fresh()->stage_id)->toBe(pipelineStage('Perdu')->id)
        ->and($opportunity->fresh()->loss_reason_id)->toBe(crmItem(ReferenceList::LossReason, 'Prix')->id);
});

it('rolls everything back when the follow-up is invalid', function () {
    $this->travelTo(reportAt('2026-11-18 16:00:00'));
    $user = commercial();
    $organisation = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $opportunity = makeOpportunity($organisation);
    $rdv = meeting($user, '2026-11-18 14:00:00', ['opportunity_id' => $opportunity->id, 'organisation_id' => $organisation->id]);
    $before = reportSnapshot($opportunity);

    $this->actingAs($user)->postJson('/crm/report', [
        'activity_id' => $rdv->id,
        'opportunity_id' => $opportunity->id,
        'stage_id' => pipelineStage('Proposition')->id,
        'next_type_id' => crmItem(ReferenceList::ActionType, 'Appel')->id,
        'next_due_at' => '2026-11-01',
    ])->assertStatus(422)->assertJsonValidationErrors(['next_due_at']);

    expect(reportSnapshot($opportunity))->toBe($before);
});

it('creates a done meeting on a fiche chosen without a planned one', function () {
    $this->travelTo(reportAt('2026-11-18 16:00:00'));
    $user = commercial();
    $organisation = Organisation::query()->create(['name' => 'Boulangerie Zoé']);
    $opportunity = makeOpportunity($organisation);

    $this->actingAs($user)->post('/crm/report', [
        'organisation_id' => $organisation->id,
        'type_id' => crmItem(ReferenceList::ActionType, 'Rendez-vous physique')->id,
        'report' => 'Visite imprévue.',
        'opportunity_id' => $opportunity->id,
        'stage_id' => pipelineStage('Étude')->id,
    ])->assertRedirect('/crm/report')->assertSessionHas('status', 'Compte rendu enregistré.');

    $done = Activity::query()->sole();
    expect($done->done_at)->not->toBeNull()
        ->and($done->organisation_id)->toBe($organisation->id)
        ->and($done->opportunity_id)->toBe($opportunity->id)
        ->and($done->report)->toBe('Visite imprévue.')
        ->and($done->responsible_user_id)->toBe($user->id)
        ->and($opportunity->fresh()->stage_id)->toBe(pipelineStage('Étude')->id);
});

it('asks for a meeting or a fiche and for the type of an unplanned meeting', function () {
    $this->actingAs(commercial())->postJson('/crm/report', ['report' => 'Rien'])
        ->assertStatus(422)->assertJsonValidationErrors(['fiche' => 'Choisissez un rendez-vous ou une fiche.']);

    $organisation = Organisation::query()->create(['name' => 'Boulangerie Zoé']);
    $this->postJson('/crm/report', ['organisation_id' => $organisation->id])
        ->assertStatus(422)->assertJsonValidationErrors(['type_id' => 'Choisissez le type d\'action.']);
    expect(Activity::query()->count())->toBe(0);
});

it('moves only an opportunity of the meeting or of its fiche', function () {
    $this->travelTo(reportAt('2026-11-18 16:00:00'));
    $user = commercial();
    $organisation = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $elsewhere = makeOpportunity(Organisation::query()->create(['name' => 'Ailleurs']), ['title' => 'Ailleurs']);
    $sibling = makeOpportunity($organisation, ['title' => 'Autre opportunité de la fiche']);
    $linked = makeOpportunity($organisation, ['title' => 'Celle du RDV']);
    $rdv = meeting($user, '2026-11-18 14:00:00', ['opportunity_id' => $linked->id, 'organisation_id' => $organisation->id]);
    $plain = meeting($user, '2026-11-18 15:00:00', ['organisation_id' => $organisation->id]);

    $refused = fn (string $activityId, Opportunity $opportunity) => $this->actingAs($user)->postJson('/crm/report', [
        'activity_id' => $activityId,
        'opportunity_id' => $opportunity->id,
        'stage_id' => pipelineStage('Proposition')->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['opportunity_id' => 'Choisissez une opportunité de ce rendez-vous ou de sa fiche.']);

    $refused($rdv->id, $elsewhere);
    $refused($rdv->id, $sibling); // the meeting is about another opportunity
    $refused($plain->id, $elsewhere);
    expect(Activity::query()->whereNotNull('done_at')->count())->toBe(0)
        ->and(StageChange::query()->count())->toBe(3);

    // A meeting without opportunity may move one of its fiche.
    $this->postJson('/crm/report', ['activity_id' => $plain->id, 'opportunity_id' => $sibling->id, 'stage_id' => pipelineStage('Proposition')->id])->assertRedirect();
    expect($sibling->fresh()->stage_id)->toBe(pipelineStage('Proposition')->id)
        ->and($plain->fresh()->opportunity_id)->toBeNull();

    // A stage needs an opportunity.
    $this->postJson('/crm/report', ['activity_id' => $rdv->id, 'stage_id' => pipelineStage('Proposition')->id])
        ->assertStatus(422)->assertJsonValidationErrors(['opportunity_id']);
});

it('refuses meetings and opportunities the user may not touch', function () {
    $this->travelTo(reportAt('2026-11-18 16:00:00'));
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $other = crmItem(ReferenceList::Das, 'Métiers supports');
    $visible = Organisation::query()->create(['name' => 'Visible']);
    $visible->das()->sync([$mine->id => ['tenant_id' => tenant()->id]]);
    $hidden = Organisation::query()->create(['name' => 'Cachée']);
    $hidden->das()->sync([$other->id => ['tenant_id' => tenant()->id]]);
    $foreign = makeOpportunity($visible, ['title' => 'Autre DAS', 'das_id' => $other->id]);

    $responsable = userWithRole(Role::ResponsableDas);
    followDas($responsable, $mine);
    $responsable->givePermissionTo(['crm.create', 'crm.update']); // what a Responsable DAS may be granted
    $hiddenRdv = meeting($responsable, '2026-11-18 14:00:00', ['organisation_id' => $hidden->id]);
    $rdv = meeting($responsable, '2026-11-18 15:00:00', ['organisation_id' => $visible->id]);
    $done = meeting($responsable, '2026-11-18 10:00:00', ['organisation_id' => $visible->id, 'done_at' => reportAt('2026-11-18 11:00:00')]);

    $this->actingAs($responsable);
    $this->get('/crm/report')->assertInertia(fn ($p) => $p->has('meetings', 1)->where('meetings.0.id', $rdv->id)->where('meetings.0.opportunities', []));

    $this->postJson('/crm/report', ['activity_id' => $hiddenRdv->id, 'report' => 'x'])
        ->assertStatus(422)->assertJsonValidationErrors(['activity_id' => 'Ce rendez-vous n\'existe pas ou n\'est plus disponible.']);
    $this->postJson('/crm/report', ['activity_id' => $done->id, 'report' => 'x'])
        ->assertStatus(422)->assertJsonValidationErrors(['activity_id' => 'Ce rendez-vous a déjà son compte rendu.']);
    $this->postJson('/crm/report', ['activity_id' => $rdv->id, 'opportunity_id' => $foreign->id, 'stage_id' => pipelineStage('Proposition')->id])
        ->assertStatus(422)->assertJsonValidationErrors(['opportunity_id']);
    $this->postJson('/crm/report', ['organisation_id' => $hidden->id, 'type_id' => crmItem(ReferenceList::ActionType, 'Appel')->id])
        ->assertStatus(422)->assertJsonValidationErrors(['organisation_id']);

    expect($rdv->fresh()->done_at)->toBeNull()
        ->and($hiddenRdv->fresh()->done_at)->toBeNull()
        ->and($foreign->fresh()->stage_id)->toBe(pipelineStage('Nouveau')->id);

    // Direction cannot write in the CRM (no crm.update): 403.
    $this->actingAs(direction(twoFactor: true))->post('/crm/report', ['activity_id' => $rdv->id])->assertForbidden();
});

it('lists and reports the meetings of types flagged meeting, mine only', function () {
    $this->travelTo(reportAt('2026-11-18 16:00:00'));
    $user = commercial();
    $visit = ReferenceItem::query()->create(['list' => ReferenceList::ActionType, 'code' => 'visite-client', 'label' => 'Visite client', 'position' => 20, 'meta' => ['planning' => 'meeting']]);
    $demo = ReferenceItem::query()->create(['list' => ReferenceList::ActionType, 'code' => 'demo', 'label' => 'Démo', 'position' => 21]);
    $flagged = meeting($user, '2026-11-18 09:00:00', ['subject' => 'Visite', 'type_id' => $visit->id]);
    $unflagged = meeting($user, '2026-11-18 10:00:00', ['subject' => 'Démo', 'type_id' => $demo->id]);
    $call = meeting($user, '2026-11-18 11:00:00', ['subject' => 'Appel', 'type_id' => crmItem(ReferenceList::ActionType, 'Appel')->id]);
    $colleague = meeting(assistant(), '2026-11-18 11:00:00', ['subject' => 'D\'un collègue']);

    $this->actingAs($user)->get('/crm/report')->assertInertia(fn ($p) => $p->has('meetings', 1)->where('meetings.0.id', $flagged->id));

    foreach ([$unflagged, $call, $colleague] as $other) {
        $this->postJson('/crm/report', ['activity_id' => $other->id, 'report' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors(['activity_id' => 'Ce rendez-vous n\'existe pas ou n\'est plus disponible.']);
        expect($other->fresh()->done_at)->toBeNull();
    }
    $this->postJson('/crm/report', ['activity_id' => $flagged->id, 'report' => 'Visite faite.'])->assertRedirect();
    expect($flagged->fresh()->done_at)->not->toBeNull();
});

it('offers the opportunities of the person and of the entreprise of a meeting', function () {
    $this->travelTo(reportAt('2026-11-18 16:00:00'));
    $user = commercial();
    $organisation = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $person = makePerson('Martin', ['is_individual' => true]);
    $ofOrganisation = makeOpportunity($organisation, ['title' => 'De l\'entreprise']);
    $ofPerson = makeOpportunity($person, ['title' => 'Du particulier']);
    meeting($user, '2026-11-18 14:00:00', ['organisation_id' => $organisation->id, 'person_id' => $person->id]);

    $this->actingAs($user)->get('/crm/report')->assertInertia(fn ($p) => $p->has('meetings.0.opportunities', 2)
        ->where('meetings.0.opportunities', fn ($o) => collect($o)->pluck('id')->sort()->values()->all() === collect([$ofOrganisation->id, $ofPerson->id])->sort()->values()->all()));

    // The person's opportunity is accepted by the report too.
    $this->post('/crm/report', ['activity_id' => Activity::query()->value('id'), 'opportunity_id' => $ofPerson->id, 'stage_id' => pipelineStage('Étude')->id])->assertRedirect('/crm/report');
    expect($ofPerson->fresh()->stage_id)->toBe(pipelineStage('Étude')->id);
});

it('links the follow-up to the opportunity moved when the meeting had none', function () {
    $this->travelTo(reportAt('2026-11-18 16:00:00'));
    $user = commercial();
    $organisation = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $opportunity = makeOpportunity($organisation);
    $rdv = meeting($user, '2026-11-18 14:00:00', ['organisation_id' => $organisation->id]);

    $this->actingAs($user)->post('/crm/report', [
        'activity_id' => $rdv->id,
        'opportunity_id' => $opportunity->id,
        'stage_id' => pipelineStage('Proposition')->id,
        'next_type_id' => crmItem(ReferenceList::ActionType, 'Appel')->id,
        'next_due_at' => '2026-11-25',
    ])->assertRedirect('/crm/report');

    expect($rdv->fresh()->opportunity_id)->toBeNull()
        ->and(Activity::query()->whereNull('done_at')->sole()->opportunity_id)->toBe($opportunity->id);
});
