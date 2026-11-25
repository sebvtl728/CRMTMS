<?php

use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Modules\SessionModules;
use App\Modules\Sessions\Planning\EnrolmentAdjustment;
use App\Modules\Sessions\Planning\IndividualPlanning;
use App\Modules\Sessions\Planning\Seances;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';
require_once __DIR__.'/Support/attendance.php';

beforeEach(function () {
    plSetUp();
});

// ---------------------------------------------------------------- exemptions

it('leaves a trainee exempted from a séance out of its expected trainees', function () {
    $session = plSession();
    $module = plModule($session);
    $first = plSeance($module, '2026-09-02');
    $second = plSeance($module, '2026-09-03');
    $lea = atEnrol($session, 'Durand');
    $marc = atEnrol($session, 'Petit');
    EnrolmentAdjustment::query()->create(['enrolment_id' => $lea->id, 'kind' => EnrolmentAdjustment::EXEMPT_SEANCE, 'seance_id' => $first->id]);

    expect(Attendances::expected($first)->pluck('id')->all())->toBe([$marc->id])
        ->and(Attendances::expected($second)->pluck('id')->sort()->values()->all())->toBe(collect([$lea->id, $marc->id])->sort()->values()->all());
});

it('leaves a trainee exempted from a module out of every séance of it, later ones included', function () {
    $session = plSession();
    $module = plModule($session);
    $lea = atEnrol($session, 'Durand');
    $marc = atEnrol($session, 'Petit');
    EnrolmentAdjustment::query()->create(['enrolment_id' => $lea->id, 'kind' => EnrolmentAdjustment::EXEMPT_MODULE, 'session_module_id' => $module->id]);
    $later = plSeance($module, '2026-09-10');

    expect(Attendances::expected($later)->pluck('id')->all())->toBe([$marc->id]);
});

it('refuses the attendance of an exempted trainee', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02');
    $lea = atEnrol($session);
    EnrolmentAdjustment::query()->create(['enrolment_id' => $lea->id, 'kind' => EnrolmentAdjustment::EXEMPT_SEANCE, 'seance_id' => $seance->id]);

    try {
        app(Attendances::class)->record($seance, $lea->id, 'present', null, null);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors()['enrolment_id'][0])->toBe('Ce stagiaire est dispensé de cette séance.');
    }
});

it('isolates the adjustments per tenant', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02');
    $lea = atEnrol($session);
    EnrolmentAdjustment::query()->create(['enrolment_id' => $lea->id, 'kind' => EnrolmentAdjustment::EXEMPT_SEANCE, 'seance_id' => $seance->id]);

    inTenant(Tenant::factory()->create());

    expect(EnrolmentAdjustment::query()->count())->toBe(0);
});

// ---------------------------------------------------------------- own hours

function ipOwnHours(string $enrolmentId, string $seanceId, string $start, string $end, int $break = 0): EnrolmentAdjustment
{
    return EnrolmentAdjustment::query()->create([
        'enrolment_id' => $enrolmentId, 'kind' => EnrolmentAdjustment::OWN_HOURS, 'seance_id' => $seanceId,
        'starts_at' => $start, 'ends_at' => $end, 'break_minutes' => $break,
    ]);
}

it('gives a present trainee with own hours his own duration', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '17:00', ['break_minutes' => 60]);
    $lea = atEnrol($session, 'Durand');
    $marc = atEnrol($session, 'Petit');
    ipOwnHours($lea->id, $seance->id, '14:00', '17:00');
    $attendances = app(Attendances::class);

    expect($attendances->record($seance, $lea->id, 'present', null, null)->minutes)->toBe(180)
        ->and($attendances->record($seance, $marc->id, 'present', null, null)->minutes)->toBe(420)
        ->and(IndividualPlanning::minutesAt($lea->id, $seance))->toBe(180)
        ->and(IndividualPlanning::times($seance, [$lea->id, $marc->id]))->toBe([
            $lea->id => ['start' => '14:00', 'end' => '17:00', 'break' => 0, 'minutes' => 180],
            $marc->id => ['start' => '09:00', 'end' => '17:00', 'break' => 60, 'minutes' => 420],
        ]);
});

it('bounds a partial attendance by his own duration', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '17:00', ['break_minutes' => 60]);
    $lea = atEnrol($session);
    ipOwnHours($lea->id, $seance->id, '14:00', '17:00');

    try {
        app(Attendances::class)->record($seance, $lea->id, 'partial', 180, null);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors()['minutes'][0])->toBe('Une présence partielle dure entre 1 minute et la durée de la séance (3 h) exclue.');
    }
});

it('marks present at his own duration with « Tous présents »', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '17:00', ['break_minutes' => 60]);
    $lea = atEnrol($session);
    ipOwnHours($lea->id, $seance->id, '14:00', '17:00');

    expect(app(Attendances::class)->allPresent($seance))->toBe(1)
        ->and(Attendance::query()->sole()->minutes)->toBe(180);
});

