<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Role;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Public\FicheBlocks;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\PlacementPeriods;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Planning\Seances;
use App\Modules\Sessions\Resources\ClosurePeriod;
use App\Modules\Sessions\Trainers\Trainers;
use App\Modules\Sessions\Trainers\TrainerStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';
require_once __DIR__.'/Support/attendance.php';

beforeEach(function () {
    plSetUp();
    $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00', 'Europe/Paris'));
});

/**
 * The séance ids of a planning page response, in order.
 *
 * @return list<string>
 */
function planningIds(TestResponse $response): array
{
    return array_column($response->viewData('page')['props']['seances'], 'id');
}

// ---------------------------------------------------------------- general planning

it('shows the séances of the month on the general planning, shaded days and filters', function () {
    $session = plSession('2026-09-01', '2026-10-31');
    $module = plModule($session);
    $martin = plTrainer('Martin');
    $durand = plTrainer('Durand');
    $room = plRoom('Salle A');
    $a = plSeance($module, '2026-09-02', '09:00', '12:30', ['trainer_id' => $martin->id, 'room_id' => $room->id]);
    $b = plSeance($module, '2026-09-03', '13:30', '17:00', ['trainer_id' => $durand->id]);
    $october = plSeance($module, '2026-10-05');
    $other = plSession('2026-09-01', '2026-09-30', ['title' => 'Autre groupe']);
    $c = plSeance(plModule($other), '2026-09-02', '13:30', '17:00');
    ClosurePeriod::query()->create(['starts_on' => '2026-09-21', 'ends_on' => '2026-09-25', 'label' => 'Inventaire']);
    $manager = plManager();

    $response = $this->actingAs($manager)->get('/sessions/planning?view=month&date=2026-09-10')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('sessions/planning')
            ->where('period.view', 'month')
            ->where('period.from', '2026-08-31')
            ->where('period.to', '2026-10-04')
            ->where('period.label', 'Septembre 2026')
            ->where('closures.0.label', 'Inventaire')
            ->where('seances.0.id', $a->id)
            ->where('seances.0.start', '09:00')
            ->where('seances.0.trainer.label', 'Paul Martin')
            ->where('seances.0.room.name', 'Salle A')
            ->where('seances.0.session.id', $session->id)
            ->has('options.trainers')->has('options.rooms')->has('options.sessions')->has('options.das'));
    expect(planningIds($response))->toEqualCanonicalizing([$a->id, $b->id, $c->id])->not->toContain($october->id);

    // Filters: trainer, room, session, DAS of the session.
    expect(planningIds($this->get("/sessions/planning?date=2026-09-10&trainer={$durand->id}")))->toBe([$b->id])
        ->and(planningIds($this->get("/sessions/planning?date=2026-09-10&room={$room->id}")))->toBe([$a->id])
        ->and(planningIds($this->get("/sessions/planning?date=2026-09-10&session={$other->id}")))->toBe([$c->id])
        ->and(planningIds($this->get('/sessions/planning?date=2026-09-10&das='.$session->das_id)))->toHaveCount(3)
        ->and(planningIds($this->get('/sessions/planning?date=2026-09-10&das='.crmItem(ReferenceList::Das, 'Métiers supports')->id)))->toBe([])
        // A malformed filter is ignored.
        ->and(planningIds($this->get('/sessions/planning?date=2026-09-10&trainer=nope')))->toHaveCount(3);

    // Week view: Monday to Sunday of the day.
    $this->get('/sessions/planning?view=week&date=2026-10-07')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('period.from', '2026-10-05')->where('period.to', '2026-10-11')
            ->where('seances', fn ($rows) => collect($rows)->pluck('id')->all() === [$october->id]));
});

it('lists on the DAS filter the trainers of that DAS only', function () {
    $das = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $martin = plTrainer('Martin');
    $durand = plTrainer('Durand');
    Trainers::setDas($martin, [$das->id]);

    $this->actingAs(plManager())->get('/sessions/planning?das='.$das->id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('options.trainers', fn ($options) => collect($options)->pluck('value')->all() === [$martin->id]));
    $this->get('/sessions/planning')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('options.trainers', fn ($options) => collect($options)->pluck('value')->sort()->values()->all() === collect([$martin->id, $durand->id])->sort()->values()->all()));
});

