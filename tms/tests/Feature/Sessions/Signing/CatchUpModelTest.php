<?php

use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\Signing\AttendanceSignature;
use App\Modules\Sessions\Attendance\Signing\CatchUpLink;
use App\Modules\Sessions\Attendance\Signing\SignSlot;
use App\Modules\Sessions\Attendance\Signing\SlotValidation;
use App\Modules\Sessions\Public\AttendanceSigning;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
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

it('stores the kind of a signature, on time by default', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    $slotId = sgOpen($session)['slot']->id;

    app(AttendanceSigning::class)->recordExternal($slotId, $lea->id, CarbonImmutable::now(), null, 'fake');

    expect(AttendanceSignature::query()->sole())
        ->kind->toBe(AttendanceSignature::KIND_ON_TIME)
        ->confirmed_at->toBeNull()
        ->isPendingCatchUp()->toBeFalse();
});

it('counts a catch-up only once confirmed when the half-day is validated', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    $slot = sgOpen($session)['slot'];
    app(AttendanceSigning::class)->validateExternal($slot->id, 'fake', null, 'Marie Martin');
    expect(Attendance::query()->where('enrolment_id', $lea->id)->sole()->status)->toBe('absent');

    $catchUp = AttendanceSignature::query()->create([
        'slot_id' => $slot->id, 'enrolment_id' => $lea->id, 'signed_at' => now(), 'kind' => AttendanceSignature::KIND_REMOTE,
    ]);
    expect($catchUp->isPendingCatchUp())->toBeTrue();
    DB::transaction(fn () => app(SlotValidation::class)->recompute($slot->fresh()));
    expect(Attendance::query()->where('enrolment_id', $lea->id)->sole()->status)->toBe('absent');

    $catchUp->forceFill(['confirmed_at' => now()])->save();
    DB::transaction(fn () => app(SlotValidation::class)->recompute($slot->fresh()));
    expect(Attendance::query()->where('enrolment_id', $lea->id)->sole())->status->toBe('present')->minutes->toBe(180);
});

it('keeps one active catch-up link per trainee and half-day', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    $slot = sgOpen($session)['slot'];
    $link = fn (string $hash) => CatchUpLink::query()->create([
        'slot_id' => $slot->id, 'enrolment_id' => $lea->id, 'email' => 'lea@example.test',
        'token_hash' => $hash, 'expires_at' => now()->addDays(7),
    ]);

    $first = $link(str_repeat('a', 64));
    expect(fn () => DB::transaction(fn () => $link(str_repeat('b', 64))))->toThrow(UniqueConstraintViolationException::class);

    $first->forceFill(['revoked_at' => now()])->save();
    expect($link(str_repeat('c', 64))->exists)->toBeTrue();
});

it('adds the on-site catch-up columns to the half-days', function () {
    $session = plSession();
    $slot = SignSlot::query()->create(['training_session_id' => $session->id, 'date' => '2026-09-10', 'half' => SignSlot::MORNING, 'status' => SignSlot::OPEN, 'opened_at' => now()]);
    $slot->forceFill(['catch_up_token_hash' => str_repeat('d', 64), 'catch_up_expires_at' => now()->endOfDay()])->save();

    expect($slot->fresh()->catch_up_token_hash)->toBe(str_repeat('d', 64));
});
