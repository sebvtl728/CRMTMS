<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function planAt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Europe/Paris');
}

/**
 * A planned action (to do unless done_at is given).
 *
 * @param  array<string, mixed>  $attributes
 */
function planAction(User $responsible, string $type, string $due, array $attributes = []): Activity
{
    return Activity::query()->create([
        'type_id' => crmItem(ReferenceList::ActionType, $type)->id,
        'subject' => $type.' '.$due,
        'due_at' => planAt($due),
        'duration_minutes' => 60,
        'organisation_id' => $attributes['organisation_id'] ?? Organisation::query()->firstOrCreate(['name' => 'Hôtel du Lac'])->id,
        'responsible_user_id' => $responsible->id,
        ...$attributes,
    ]);
}

/**
 * Subjects per day of the planning page (Monday first).
 *
 * @return array<string, list<string>>
 */
function planningSubjects(mixed $page): array
{
    $days = [];
    foreach ($page['days'] as $day) {
        $days[$day['date']] = array_column($day['items'], 'subject');
    }

    return $days;
}

it('cuts the iso week on Paris days across the end of summer time', function () {
    $this->travelTo(planAt('2026-10-21 10:00:00'));
    $user = commercial();
    $this->actingAs($user);
    // W43 2026 = Monday 19/10 → Sunday 25/10; summer time ends on Sunday 25/10 at 3:00.
    planAction($user, 'Rendez-vous physique', '2026-10-18 23:30:00', ['subject' => 'Dimanche d\'avant']);
    planAction($user, 'Rendez-vous physique', '2026-10-19 00:15:00', ['subject' => 'Lundi minuit et quart']);
    planAction($user, 'Prise de rendez-vous', '2026-10-25 23:30:00', ['subject' => 'Dimanche soir']);
    planAction($user, 'Rendez-vous physique', '2026-10-26 00:30:00', ['subject' => 'Lundi suivant']);

    $page = $this->get('/crm/planning?week=2026-W43')->assertOk()
        ->assertInertia(fn ($p) => $p->component('crm/planning')
            ->where('week', '2026-W43')->where('previous', '2026-W42')->where('next', '2026-W44')
            ->has('days', 7)->where('days.0.date', '2026-10-19')->where('days.6.date', '2026-10-25'))
        ->viewData('page')['props'];

    $days = planningSubjects($page);
    expect($days['2026-10-19'])->toBe(['Lundi minuit et quart'])
        ->and($days['2026-10-25'])->toBe(['Dimanche soir'])
        ->and(array_merge(...array_values($days)))->toHaveCount(2)
        ->and($page['days'][6]['items'][0]['time'])->toBe('23:30');

    // The week of the start of summer time (Sunday 29/03), and the week after it.
    planAction($user, 'Rendez-vous physique', '2026-03-29 23:30:00', ['subject' => 'Heure d\'été']);
    $page = $this->get('/crm/planning?week=2026-W13')->assertOk()->viewData('page')['props'];
    expect(planningSubjects($page)['2026-03-29'])->toBe(['Heure d\'été'])
        ->and($page['days'][0]['date'])->toBe('2026-03-23');
});

it('handles 53-week years and falls back to the current week on an invalid week', function () {
    $this->travelTo(planAt('2026-11-18 10:00:00')); // W47
    $this->actingAs(commercial());

    $this->get('/crm/planning?week=2026-W53')->assertOk()
        ->assertInertia(fn ($p) => $p->where('week', '2026-W53')->where('next', '2027-W01')->where('days.0.date', '2026-12-28'));

    foreach (['2025-W53', '2026-W00', 'n\'importe quoi', '2026-W7'] as $week) {
        $this->get('/crm/planning?week='.urlencode($week))->assertOk()
            ->assertInertia(fn ($p) => $p->where('week', '2026-W47')->where('days.0.date', '2026-11-16'));
    }
    $this->get('/crm/planning')->assertOk()->assertInertia(fn ($p) => $p->where('week', '2026-W47'));
});

it('shows meetings to do or done and planned calls, nothing else', function () {
    $this->travelTo(planAt('2026-11-18 10:00:00'));
    $user = commercial();
    $this->actingAs($user);
    planAction($user, 'Rendez-vous physique', '2026-11-16 14:00:00', ['subject' => 'RDV fait', 'done_at' => planAt('2026-11-16 15:00:00')]);
    planAction($user, 'Prise de rendez-vous', '2026-11-19 09:00:00', ['subject' => 'RDV à faire', 'location' => 'Annecy']);
    planAction($user, 'Appel', '2026-11-19 11:00:00', ['subject' => 'Appel planifié']);
    planAction($user, 'Appel', '2026-11-17 11:00:00', ['subject' => 'Appel sans échéance', 'due_at' => null, 'done_at' => planAt('2026-11-17 11:00:00')]);
    planAction($user, 'E-mail', '2026-11-19 10:00:00', ['subject' => 'E-mail']);
    planAction($user, 'Rendez-vous physique', '2026-11-19 16:00:00', ['subject' => 'Supprimé'])->delete();
    planAction(commercial(), 'Rendez-vous physique', '2026-11-19 16:00:00', ['subject' => 'D\'un collègue']);
    $gone = Organisation::query()->create(['name' => 'À la corbeille']);
    planAction($user, 'Rendez-vous physique', '2026-11-19 17:00:00', ['subject' => 'Fiche supprimée', 'organisation_id' => $gone->id]);
    $gone->delete();

    $page = $this->get('/crm/planning')->assertOk()->viewData('page')['props'];
    $days = planningSubjects($page);

    expect($days['2026-11-16'])->toBe(['RDV fait'])
        ->and($days['2026-11-17'])->toBe([])
        ->and($days['2026-11-19'])->toBe(['RDV à faire', 'Appel planifié']);

    $rdv = $page['days'][3]['items'][0];
    expect($rdv)->toMatchArray(['kind' => 'meeting', 'time' => '09:00', 'duration_minutes' => 60, 'location' => 'Annecy', 'status' => 'todo'])
        ->and($rdv['organisation']['name'])->toBe('Hôtel du Lac')
        ->and($rdv)->not->toHaveKey('report')
        ->and($page['days'][3]['items'][1]['kind'])->toBe('call')
        ->and($page['days'][0]['items'][0]['status'])->toBe('done');
});