it('keeps the general planning to the scope of sessions.seances.view', function () {
    $mine = plSession('2026-09-01', '2026-09-30');
    $theirs = plSession('2026-09-01', '2026-09-30', ['title' => 'Groupe B']);
    $formateur = userWithRole(Role::Formateur);
    $trainer = Trainers::forUser($formateur) ?? Trainers::ensureForUser($formateur);
    $his = plSeance(plModule($mine), '2026-09-02', '09:00', '12:30', ['trainer_id' => $trainer->id]);
    plSeance(plModule($mine), '2026-09-03');
    plSeance(plModule($theirs), '2026-09-02', '13:30', '17:00');

    // A trainer: his séances only, never an alert (they name trainees) nor an internal note.
    $response = $this->actingAs($formateur)->get('/sessions/planning?date=2026-09-10')->assertOk();
    expect(planningIds($response))->toBe([$his->id])
        ->and($response->viewData('page')['props']['seances'][0])->not->toHaveKey('alerts')->not->toHaveKey('note')
        ->and(array_column($response->viewData('page')['props']['options']['trainers'], 'value'))->toBe([$trainer->id]);

    // « Ses DAS »: the séances of the sessions of his DAS.
    $das = l2User([Permissions::SEANCES_VIEW => AccessScope::Das]);
    expect(planningIds($this->actingAs($das)->get('/sessions/planning?date=2026-09-10')))->toBe([]);
    followDas($das, crmItem(ReferenceList::Das, 'Hôtellerie-restauration'));
    expect(planningIds($this->actingAs($das)->get('/sessions/planning?date=2026-09-10')))->toHaveCount(3);

    // Without the right: 403; a trashed session hides its séances.
    $this->actingAs(userWithPermissions([Permissions::SESSIONS_VIEW]))->get('/sessions/planning')->assertForbidden();
    $theirs->delete();
    expect(planningIds($this->actingAs(plManager())->get('/sessions/planning?date=2026-09-10')))->toHaveCount(2);
});

it('loads a month of 100 séances in a bounded number of queries', function () {
    $session = plSession('2026-09-01', '2026-09-30');
    $module = plModule($session);
    $trainers = [plTrainer('Martin'), plTrainer('Durand'), plTrainer('Petit')];
    $rooms = [plRoom('Salle A'), plRoom('Salle B')];
    atEnrol($session, 'Roux');
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/sessions/planning?date=2026-09-10')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $manager = plManager();
    $this->actingAs($manager);
    // A few séances using every trainer and room (each kind of data loaded once).
    plSeance($module, '2026-09-01', '07:00', '07:30', ['trainer_id' => $trainers[0]->id, 'room_id' => $rooms[0]->id]);
    plSeance($module, '2026-09-01', '07:30', '08:00', ['trainer_id' => $trainers[1]->id, 'room_id' => $rooms[1]->id]);
    plSeance($module, '2026-09-01', '08:00', '08:30', ['trainer_id' => $trainers[2]->id]);
    $few = $count();

    $created = 2;
    foreach (range(1, 30) as $day) {
        $date = sprintf('2026-09-%02d', $day);
        foreach ([['09:00', '10:00'], ['10:00', '11:00'], ['13:00', '14:00'], ['14:00', '15:00']] as $i => [$start, $end]) {
            if ($created >= 99) {
                break 2;
            }
            plSeance($module, $date, $start, $end, ['trainer_id' => $trainers[$i % 3]->id, 'room_id' => $rooms[$i % 2]->id]);
            $created++;
        }
    }
    expect(Seance::query()->count())->toBe(100);

    expect($count())->toBeLessThanOrEqual($few);
});

// ---------------------------------------------------------------- « Mon planning »

