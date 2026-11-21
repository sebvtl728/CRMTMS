<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\Signing\AttendanceSignature;
use App\Modules\Sessions\Public\AttendanceSigning;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Support/lot2a.php';
require_once __DIR__.'/../Support/planning.php';
require_once __DIR__.'/../Support/attendance.php';
require_once __DIR__.'/../Support/signing.php';

beforeEach(function () {
    plSetUp();
    Storage::fake(TenantStorage::DISK);
    sgNow();
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('lists the half-days with their séances and expected trainees for a connector', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    sgOpen($session);

    $slots = app(AttendanceSigning::class)->slots($session->id, '2026-09-01', '2026-09-30');

    expect($slots)->toHaveCount(1)
        ->and($slots[0]['half'])->toBe('morning')->and($slots[0]['date'])->toBe('2026-09-10')
        ->and($slots[0]['seance_ids'])->toBe([$seance->id])
        ->and($slots[0]['expected'])->toBe([['enrolment_id' => $lea->id, 'person_id' => $lea->person_id]]);
});

it('records signatures and the validation coming from a connector, attendance written', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    $paul = atEnrol($session, 'Petit');
    $slotId = sgOpen($session)['slot']->id;
    $port = app(AttendanceSigning::class);

    $port->recordExternal($slotId, $lea->id, CarbonImmutable::parse('2026-09-10 09:05', 'Europe/Paris'), null, 'fake');
    expect(fn () => $port->recordExternal($slotId, $lea->id, CarbonImmutable::now(), null, 'fake'))->toThrow(UserFacingHttpException::class);
    $port->validateExternal($slotId, 'fake', sigDrawing(), 'Marie Martin');

    expect(AttendanceSignature::query()->sole())->source->toBe('fake')->drawing_path->toBeNull();
    expect(Attendance::query()->where('enrolment_id', $lea->id)->sole()->status)->toBe('present')
        ->and(Attendance::query()->where('enrolment_id', $paul->id)->sole()->status)->toBe('absent');
});

it('refuses the online source from a connector', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-10');
    $lea = atEnrol($session, 'Durand');
    $slotId = sgOpen($session)['slot']->id;

    expect(fn () => app(AttendanceSigning::class)->recordExternal($slotId, $lea->id, CarbonImmutable::now(), null, 'online'))
        ->toThrow(InvalidArgumentException::class);
});

it('creates the half-day of a past day for a connector importing afterwards, without any link', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-08', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    $port = app(AttendanceSigning::class);

    $slotId = $port->ensureSlot($session->id, '2026-09-08', 'morning', 'fake');
    expect($port->ensureSlot($session->id, '2026-09-08', 'morning', 'fake'))->toBe($slotId);
    $port->recordExternal($slotId, $lea->id, CarbonImmutable::parse('2026-09-08 09:05', 'Europe/Paris'), sigDrawing(), 'fake');
    $port->validateExternal($slotId, 'fake', null, 'Marie Martin');

    expect(\App\Modules\Sessions\Attendance\Signing\SignSlot::query()->sole())->token_hash->toBeNull()->status->toBe('validated');
    expect(Attendance::query()->sole()->status)->toBe('present');
});
