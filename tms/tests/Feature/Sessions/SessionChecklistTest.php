<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\PlacementPeriods;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\Summary\Card;
use App\Modules\Sessions\Summary\SessionChecklist;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';
require_once __DIR__.'/Support/attendance.php';

beforeEach(function () {
    plSetUp();
});

/**
 * Who sees every card of every session.
 */
function scAllRights(): User
{
    return userWithPermissions([
        Permissions::SESSIONS_VIEW, Permissions::SESSIONS_UPDATE, Permissions::SEANCES_VIEW, Permissions::SEANCES_MANAGE,
        Permissions::DOSSIERS_VIEW, Permissions::ATTENDANCE_MANAGE, Permissions::ATTENDANCE_CLOSE,
    ]);
}

/**
 * The cards of the session keyed by their key.
 *
 * @return array<string, Card>
 */
function scCards(TrainingSession $session, ?User $user = null, string $today = '2026-09-15'): array
{
    $cards = SessionChecklist::forMany(collect([$session->refresh()]), $user ?? scAllRights(), CarbonImmutable::parse($today, 'Europe/Paris'))[$session->id];

    return collect($cards)->keyBy(fn (Card $c) => $c->key)->all();
}

it('computes hours as séances for séance modules and planned hours for interval modules, never both', function () {
    $session = plSession();
    $module = plModule($session);
    $module->update(['planned_minutes' => 420]);
    atIntervalModule($session, 600);
    $first = plSeance($module, '2026-09-02', '09:00', '12:30');
    plSeance($module, '2026-09-03', '09:00', '12:30');

    $hours = scCards($session)['hours'];
    expect($hours->value)->toBe('17 h / 17 h')->and($hours->state)->toBe('done')
        ->and($hours->title)->toBe('Heures planifiées')->and($hours->step)->toBe('planning');

    $first->delete();
    expect(scCards($session)['hours']->toArray())->toMatchArray(['state' => 'todo', 'value' => '13 h 30 / 17 h']);

    plSeance($module, '2026-09-04', '09:00', '12:30');
    plSeance($module, '2026-09-07', '09:00', '12:30');
    expect(scCards($session)['hours']->toArray())->toMatchArray(['state' => 'alert', 'value' => '20 h 30 / 17 h']);

    SessionModule::query()->create([
        'training_session_id' => $session->id, 'position' => 3, 'title' => 'Sans heures', 'planned_minutes' => null,
        'dating_mode' => 'interval', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30',
    ]);
    $hours = scCards($session)['hours'];
    expect($hours->state)->toBe('todo')->and($hours->text)->toContain('à définir');
});

it('counts upcoming séances without trainer, without room or place, with alerts', function () {
    $session = plSession();
    $module = plModule($session);
    plSeance($module, '2026-09-02', '09:00', '12:30'); // past: ignored
    $a = plSeance($module, '2026-09-16', '09:00', '12:30');
    $b = plSeance($module, '2026-09-16', '11:00', '13:00'); // overlaps $a
    plSeance($module, '2026-09-17', '09:00', '12:30')->update(['status' => Seance::CANCELLED, 'cancel_reason' => 'Grève']);

    $cards = scCards($session);
    expect($cards['trainers']->toArray())->toMatchArray(['value' => '2', 'state' => 'todo', 'step' => 'planning'])
        ->and($cards['rooms']->toArray())->toMatchArray(['value' => '2', 'state' => 'todo'])
        ->and($cards['conflicts']->toArray())->toMatchArray(['value' => '2', 'state' => 'alert']);

    $trainer = plTrainer();
    $a->update(['trainer_id' => $trainer->id, 'room_id' => plRoom()->id]);
    $b->update(['trainer_id' => $trainer->id, 'place_text' => 'Chez le client', 'starts_at' => '14:00', 'ends_at' => '17:00']);

    $cards = scCards($session);
    expect($cards['trainers']->toArray())->toMatchArray(['value' => '0', 'state' => 'done'])
        ->and($cards['rooms']->toArray())->toMatchArray(['state' => 'done'])
        ->and($cards['conflicts']->toArray())->toMatchArray(['state' => 'done']);
});

