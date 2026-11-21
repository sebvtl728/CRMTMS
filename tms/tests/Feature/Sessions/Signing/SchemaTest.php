<?php

use App\Modules\Sessions\Attendance\Signing\AttendanceSignature;
use App\Modules\Sessions\Attendance\Signing\SignedSheet;
use App\Modules\Sessions\Attendance\Signing\SignSlot;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

require_once __DIR__.'/../Support/lot2a.php';
require_once __DIR__.'/../Support/planning.php';
require_once __DIR__.'/../Support/attendance.php';

beforeEach(fn () => plSetUp());

it('stores slots, signatures and signed sheets, one signature per slot and trainee', function () {
    $session = plSession();
    $lea = atEnrol($session, 'Durand');
    $slot = SignSlot::query()->create(['training_session_id' => $session->id, 'date' => '2026-09-10', 'half' => SignSlot::MORNING, 'status' => SignSlot::OPEN, 'opened_at' => now()]);
    $data = ['slot_id' => $slot->id, 'enrolment_id' => $lea->id, 'drawing_sha256' => str_repeat('a', 64), 'signed_at' => now()];
    AttendanceSignature::query()->create($data);
    SignedSheet::query()->create(['training_session_id' => $session->id, 'month' => '2026-09-01', 'path' => 'x.pdf', 'pdf_sha256' => str_repeat('b', 64)]);

    expect($slot->fresh()->date->toDateString())->toBe('2026-09-10')
        ->and(AttendanceSignature::query()->sole()->source)->toBe('online')
        ->and(SignedSheet::query()->count())->toBe(1);
    expect(fn () => AttendanceSignature::query()->create($data))->toThrow(UniqueConstraintViolationException::class);
});

it('refuses an unknown half-day', function () {
    $session = plSession();
    expect(fn () => SignSlot::query()->create(['training_session_id' => $session->id, 'date' => '2026-09-10', 'half' => 'evening', 'status' => SignSlot::OPEN, 'opened_at' => now()]))
        ->toThrow(QueryException::class);
});
