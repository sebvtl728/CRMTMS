<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Attendance\Signing\AttendanceSigning;
use App\Modules\Sessions\Attendance\Signing\SignSlot;
use App\Modules\Sessions\Attendance\Signing\SlotValidation;
use App\Modules\Sessions\Planning\Seances;
use App\Modules\Sessions\Trainers\Trainers;
use Carbon\CarbonImmutable;
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

function vSign(SignSlot $slot, string $enrolmentId): void
{
    app(AttendanceSigning::class)->sign($slot, $enrolmentId, sigDrawing(), '', '');
}

function vValidate(SignSlot $slot, ?User $by = null): SignSlot
{
    $by ??= atManager();
    test()->actingAs($by);

    return app(SlotValidation::class)->validate($slot->fresh(), sigDrawing(), $by);
}

function vAttendance(string $seanceId, string $enrolmentId): ?Attendance
{
    return Attendance::query()->where('seance_id', $seanceId)->where('enrolment_id', $enrolmentId)->first();
}

it('marks present who signed and absent who did not, from the signatures', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    $paul = atEnrol($session, 'Petit');
    $slot = sgOpen($session)['slot'];
    vSign($slot, $lea->id);

    $done = vValidate($slot);

    expect($done->status)->toBe(SignSlot::VALIDATED)->and($done->token_hash)->toBeNull()
        ->and($done->validator_role)->toBe(SignSlot::ROLE_MANAGER)
        ->and(Storage::disk(TenantStorage::DISK)->exists((string) $done->trainer_signature_path))->toBeTrue();
    expect(vAttendance($seance->id, $lea->id))->status->toBe('present')->minutes->toBe(180)->source->toBe('signing');
    expect(vAttendance($seance->id, $paul->id))->status->toBe('absent')->minutes->toBe(0);
    expect(fn () => vValidate($slot))->toThrow(UserFacingHttpException::class, 'Cette demi-journée est déjà validée.');
});

it('writes a full day partial after the morning, present after the afternoon', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10', '09:00', '17:00', ['break_minutes' => 60]);
    $lea = atEnrol($session, 'Durand');
    $paul = atEnrol($session, 'Petit');
    $morning = sgOpen($session)['slot'];
    vSign($morning, $lea->id);
    vValidate($morning);
    expect(vAttendance($seance->id, $lea->id))->status->toBe('partial')->minutes->toBe(210);
    expect(vAttendance($seance->id, $paul->id))->status->toBe('absent');

    sgNow('2026-09-10 14:00');
    $afternoon = sgOpen($session, half: SignSlot::AFTERNOON)['slot'];
    vSign($afternoon, $lea->id);
    vSign($afternoon, $paul->id);
    vValidate($afternoon);

    expect(vAttendance($seance->id, $lea->id))->status->toBe('present')->minutes->toBe(420);
    expect(vAttendance($seance->id, $paul->id))->status->toBe('partial')->minutes->toBe(210);
});

it('never overwrites an attendance entered by hand', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    test()->actingAs(atManager());
    app(Attendances::class)->record($seance, $lea->id, 'excused', null, 'Rendez-vous médical');
    $slot = sgOpen($session)['slot'];

    vValidate($slot);

    expect(vAttendance($seance->id, $lea->id))->status->toBe('excused')->source->toBeNull();
});

it('gives back to the hand a row corrected after validation', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    vValidate(sgOpen($session)['slot']);
    expect(vAttendance($seance->id, $lea->id)->source)->toBe('signing');

    app(Attendances::class)->record($seance, $lea->id, 'excused', null, null);

    expect(vAttendance($seance->id, $lea->id)->source)->toBeNull();
});

it('validates nothing when the month got closed meanwhile (409)', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    $slot = sgOpen($session)['slot'];
    vSign($slot, $lea->id);
    test()->actingAs(atManager());
    app(Closures::class)->close($session, '2026-09');

    expect(fn () => vValidate($slot))->toThrow(UserFacingHttpException::class);
    expect($slot->fresh()->status)->toBe(SignSlot::OPEN)->and(vAttendance($seance->id, $lea->id))->toBeNull();
});

it('lets the trainer validate his own half-day, never one holding another trainer\'s séance (403)', function () {
    $a = userWithRole(Role::Formateur);
    $trainerA = Trainers::forUser($a) ?? Trainers::ensureForUser($a);
    $b = userWithRole(Role::Formateur);
    $trainerB = Trainers::forUser($b) ?? Trainers::ensureForUser($b);
    $session = plSession();
    $module = plModule($session);
    plSeance($module, '2026-09-10', '09:00', '10:30', ['trainer_id' => $trainerA->id]);
    $other = plSession();
    plSeance(plModule($other), '2026-09-10', '09:00', '12:00', ['trainer_id' => $trainerA->id]);
    plSeance($module, '2026-09-10', '10:30', '12:00', ['trainer_id' => $trainerB->id]);
    $mixed = sgOpen($session)['slot'];

    expect(fn () => vValidate($mixed, $a))->toThrow(UserFacingHttpException::class, 'Une autre séance de cette demi-journée est animée par un autre formateur : un gestionnaire des présences la valide.');
    expect(vValidate($mixed)->validator_role)->toBe(SignSlot::ROLE_MANAGER);

    $own = vValidate(sgOpen($other)['slot'], $a);
    expect($own->validator_role)->toBe(SignSlot::ROLE_TRAINER)->and($own->trainer_name)->toBe($a->name);
});

it('validates later, the slot left open the day before', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    $slot = sgOpen($session)['slot'];
    vSign($slot, $lea->id);
    sgNow('2026-09-12 10:00');

    vValidate($slot);

    expect(vAttendance($seance->id, $lea->id)->status)->toBe('present');
});

it('keeps the signing rows under review when the séance moves', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    $slot = sgOpen($session)['slot'];
    vSign($slot, $lea->id);
    vValidate($slot);

    app(Seances::class)->reschedule($seance->refresh(), ['ends_at' => '11:00', 'break_minutes' => 0]);

    expect(vAttendance($seance->id, $lea->id))->source->toBe('signing')->needs_review->toBeTrue()->minutes->toBe(120);
});

it('locks the session before the slot when validating, as when opening (no lost update, no deadlock)', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-10', '09:00', '17:00', ['break_minutes' => 60]);
    atEnrol($session, 'Durand');
    $slot = sgOpen($session)['slot'];
    $by = atManager();
    test()->actingAs($by);
    DB::enableQueryLog();
    app(SlotValidation::class)->validate($slot->fresh(), sigDrawing(), $by);
    $log = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $locks = $log->filter(fn (string $q) => str_contains($q, ' for update') || str_contains($q, ' for share'))
        ->map(fn (string $q) => str_contains($q, '"training_sessions"') ? 'session' : (str_contains($q, '"attendance_sign_slots"') ? 'slot' : 'other'))
        ->values()->all();
    expect($locks[0])->toBe('session')->and(array_search('slot', $locks, true))->toBeGreaterThan(0);
});