it('checks enrolments against places', function () {
    $session = plSession(overrides: ['capacity' => 1]);
    expect(scCards($session)['enrolments']->toArray())->toMatchArray(['state' => 'todo', 'value' => '0 / 1', 'step' => 'stagiaires']);

    atEnrol($session);
    expect(scCards($session)['enrolments']->toArray())->toMatchArray(['state' => 'done', 'value' => '1 / 1']);

    $waiting = atEnrol($session, 'Petit');
    expect($waiting->status)->toBe(EnrolmentStatus::Waitlisted)
        ->and(scCards($session)['enrolments']->toArray())->toMatchArray(['state' => 'alert']);

    $waiting->update(['status' => EnrolmentStatus::Confirmed, 'waitlist_position' => null]);
    $enrolments = scCards($session)['enrolments'];
    expect($enrolments->state)->toBe('alert')->and($enrolments->value)->toBe('2 / 1');
});

it('checks that every dossier of the session is funded at 100 %', function () {
    $session = plSession();
    expect(scCards($session)['fundings']->toArray())->toMatchArray(['state' => 'na']);

    $dossier = atEnrol($session)->dossier()->firstOrFail();
    expect(scCards($session)['fundings']->toArray())->toMatchArray(['state' => 'todo', 'value' => '1']);

    l2Funding($dossier, ['amount' => '600,00']);
    expect(scCards($session)['fundings']->toArray())->toMatchArray(['state' => 'todo']);
    l2Funding($dossier, ['amount' => '400,00']);
    expect(scCards($session)['fundings']->toArray())->toMatchArray(['state' => 'done', 'value' => '0']);
});

it('hides the fundings card without sessions.dossiers.view and the attendance cards without an attendance right', function () {
    $session = plSession();
    $order = fn (array $cards) => array_keys($cards);

    expect($order(scCards($session)))->toBe(['hours', 'trainers', 'rooms', 'conflicts', 'enrolments', 'fundings', 'placements', 'attendance', 'closures']);
    expect($order(scCards($session, userWithPermissions([Permissions::SESSIONS_VIEW]))))
        ->toBe(['hours', 'trainers', 'rooms', 'conflicts', 'enrolments', 'placements']);
    expect($order(scCards($session, userWithPermissions([Permissions::SESSIONS_VIEW, Permissions::ATTENDANCE_CLOSE]))))
        ->toBe(['hours', 'trainers', 'rooms', 'conflicts', 'enrolments', 'placements', 'attendance', 'closures']);
    // « Ses fiches » on the dossiers: not his session, no fundings card.
    $own = l2User([Permissions::SESSIONS_VIEW => null, Permissions::DOSSIERS_VIEW => AccessScope::Own, Permissions::ATTENDANCE_MANAGE => AccessScope::Own]);
    expect($order(scCards($session, $own)))->toBe(['hours', 'trainers', 'rooms', 'conflicts', 'enrolments', 'placements']);
});

it('marks placements na without placement period, todo when a trainee has no host', function () {
    $session = plSession();
    $lea = atEnrol($session);
    expect(scCards($session)['placements']->toArray())->toMatchArray(['state' => 'na', 'step' => 'planning']);

    $period = PlacementPeriods::save($session, ['starts_on' => '2026-09-21', 'ends_on' => '2026-09-25', 'label' => 'Stage en entreprise']);
    expect(scCards($session)['placements']->toArray())->toMatchArray(['state' => 'todo', 'value' => '1']);

    PlacementPeriods::setHost($period, $lea, ['organisation_id' => Organisation::query()->create(['name' => 'Hôtel du Lac'])->id, 'starts_on' => null, 'ends_on' => null]);
    expect(scCards($session)['placements']->toArray())->toMatchArray(['state' => 'done', 'value' => '0']);
});

