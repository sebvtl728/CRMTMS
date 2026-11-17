<?php

use App\Modules\Core\References\ReferenceList;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Attendance\MonthlyHours;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Planning\Seances;
use App\Modules\Sessions\Public\RealisedHours;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';
require_once __DIR__.'/Support/attendance.php';

beforeEach(function () {
    plSetUp();
});

/**
 * September–October 2026: module A in séances (21 h planned), B in interval
 * (10 h), C in interval without planned hours (counted 0).
 *
 * @return array{session: TrainingSession, a: SessionModule, b: SessionModule, c: SessionModule, lea: Enrolment}
 */
function rhMixed(): array
{
    $session = plSession('2026-09-01', '2026-10-31');
    $a = plModule($session);
    $b = atIntervalModule($session, 600, 'Autonomie', 2);
    $c = SessionModule::query()->create(['training_session_id' => $session->id, 'position' => 3, 'title' => 'Tutorat', 'planned_minutes' => null,
        'dating_mode' => 'interval', 'starts_on' => '2026-09-01', 'ends_on' => '2026-10-31']);
    $lea = atEnrol($session, 'Durand');
    $attendances = app(Attendances::class);
    $hours = app(MonthlyHours::class);

    $attendances->record(plSeance($a, '2026-09-02', '09:00', '12:00'), $lea->id, 'present', null, null);              // 180
    $attendances->record(plSeance($a, '2026-09-03', '09:00', '17:00', ['break_minutes' => 60]), $lea->id, 'partial', 200, null); // 200
    $attendances->record(plSeance($a, '2026-10-05', '09:00', '12:00'), $lea->id, 'absent', null, null);               // 0
    $cancelled = plSeance($a, '2026-10-06', '09:00', '12:00');
    $attendances->record($cancelled, $lea->id, 'present', null, null);
    app(Seances::class)->cancel($cancelled, 'Grève');                                                                 // 180 → 0
    $hours->record($lea->id, $b, '2026-09', 450, null);
    $hours->record($lea->id, $b, '2026-10', 300, null);
    $hours->record($lea->id, $c, '2026-10', 60, null);

    return ['session' => $session, 'a' => $a, 'b' => $b, 'c' => $c, 'lea' => $lea];
}

it('gives the exact realised and planned minutes of an enrolment, séances and monthly hours mixed', function () {
    ['session' => $session, 'a' => $a, 'b' => $b, 'c' => $c, 'lea' => $lea] = rhMixed();
    app(Closures::class)->close($session, '2026-09');

    expect(RealisedHours::forEnrolment($lea->id))->toBe([
        'planned_minutes' => 1860,
        'enrolment_planned_minutes' => 1260,
        'realised_minutes' => 1190,
        'by_module' => [
            ['module_id' => $a->id, 'planned' => 1260, 'realised' => 380],
            ['module_id' => $b->id, 'planned' => 600, 'realised' => 750],
            ['module_id' => $c->id, 'planned' => 0, 'realised' => 60],
        ],
        'closed_months' => ['2026-09'],
    ]);

    expect(RealisedHours::forEnrolment($lea->id, '2026-09'))->toBe([
        'planned_minutes' => 1860,
        'enrolment_planned_minutes' => 1260,
        'realised_minutes' => 830,
        'by_module' => [
            ['module_id' => $a->id, 'planned' => 1260, 'realised' => 380],
            ['module_id' => $b->id, 'planned' => 600, 'realised' => 450],
            ['module_id' => $c->id, 'planned' => 0, 'realised' => 0],
        ],
        'closed_months' => ['2026-09'],
    ]);

    expect(RealisedHours::forEnrolment($lea->id, '2026-10'))->toMatchArray(['realised_minutes' => 360, 'closed_months' => []]);
});

it('counts nothing outside the entry and leaving days of the trainee', function () {
    ['session' => $session, 'a' => $a, 'b' => $b] = rhMixed();
    $paul = atEnrol($session, 'Martin');
    $seance = Seance::query()->where('date', '2026-09-03')->sole();
    app(Attendances::class)->record($seance, $paul->id, 'present', null, null);
    app(MonthlyHours::class)->record($paul->id, $b, '2026-10', 120, null);
    app(Enrolments::class)->abandon($paul, CarbonImmutable::parse('2026-09-03'), crmItem(ReferenceList::AbandonReason, 'Maladie')->id);

    // October hours entered before the abandon on 3 September: no longer counted.
    expect(RealisedHours::forEnrolment($paul->id)['realised_minutes'])->toBe(420)
        ->and(RealisedHours::forEnrolment($paul->id)['by_module'][1]['realised'])->toBe(0);
});

it('gives the realised hours of every trainee of a session for a month', function () {
    ['session' => $session, 'a' => $a, 'lea' => $lea] = rhMixed();
    $marc = atEnrol($session, 'Petit', ['entered_on' => '2026-10-01']);
    $waiting = atEnrol($session, 'Attente');
    app(Enrolments::class)->cancel($waiting);

    $rows = RealisedHours::forSession($session->id, '2026-09');

    expect(collect($rows)->pluck('enrolment_id')->all())->toBe([$lea->id, $marc->id])
        ->and($rows[0]['realised_minutes'])->toBe(830)
        ->and($rows[0])->toBe(['enrolment_id' => $lea->id, ...RealisedHours::forEnrolment($lea->id, '2026-09')])
        ->and($rows[1]['realised_minutes'])->toBe(0)
        ->and($rows[1]['planned_minutes'])->toBe(1860);
    expect(RealisedHours::forSession($session->id, '2026-10')[0]['realised_minutes'])->toBe(360);
});

it('answers within a fixed number of queries for a session of many trainees and séances', function () {
    $session = plSession('2026-09-01', '2026-10-31');
    $module = plModule($session);
    $seances = collect(range(1, 20))->map(fn (int $i) => plSeance($module, CarbonImmutable::parse('2026-09-01')->addDays($i)->toDateString()));
    $enrolments = collect(range(1, 8))->map(fn (int $i) => atEnrol($session, "Stagiaire{$i}"));
    foreach ($seances as $seance) {
        app(Attendances::class)->allPresent($seance);
    }

    DB::enableQueryLog();
    $rows = RealisedHours::forSession($session->id, '2026-09');
    $queries = count(DB::getQueryLog());

    expect($rows)->toHaveCount(8)->and($rows[0]['realised_minutes'])->toBe(20 * 210)->and($queries)->toBeLessThanOrEqual(8);
    expect($enrolments)->toHaveCount(8);
});

it('never counts a cancelled séance, even with minutes left on its attendance', function () {
    ['lea' => $lea] = rhMixed();
    // Stale minutes on the cancelled séance of 6 October (written around the services).
    $cancelled = Seance::query()->where('date', '2026-10-06')->sole();
    DB::table('attendances')->where('seance_id', $cancelled->id)->update(['minutes' => 180]);

    expect(RealisedHours::forEnrolment($lea->id)['realised_minutes'])->toBe(1190)
        ->and(RealisedHours::forEnrolment($lea->id, '2026-10')['realised_minutes'])->toBe(360);
});

it('exposes the planned minutes of the enrolment next to the session total', function () {
    $session = plSession('2026-09-01', '2026-09-30');
    plModule($session);
    $lea = atEnrol($session, 'Durand', ['planned_hours' => '10.33']);

    $hours = RealisedHours::forEnrolment($lea->id);

    expect($hours['enrolment_planned_minutes'])->toBe(620)->and($hours['planned_minutes'])->toBe(1260);
});