it('reviews a moved séance at his own duration', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '17:00', ['break_minutes' => 60]);
    $lea = atEnrol($session, 'Durand');
    $marc = atEnrol($session, 'Petit');
    ipOwnHours($lea->id, $seance->id, '14:00', '17:00');
    app(Attendances::class)->record($seance, $lea->id, 'present', null, null);
    app(Attendances::class)->record($seance, $marc->id, 'present', null, null);

    app(Seances::class)->reschedule($seance, ['date' => '2026-09-02', 'starts_at' => '08:00', 'ends_at' => '16:00', 'break_minutes' => 60]);

    $rows = Attendance::query()->get()->keyBy('enrolment_id');
    // His own hours kept within the séance: 14:00–16:00.
    expect($rows[$lea->id]->minutes)->toBe(120)->and($rows[$lea->id]->needs_review)->toBeTrue()
        ->and($rows[$marc->id]->minutes)->toBe(420);
});

// ---------------------------------------------------------------- planned hours

function ipExempt(string $enrolmentId, string $seanceId): EnrolmentAdjustment
{
    return EnrolmentAdjustment::query()->create(['enrolment_id' => $enrolmentId, 'kind' => EnrolmentAdjustment::EXEMPT_SEANCE, 'seance_id' => $seanceId]);
}

function ipRefreshed(Enrolment $enrolment): Enrolment
{
    IndividualPlanning::refresh($enrolment->refresh());

    return $enrolment->refresh();
}

it('subtracts an exempted séance from the planned hours', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '12:30');
    $lea = atEnrol($session);
    ipExempt($lea->id, $seance->id);

    $lea = ipRefreshed($lea);

    expect($lea->planned_hours)->toBe('17.50')->and($lea->planned_hours_base)->toBe('21.00');
});

it('subtracts the planned hours of an exempted module', function () {
    $session = plSession();
    plModule($session);
    $workshop = atIntervalModule($session, 600);
    SessionModules::recompute($session->refresh());
    $lea = atEnrol($session->refresh());
    EnrolmentAdjustment::query()->create(['enrolment_id' => $lea->id, 'kind' => EnrolmentAdjustment::EXEMPT_MODULE, 'session_module_id' => $workshop->id]);

    expect(ipRefreshed($lea)->planned_hours)->toBe('21.00');
});

it('never subtracts more than the planned hours of a module', function () {
    $session = plSession();
    $module = plModule($session);
    $lea = atEnrol($session);
    foreach (['2026-09-02', '2026-09-03', '2026-09-04', '2026-09-07'] as $day) {
        ipExempt($lea->id, plSeance($module, $day, '09:00', '17:00', ['break_minutes' => 60])->id);
    }

    expect(ipRefreshed($lea)->planned_hours)->toBe('0.00');
});

it('subtracts the hours a trainee with own hours misses', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '17:00', ['break_minutes' => 60]);
    $lea = atEnrol($session);
    ipOwnHours($lea->id, $seance->id, '14:00', '17:00');

    expect(ipRefreshed($lea)->planned_hours)->toBe('17.00');
});

it('starts from the hours typed by hand and comes back to them', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '12:30');
    $lea = atEnrol($session, 'Durand', ['planned_hours' => '20']);
    $exemption = ipExempt($lea->id, $seance->id);

    expect(ipRefreshed($lea)->planned_hours)->toBe('16.50');

    $exemption->delete();
    $lea = ipRefreshed($lea);

    expect($lea->planned_hours)->toBe('20.00')->and($lea->planned_hours_base)->toBeNull();
});

it('writes the base when the planned hours are typed with adjustments', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '12:30');
    $lea = atEnrol($session);
    ipExempt($lea->id, $seance->id);
    $lea = ipRefreshed($lea);

    $lea = app(Enrolments::class)->update($lea, ['planned_hours' => '18'])->refresh();

    expect($lea->planned_hours_base)->toBe('18.00')->and($lea->planned_hours)->toBe('14.50');
});

it('recomputes when a séance with own hours is cancelled or moved', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '17:00', ['break_minutes' => 60]);
    $lea = atEnrol($session);
    ipOwnHours($lea->id, $seance->id, '14:00', '17:00');
    $lea = ipRefreshed($lea);
    expect($lea->planned_hours)->toBe('17.00');

    $seance = app(Seances::class)->reschedule($seance, ['date' => '2026-09-02', 'starts_at' => '09:00', 'ends_at' => '18:00', 'break_minutes' => 60]);
    expect($lea->refresh()->planned_hours)->toBe('16.00');

    app(Attendances::class)->record($seance, $lea->id, 'present', null, null);
    app(Seances::class)->cancel($seance, 'Formateur absent');

    expect($lea->refresh()->planned_hours)->toBe('21.00')
        ->and(Attendance::query()->sole()->minutes)->toBe(0)
        ->and(Attendance::query()->sole()->needs_review)->toBeTrue();
});

it('recomputes when an exempted séance is deleted', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '12:30');
    $lea = atEnrol($session);
    ipExempt($lea->id, $seance->id);
    expect(ipRefreshed($lea)->planned_hours)->toBe('17.50');

    app(Seances::class)->delete($seance);

    expect($lea->refresh()->planned_hours)->toBe('21.00');
});

it('keeps the base when the session hours change', function () {
    $session = plSession();
    $module = plModule($session);
    $seance = plSeance($module, '2026-09-02', '09:00', '12:30');
    $lea = atEnrol($session);
    ipExempt($lea->id, $seance->id);
    ipRefreshed($lea);

    app(SessionModules::class)->save($session->refresh(), ['title' => $module->title, 'planned_hours' => '30'], $module->refresh());

    expect($lea->refresh()->planned_hours)->toBe('17.50')->and($lea->planned_hours_base)->toBe('21.00');
});
