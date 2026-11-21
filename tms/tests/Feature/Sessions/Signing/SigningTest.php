<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Attendance\Signing\AttendanceSignature;
use App\Modules\Sessions\Attendance\Signing\AttendanceSigning;
use App\Modules\Sessions\Attendance\Signing\SignSlot;
use App\Modules\Sessions\Attendance\Signing\SlotSeances;
use App\Modules\Sessions\Planning\Seances;
use App\Modules\Sessions\Sessions\SessionWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
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
});

afterEach(fn () => CarbonImmutable::setTestNow());

function sgSign(SignSlot $slot, string $enrolmentId): AttendanceSignature
{
    return app(AttendanceSigning::class)->sign($slot, $enrolmentId, sigDrawing(), '10.0.0.1', 'Firefox');
}

it('opens the morning of a full-day séance behind a 40-character token, only its SHA-256 kept', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-10', '09:00', '17:00', ['break_minutes' => 60]);

    ['slot' => $slot, 'token' => $token] = sgOpen($session);

    expect($token)->toMatch('/^[A-Za-z0-9]{40}$/')
        ->and($slot->token_hash)->toBe(hash('sha256', $token))
        ->and($slot->status)->toBe(SignSlot::OPEN)
        ->and(AttendanceSigning::findByToken($token)?->id)->toBe($slot->id);
});

it('gives a new token when reopened, the old one dead', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-10');
    $first = sgOpen($session)['token'];
    ['slot' => $slot, 'token' => $second] = sgOpen($session);

    expect($second)->not->toBe($first)
        ->and(AttendanceSigning::findByToken($first))->toBeNull()
        ->and(AttendanceSigning::findByToken($second)?->id)->toBe($slot->id)
        ->and(SignSlot::query()->count())->toBe(1);

    app(AttendanceSigning::class)->close($slot);
    expect(AttendanceSigning::findByToken($second))->toBeNull()->and($slot->fresh()->status)->toBe(SignSlot::CLOSED);
});

it('opens only on the day, a half-day with a séance, a live session and an open month (409)', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    plSeance(plModule($session), '2026-09-11', '09:00', '12:00');

    expect(fn () => sgOpen($session, '2026-09-11'))->toThrow(UserFacingHttpException::class, 'L\'émargement en ligne s\'ouvre le jour même.');
    expect(fn () => sgOpen($session, '2026-09-10', SignSlot::AFTERNOON))->toThrow(UserFacingHttpException::class, 'Aucune séance cet après-midi.');

    app(Seances::class)->cancel($seance, 'Intempéries');
    expect(fn () => sgOpen($session))->toThrow(UserFacingHttpException::class, 'Aucune séance ce matin.');
});

it('refuses to open a closed month or a cancelled session', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-10');
    app(Closures::class)->close($session, '2026-09');
    expect(fn () => sgOpen($session))->toThrow(UserFacingHttpException::class);

    $other = plSession();
    plSeance(plModule($other), '2026-09-10');
    app(SessionWriter::class)->cancel($other, crmItem(ReferenceList::CancelReason, 'Effectif insuffisant')->id);
    expect(fn () => sgOpen($other->fresh()))->toThrow(UserFacingHttpException::class, 'Cette session est annulée.');
});

it('finds no slot for a malformed token, another day or a session in the trash', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-10');
    $token = sgOpen($session)['token'];

    expect(AttendanceSigning::findByToken('abc'))->toBeNull()
        ->and(AttendanceSigning::findByToken(str_repeat('a', 40)))->toBeNull();

    sgNow('2026-09-11 08:00');
    expect(AttendanceSigning::findByToken($token))->toBeNull();

    sgNow();
    $session->delete();
    expect(AttendanceSigning::findByToken($token))->toBeNull();
});

it('records a drawn signature once per trainee expected that day', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-10');
    $lea = atEnrol($session, 'Durand');
    $late = atEnrol($session, 'Tardif', ['entered_on' => '2026-09-14']);
    $other = atEnrol(plSession(), 'Ailleurs');
    $slot = sgOpen($session)['slot'];

    $signature = sgSign($slot, $lea->id);

    expect($signature->source)->toBe('online')
        ->and($signature->ip)->toBe('10.0.0.1')
        ->and(Storage::disk(TenantStorage::DISK)->exists((string) $signature->drawing_path))->toBeTrue()
        ->and($signature->drawing_sha256)->toBe(hash('sha256', (string) Storage::disk(TenantStorage::DISK)->get((string) $signature->drawing_path)));
    expect(fn () => sgSign($slot, $lea->id))->toThrow(UserFacingHttpException::class, 'Ce nom a déjà signé.');
    expect(fn () => sgSign($slot, $late->id))->toThrow(ValidationException::class);
    expect(fn () => sgSign($slot, $other->id))->toThrow(ValidationException::class);
    expect(fn () => app(AttendanceSigning::class)->sign($slot, $lea->id, sigDrawing(blank: true), '', ''))->toThrow(ValidationException::class);
});

it('refuses a signature on a closed slot', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-10');
    $lea = atEnrol($session, 'Durand');
    $slot = sgOpen($session)['slot'];
    app(AttendanceSigning::class)->close($slot);

    expect(fn () => sgSign($slot->fresh(), $lea->id))->toThrow(UserFacingHttpException::class);
});

it('erases a signature made under the wrong name', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-10');
    $lea = atEnrol($session, 'Durand');
    $slot = sgOpen($session)['slot'];
    $signature = sgSign($slot, $lea->id);

    app(AttendanceSigning::class)->erase($signature);

    expect(AttendanceSignature::query()->count())->toBe(0)
        ->and(Storage::disk(TenantStorage::DISK)->exists((string) $signature->drawing_path))->toBeFalse();
    sgSign($slot, $lea->id);
});

it('shows the first name and initial only, homonyms numbered', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-10');
    $a = atEnrol($session, 'Durand');
    $b = atEnrol($session, 'Dupont');
    $c = atEnrol($session, 'Martin');
    $slot = sgOpen($session)['slot'];

    $names = AttendanceSigning::publicNames(SlotSeances::expected($slot));

    $first = strcmp($a->id, $b->id) < 0 ? $a : $b;
    $second = $first->is($a) ? $b : $a;
    expect($names[$first->id])->toBe('Léa D.')
        ->and($names[$second->id])->toBe('Léa D. (2)')
        ->and($names[$c->id])->toBe('Léa M.');
});

it('journals no name, path nor address', function () {
    $session = plSession();
    plSeance(plModule($session), '2026-09-10');
    $lea = atEnrol($session, 'Durand');
    $signature = sgSign(sgOpen($session)['slot'], $lea->id);

    $log = (string) json_encode(DB::table('audit_logs')->where('auditable_type', 'attendance_signature')->get());
    expect($log)->not->toContain('Durand')->not->toContain((string) $signature->drawing_path)->not->toContain('10.0.0.1')->not->toContain('Firefox');
});
