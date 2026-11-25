<?php

use App\Modules\Sessions\Attendance\AttendanceRows;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Planning\IndividualPlanningWriter as Writer;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Public\Billing;
use App\Modules\Sessions\Public\Paperwork;
use App\Modules\Sessions\Public\RealisedHours;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';
require_once __DIR__.'/Support/attendance.php';

beforeEach(function () {
    plSetUp();
    $this->actingAs(atManager());
    $this->travelTo(CarbonImmutable::parse('2026-08-25 08:00', 'Europe/Paris'));
});

/**
 * Léa on her session (September), a catch-up séance of 3 h 30 in October on another one.
 *
 * @return array{own: TrainingSession, host: TrainingSession, lea: Enrolment, mine: Seance, catchUp: Seance}
 */
function iparSetUp(): array
{
    $own = plSession('2026-09-01', '2026-09-30');
    $mine = plSeance(plModule($own), '2026-09-02', '09:00', '12:30');
    $host = plSession('2026-10-01', '2026-10-31');
    $catchUp = plSeance(plModule($host), '2026-10-05', '09:00', '12:30');
    $lea = atEnrol($own);
    app(Writer::class)->addSeances($lea, [$catchUp->id], 'Rattrapage');

    return ['own' => $own, 'host' => $host, 'lea' => $lea->refresh(), 'mine' => $mine, 'catchUp' => $catchUp];
}

it('counts the hours of a catch-up in his realised hours, the same as the invoicing', function () {
    ['lea' => $lea, 'mine' => $mine, 'catchUp' => $catchUp] = iparSetUp();
    app(Attendances::class)->record($mine, $lea->id, 'present', null, null);
    app(Attendances::class)->record($catchUp, $lea->id, 'present', null, null);

    $hours = RealisedHours::forEnrolment($lea->id);

    expect(collect($hours['by_module'])->firstWhere('module_id', null))->toBe(['module_id' => null, 'planned' => 210, 'realised' => 210])
        ->and($hours['realised_minutes'])->toBe(420)
        ->and(Billing::hours($lea->dossier_id)['enrolments'][0]['realised_minutes'])->toBe(420);
});

it('closes the months of a catch-up with its host session', function () {
    ['own' => $own, 'host' => $host, 'lea' => $lea, 'mine' => $mine, 'catchUp' => $catchUp] = iparSetUp();
    app(Attendances::class)->record($mine, $lea->id, 'present', null, null);
    app(Attendances::class)->record($catchUp, $lea->id, 'present', null, null);
    app(Closures::class)->close($own, '2026-09');

    expect(Billing::hours($lea->dossier_id)['enrolments'][0]['all_months_closed'])->toBeFalse()
        ->and(Billing::hours($lea->dossier_id, '2026-10')['enrolments'][0]['realised_minutes'])->toBe(210)
        ->and(Paperwork::catchUpPending($lea->id))->toBeTrue();

    app(Closures::class)->close($host, '2026-10');

    expect(Billing::hours($lea->dossier_id)['enrolments'][0]['all_months_closed'])->toBeTrue()
        ->and(Billing::hours($lea->dossier_id, '2026-10')['enrolments'][0]['realised_minutes'])->toBe(420)
        ->and(Paperwork::catchUpPending($lea->id))->toBeFalse()
        ->and(Paperwork::catchUpPending($lea->id, '2026-09-30'))->toBeTrue()
        ->and(Paperwork::sessionsFinallyClosedSince(CarbonImmutable::parse('2026-08-01')))->toContain($own->id);
});

it('shows the guest in the attendance grid of the host session, not in its hours', function () {
    ['host' => $host, 'lea' => $lea, 'catchUp' => $catchUp] = iparSetUp();

    $grid = AttendanceRows::grid($catchUp);
    $month = AttendanceRows::session($host->refresh(), atManager(), '2026-10');

    expect($grid['rows'][0]['guest'])->toBe('Rattrapage ('.$lea->dossier->number.')')
        ->and($month['seances'][0]['expected'])->toBe(1)
        ->and($month['realised'])->toBe([])
        ->and(RealisedHours::forSession($host->id, '2026-10'))->toBe([]);
});

it('lists the catch-up in his planning and his documents', function () {
    ['lea' => $lea, 'catchUp' => $catchUp] = iparSetUp();

    $schedule = Paperwork::enrolment($lea->id)['schedule'];

    expect(array_column($schedule, 'date'))->toBe(['2026-09-02', '2026-10-05'])
        ->and(Paperwork::enrolment($lea->id)['planned_minutes'])->toBe(1470);
});
