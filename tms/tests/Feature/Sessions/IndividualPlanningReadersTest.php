<?php

use App\Modules\Sessions\Attendance\AttendanceRows;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Attendance\MonthlyHours;
use App\Modules\Sessions\Planning\IndividualPlanning;
use App\Modules\Sessions\Planning\IndividualPlanningWriter as Writer;
use App\Modules\Sessions\Planning\TraineePlanning;
use App\Modules\Sessions\Public\Billing;
use App\Modules\Sessions\Public\Paperwork;
use App\Modules\Sessions\Public\RealisedHours;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';
require_once __DIR__.'/Support/attendance.php';

beforeEach(function () {
    plSetUp();
    $this->actingAs(atManager());
    $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00', 'Europe/Paris'));
});

it('counts an exempted trainee out of the expected trainees of the month grid', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02');
    $lea = atEnrol($session, 'Durand');
    atEnrol($session, 'Petit');
    app(Writer::class)->exemptSeances($lea, [$seance->id], null);

    $rows = AttendanceRows::session($session->refresh(), atManager(), '2026-09');

    expect($rows['seances'][0]['expected'])->toBe(1);
});

it('gives an exempted module no planned minutes in the realised hours', function () {
    $session = plSession();
    plModule($session);
    $workshop = atIntervalModule($session, 600);
    $lea = atEnrol($session);
    app(Writer::class)->exemptModule($lea, $workshop->id, null);

    $byModule = collect(RealisedHours::forEnrolment($lea->id)['by_module'])->keyBy('module_id');

    expect($byModule[$workshop->id]['planned'])->toBe(0);
});

it('reduces the planned minutes of a module by his own hours in the realised hours', function () {
    $session = plSession();
    $module = plModule($session);
    $seance = plSeance($module, '2026-09-02', '09:00', '17:00', ['break_minutes' => 60]);
    $lea = atEnrol($session);
    app(Writer::class)->setOwnHours($lea, $seance->id, '14:00', '17:00', 0);

    expect(collect(RealisedHours::forEnrolment($lea->id)['by_module'])->firstWhere('module_id', $module->id)['planned'])->toBe(1020);
});

it('bills the absence of a trainee with own hours on his own duration', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '17:00', ['break_minutes' => 60]);
    $lea = atEnrol($session);
    app(Writer::class)->setOwnHours($lea, $seance->id, '14:00', '17:00', 0);
    app(Attendances::class)->record($seance, $lea->id, 'absent', null, null);
    $this->travelTo(CarbonImmutable::parse('2026-09-10 08:00', 'Europe/Paris'));

    $hours = Billing::hours($lea->dossier_id);

    expect($hours['enrolments'][0]['absent_minutes'])->toBe(180)
        ->and($hours['enrolments'][0]['planned_minutes'])->toBe(1020);
});

it('refuses monthly hours on an exempted module', function () {
    $session = plSession();
    $workshop = atIntervalModule($session, 600);
    $lea = atEnrol($session);
    app(Writer::class)->exemptModule($lea, $workshop->id, null);

    try {
        app(MonthlyHours::class)->record($lea->id, $workshop, '2026-09', 60, null);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors()['enrolment_id'][0])->toBe('Ce stagiaire est dispensé de ce module.');
    }
});

it('lists his séances at his hours without the exempted ones', function () {
    $session = plSession();
    $module = plModule($session);
    $first = plSeance($module, '2026-09-02', '09:00', '17:00', ['break_minutes' => 60]);
    $second = plSeance($module, '2026-09-03', '09:00', '12:30');
    $lea = atEnrol($session);
    app(Writer::class)->setOwnHours($lea, $first->id, '14:00', '17:00', 0);
    app(Writer::class)->exemptSeances($lea, [$second->id], null);

    $planning = IndividualPlanning::seancesOf(collect([$lea->refresh()]))[$lea->id];
    $lines = TraineePlanning::details(collect([$lea]), CarbonImmutable::parse('2026-09-01'))[$lea->id];
    $schedule = Paperwork::enrolment($lea->id)['schedule'];

    expect(count($planning))->toBe(1)->and($planning[0]->start)->toBe('14:00')->and($planning[0]->minutes)->toBe(180)
        ->and($lines)->toHaveCount(1)->and($lines[0])->toContain('14:00–17:00')
        ->and($schedule)->toHaveCount(1)->and($schedule[0]['starts_at'])->toBe('14:00')
        ->and(Paperwork::enrolment($lea->id)['planned_minutes'])->toBe(810); // 21 h − 4 h (own hours) − 3 h 30 (exempted)
});

it('never lists a new séance of an exempted module', function () {
    $session = plSession();
    $module = plModule($session);
    $lea = atEnrol($session);
    app(Writer::class)->exemptModule($lea, $module->id, null);
    plSeance($module, '2026-09-08');

    expect(IndividualPlanning::seancesOf(collect([$lea->refresh()]))[$lea->id])->toBe([])
        ->and(Paperwork::enrolment($lea->id)['schedule'])->toBe([])
        ->and(TraineePlanning::details(collect([$lea]), CarbonImmutable::parse('2026-09-01'))[$lea->id])->toBe([]);
});
