<?php

use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\Signing\AttendanceSigning;
use App\Modules\Sessions\Attendance\Signing\SignSlot;
use App\Modules\Sessions\Attendance\Signing\SlotSeances;
use App\Modules\Sessions\Attendance\Signing\SlotValidation;
use App\Modules\Sessions\Planning\IndividualPlanningWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/lot2a.php';
require_once __DIR__.'/../Support/planning.php';
require_once __DIR__.'/../Support/attendance.php';
require_once __DIR__.'/../Support/signing.php';

beforeEach(function () {
    plSetUp();
    Storage::fake(TenantStorage::DISK);
    sgNow();
    $this->actingAs(atManager());
});

afterEach(fn () => CarbonImmutable::setTestNow());

function isValidate(SignSlot $slot): void
{
    app(SlotValidation::class)->validate($slot->fresh(), sigDrawing(), atManager());
}

it('expects a trainee with own afternoon hours only at the afternoon half-day', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10', '09:00', '17:00', ['break_minutes' => 60]);
    $lea = atEnrol($session, 'Durand');
    $paul = atEnrol($session, 'Petit');
    app(IndividualPlanningWriter::class)->setOwnHours($lea, $seance->id, '14:00', '17:00', 0);

    $morning = sgOpen($session)['slot'];
    expect(SlotSeances::expected($morning)->pluck('id')->all())->toBe([$paul->id]);
    app(AttendanceSigning::class)->sign($morning, $paul->id, sigDrawing(), '', '');
    isValidate($morning);
    expect(Attendance::query()->where('enrolment_id', $lea->id)->exists())->toBeFalse();

    sgNow('2026-09-10 14:00');
    $afternoon = sgOpen($session, half: SignSlot::AFTERNOON)['slot'];
    expect(SlotSeances::expected($afternoon)->pluck('id')->sort()->values()->all())->toBe(collect([$lea->id, $paul->id])->sort()->values()->all());
    app(AttendanceSigning::class)->sign($afternoon, $lea->id, sigDrawing(), '', '');
    isValidate($afternoon);

    $row = Attendance::query()->where('enrolment_id', $lea->id)->sole();
    expect($row->status)->toBe('present')->and($row->minutes)->toBe(180);
});

it('leaves an exempted trainee out of the half-day and its public names', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    $paul = atEnrol($session, 'Petit');
    app(IndividualPlanningWriter::class)->exemptSeances($lea, [$seance->id], null);
    $slot = sgOpen($session)['slot'];

    expect(SlotSeances::expected($slot)->pluck('id')->all())->toBe([$paul->id])
        ->and(fn () => app(AttendanceSigning::class)->sign($slot, $lea->id, sigDrawing(), '', ''))->toThrow(ValidationException::class);

    app(AttendanceSigning::class)->sign($slot, $paul->id, sigDrawing(), '', '');
    isValidate($slot);
    expect(Attendance::query()->where('enrolment_id', $lea->id)->exists())->toBeFalse();
});