it('shows a linked trainer his séances of the week with the attendance entry', function () {
    $session = plSession('2026-09-01', '2026-09-30');
    $module = plModule($session);
    $formateur = userWithRole(Role::Formateur);
    $trainer = Trainers::forUser($formateur) ?? Trainers::ensureForUser($formateur);
    $room = plRoom('Salle A');
    $room->update(['address' => '12 rue des Lilas, Annecy']);
    $monday = plSeance($module, '2026-09-07', '09:00', '12:30', ['trainer_id' => $trainer->id, 'room_id' => $room->id, 'note' => 'Note interne']);
    $cancelled = plSeance($module, '2026-09-08', '09:00', '12:30', ['trainer_id' => $trainer->id]);
    app(Seances::class)->cancel($cancelled, 'Formateur malade');
    plSeance($module, '2026-09-09'); // without trainer
    plSeance($module, '2026-09-14', '09:00', '12:30', ['trainer_id' => $trainer->id]); // next week

    $response = $this->actingAs($formateur)->get('/mon-planning?view=week&date=2026-09-09')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('sessions/my-planning')
            ->where('period.from', '2026-09-07')
            ->where('seances.0.id', $monday->id)
            ->where('seances.0.room.name', 'Salle A')
            ->where('seances.0.can_attendance', true)
            ->where('seances.1.id', $cancelled->id)
            ->where('seances.1.status', 'cancelled')
            ->where('seances.1.can_attendance', false)
            ->has('seances', 2));
    expect($response->viewData('page')['props']['seances'][0])->not->toHaveKey('note')->not->toHaveKey('alerts');

    // Month view (default): the 3 séances of September.
    $this->get('/mon-planning?date=2026-09-09')->assertOk()->assertInertia(fn (Assert $page) => $page->where('period.view', 'month')->has('seances', 3));

    // The grid of his séance opens; « Tous présents » on it.
    atEnrol($session, 'Durand');
    $this->postJson("/seances/{$monday->id}/attendance/all-present")->assertOk()->assertJsonPath('count', 1);
});

it('keeps « Mon planning » to the linked trainer', function () {
    $this->actingAs(plManager())->get('/mon-planning')->assertForbidden();
    $this->actingAs(commercial())->get('/mon-planning')->assertForbidden();

    // A manager also linked to a trainer sees his own séances only there.
    $session = plSession('2026-09-01', '2026-09-30');
    $module = plModule($session);
    $manager = plManager();
    $trainer = Trainers::declareUser($manager, TrainerStatus::Employee);
    $his = plSeance($module, '2026-09-02', '09:00', '12:30', ['trainer_id' => $trainer->id]);
    plSeance($module, '2026-09-03');
    $response = $this->actingAs($manager)->get('/mon-planning?date=2026-09-02')->assertOk();
    expect(planningIds($response))->toBe([$his->id]);
});

// ---------------------------------------------------------------- trainee planning on the person

it('adds the upcoming séances and the placements of a trainee to « Formations suivies »', function () {
    $session = plSession('2026-08-31', '2026-10-31');
    $module = plModule($session);
    $room = plRoom('Salle A');
    plSeance($module, '2026-08-31'); // before today: not listed
    $next = plSeance($module, '2026-09-02', '09:00', '12:30', ['room_id' => $room->id]);
    $cancelled = plSeance($module, '2026-09-03');
    app(Seances::class)->cancel($cancelled, 'Formateur malade');
    plSeance($module, '2026-09-10', '13:30', '17:00');
    PlacementPeriods::save($session, ['starts_on' => '2026-10-01', 'ends_on' => '2026-10-15', 'label' => 'Stage en entreprise']);
    $lea = atEnrol($session, 'Durand');
    $viewer = userWithPermissions([Permissions::SESSIONS_VIEW, 'crm.fiches.view']);

    $block = collect(FicheBlocks::for('person', $lea->person_id, $viewer))->firstWhere('key', 'sessions.enrolments');
    $details = $block['rows'][0]['details'];
    expect($details)->toBe([
        'Mer. 02/09/2026 · 09:00–12:30 · '.$module->title.' · Salle A',
        'Jeu. 10/09/2026 · 13:30–17:00 · '.$module->title,
        'Stage en entreprise du 01/10/2026 au 15/10/2026',
    ]);
});