it('counts past séances without attendance and elapsed months not closed', function () {
    $session = plSession('2026-09-01', '2026-11-30');
    $module = plModule($session);
    $lea = atEnrol($session);
    $recorded = plSeance($module, '2026-09-02');
    plSeance($module, '2026-10-05');
    plSeance($module, '2026-11-20');
    app(Attendances::class)->record($recorded, $lea->id, 'present', null, null);

    // Before the end of the first month: nothing to close yet.
    expect(scCards($session, today: '2026-09-15')['closures']->toArray())->toMatchArray(['state' => 'na']);

    $cards = scCards($session, today: '2026-11-17');
    expect($cards['attendance']->toArray())->toMatchArray(['value' => '1', 'state' => 'todo', 'step' => 'suivi'])
        ->and($cards['closures']->toArray())->toMatchArray(['value' => '2', 'state' => 'todo', 'step' => 'suivi']);

    app(Closures::class)->close($session, '2026-09');
    expect(scCards($session, today: '2026-11-17')['closures']->toArray())->toMatchArray(['value' => '1', 'state' => 'todo']);
    app(Closures::class)->close($session, '2026-10');
    expect(scCards($session, today: '2026-11-17')['closures']->toArray())->toMatchArray(['state' => 'done']);
});

it('counts the last month of a session ending today, and a month closed early', function () {
    $session = plSession('2026-11-02', '2026-11-20');
    $module = plModule($session);
    $lea = atEnrol($session);
    app(Attendances::class)->record(plSeance($module, '2026-11-19'), $lea->id, 'present', null, null);

    // Under way, its month not closed: nothing to close yet.
    expect(scCards($session, today: '2026-11-10')['closures']->toArray())->toMatchArray(['state' => 'na']);
    // Its last day: its month is to close.
    expect(scCards($session, today: '2026-11-20')['closures']->toArray())->toMatchArray(['value' => '1', 'state' => 'todo', 'text' => '1 mois à clôturer.']);

    app(Closures::class)->close($session, '2026-11');
    expect(scCards($session, today: '2026-11-20')['closures']->toArray())->toMatchArray(['value' => '0', 'state' => 'done', 'text' => 'Tous les mois sont clôturés.']);
    // Closed before its end: shown as closed, not « nothing to close ».
    expect(scCards($session, today: '2026-11-10')['closures']->toArray())->toMatchArray(['state' => 'done']);
});

it('returns no card for a cancelled session', function () {
    $session = plSession();
    $session = app(SessionWriter::class)->cancel($session, crmItem(ReferenceList::CancelReason, 'Effectif insuffisant')->id);

    expect(SessionChecklist::for($session, scAllRights()))->toBe([])
        ->and(SessionChecklist::forMany(collect([$session, plSession()]), scAllRights())[$session->id])->toBe([]);
});

it('serialises a card and counts the pending ones', function () {
    $session = plSession();
    $cards = SessionChecklist::for($session, scAllRights());

    expect($cards[0]->toArray())->toHaveKeys(['key', 'title', 'value', 'state', 'text', 'step', 'anchor'])
        ->and(SessionChecklist::pending($cards))->toBe(collect($cards)->whereIn('state', ['todo', 'alert'])->count())
        ->and(SessionChecklist::pending($cards))->toBeGreaterThan(0);
});

it('keeps a fixed number of queries for many sessions', function () {
    $user = scAllRights();
    $trainer = plTrainer();
    $sessions = collect(range(1, 12))->map(function (int $i) use ($trainer) {
        $session = plSession('2026-09-01', '2026-11-30');
        $module = plModule($session);
        plSeance($module, '2026-09-02', '09:00', '12:30', ['trainer_id' => $trainer->id]);
        plSeance($module, sprintf('2026-11-%02d', 18 + ($i % 10)), '09:00', '12:30');
        atEnrol($session, "Durand{$i}");

        return $session;
    });
    $today = CarbonImmutable::parse('2026-11-17', 'Europe/Paris');
    SessionChecklist::forMany($sessions, $user, $today); // memoised scopes

    $count = function ($subset) use ($user, $today): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        SessionChecklist::forMany($subset, $user, $today);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    expect($count($sessions->take(3)))->toBe($count($sessions));
});