it('follows the planning flag of the action types, not their codes', function () {
    $this->travelTo(planAt('2026-11-18 10:00:00'));
    $user = commercial();
    $this->actingAs($user);
    $visit = ReferenceItem::query()->create(['list' => ReferenceList::ActionType, 'code' => 'visite-client', 'label' => 'Visite client', 'position' => 20, 'meta' => ['planning' => 'meeting']]);
    $demo = ReferenceItem::query()->create(['list' => ReferenceList::ActionType, 'code' => 'demo', 'label' => 'Démo', 'position' => 21]);
    $call = crmItem(ReferenceList::ActionType, 'Appel');
    $call->update(['meta' => null]); // the organisme took calls out of the planning
    planAction($user, 'Visite client', '2026-11-19 09:00:00', ['subject' => 'Visite', 'type_id' => $visit->id]);
    planAction($user, 'Démo', '2026-11-19 10:00:00', ['subject' => 'Démo', 'type_id' => $demo->id]);
    planAction($user, 'Appel', '2026-11-19 11:00:00', ['subject' => 'Appel']);

    $page = $this->get('/crm/planning')->assertOk()->viewData('page')['props'];
    expect(planningSubjects($page)['2026-11-19'])->toBe(['Visite'])
        ->and($page['days'][3]['items'][0]['kind'])->toBe('meeting');
});

it('shows the planning of a colleague only to direction and admin', function () {
    $this->travelTo(planAt('2026-11-18 10:00:00'));
    $seller = commercial();
    $other = commercial();
    planAction($seller, 'Rendez-vous physique', '2026-11-19 09:00:00', ['subject' => 'RDV du commercial']);
    planAction($other, 'Rendez-vous physique', '2026-11-19 10:00:00', ['subject' => 'RDV de l\'autre']);

    // A commercial asking for a colleague gets his own planning (the filter is ignored).
    $this->actingAs($other)->get('/crm/planning?user='.$seller->id)->assertOk()
        ->assertInertia(fn ($p) => $p->where('can_team', false)->where('user', $other->id)->where('users', [])
            ->where('days.3.items.0.subject', 'RDV de l\'autre')->has('days.3.items', 1));

    $boss = direction(twoFactor: true);
    $this->actingAs($boss)->get('/crm/planning?user='.$seller->id)->assertOk()
        ->assertInertia(fn ($p) => $p->where('can_team', true)->where('user', $seller->id)
            ->where('days.3.items.0.subject', 'RDV du commercial')->has('days.3.items', 1)
            ->where('users', fn ($users) => collect($users)->pluck('value')->contains($seller->id)));

    // Their own by default; an unknown user falls back to it too.
    $this->get('/crm/planning')->assertInertia(fn ($p) => $p->where('user', $boss->id)->has('days.3.items', 0));
    $this->get('/crm/planning?user=00000000-0000-7000-8000-000000000000')->assertInertia(fn ($p) => $p->where('user', $boss->id));

    $this->actingAs(admin(twoFactor: true))->get('/crm/planning?user='.$other->id)
        ->assertInertia(fn ($p) => $p->where('user', $other->id)->where('days.3.items.0.subject', 'RDV de l\'autre'));
});

// The responsible always sees his own actions, even out of his DAS (« Confier à… », 0.7.1).
it('shows a responsable das his own meetings in the planning, even out of his das', function () {
    $this->travelTo(planAt('2026-11-18 10:00:00'));
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $other = crmItem(ReferenceList::Das, 'Métiers supports');
    $visible = Organisation::query()->create(['name' => 'Visible']);
    $visible->das()->sync([$mine->id => ['tenant_id' => tenant()->id]]);
    $hidden = Organisation::query()->create(['name' => 'Cachée']);
    $hidden->das()->sync([$other->id => ['tenant_id' => tenant()->id]]);

    $responsable = userWithRole(Role::ResponsableDas);
    followDas($responsable, $mine);
    planAction($responsable, 'Rendez-vous physique', '2026-11-19 09:00:00', ['subject' => 'Visible', 'organisation_id' => $visible->id]);
    planAction($responsable, 'Rendez-vous physique', '2026-11-19 10:00:00', ['subject' => 'Caché', 'organisation_id' => $hidden->id]);

    $this->actingAs($responsable)->get('/crm/planning')->assertOk()
        ->assertInertia(fn ($p) => $p->has('days.3.items', 2)->where('days.3.items.0.subject', 'Visible')->where('days.3.items.1.subject', 'Caché'));
});

it('asks guests to sign in', function () {
    $this->get('/crm/planning')->assertRedirect('/login');
});
