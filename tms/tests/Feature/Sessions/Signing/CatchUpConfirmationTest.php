<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Attendance\Signing\AttendanceSignature;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Public\AttendanceSigning;
use App\Modules\Sessions\Sessions\TrainingSession;
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

/**
 * A validated morning where Léa did not sign, then her remote catch-up (unconfirmed).
 *
 * @return array{0: TrainingSession, 1: Seance, 2: string, 3: AttendanceSignature}
 */
function cuPending(): array
{
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    $slot = sgOpen($session)['slot'];
    app(AttendanceSigning::class)->validateExternal($slot->id, 'fake', null, 'Marie Martin');
    $signature = AttendanceSignature::query()->create([
        'slot_id' => $slot->id, 'enrolment_id' => $lea->id, 'signed_at' => now(), 'kind' => AttendanceSignature::KIND_REMOTE,
    ]);

    return [$session, $seance, $lea->id, $signature];
}

it('confirms a catch-up: the trainee becomes present on the validated half-day', function () {
    [, , $leaId, $signature] = cuPending();
    expect(Attendance::query()->where('enrolment_id', $leaId)->sole()->status)->toBe('absent');

    $this->actingAs($manager = atManager())->postJson("/sessions/rattrapages/{$signature->id}/confirm")->assertOk();

    expect(Attendance::query()->where('enrolment_id', $leaId)->sole()->status)->toBe('present')
        ->and($signature->refresh())->confirmed_at->not->toBeNull()->confirmed_by->toBe($manager->id);
    expect(json_encode(AuditLog::query()->where('auditable_id', $signature->id)->pluck('new_values')))->not->toContain('Durand');
    $this->postJson("/sessions/rattrapages/{$signature->id}/confirm")->assertStatus(409);
});

it('refuses a catch-up with a reason, keeping the signature without attendance', function () {
    [, , $leaId, $signature] = cuPending();
    $this->actingAs(atManager());

    $this->postJson("/sessions/rattrapages/{$signature->id}/refuse", ['reason' => ''])->assertStatus(422)
        ->assertJsonValidationErrors(['reason' => 'Indiquez un motif (300 caractères au plus).']);
    $this->postJson("/sessions/rattrapages/{$signature->id}/refuse", ['reason' => 'Absent selon le formateur'])->assertOk();

    expect(Attendance::query()->where('enrolment_id', $leaId)->sole()->status)->toBe('absent')
        ->and($signature->refresh())->refused_at->not->toBeNull()->refusal_reason->toBe('Absent selon le formateur');
    $this->postJson("/sessions/rattrapages/{$signature->id}/confirm")->assertStatus(409);
});

it('never touches an attendance entered by hand', function () {
    [$session, $seance, $leaId, $signature] = cuPending();
    app(Attendances::class)->record($seance, $leaId, Attendance::EXCUSED, 0, 'Malade');
    $this->actingAs(atManager());

    $this->getJson("/sessions/{$session->id}/attendance?month=2026-09")->assertOk()
        ->assertJsonPath('catch_ups.0.signature_id', $signature->id)->assertJsonPath('catch_ups.0.manual_attendance', true);
    $this->postJson("/sessions/rattrapages/{$signature->id}/confirm")->assertOk();

    expect(Attendance::query()->where('enrolment_id', $leaId)->sole()->status)->toBe('excused')
        ->and($signature->refresh()->confirmed_at)->not->toBeNull();
});

it('refuses to confirm once the month is closed', function () {
    [$session, , , $signature] = cuPending();
    app(Closures::class)->close($session, '2026-09');

    $this->actingAs(atManager())->postJson("/sessions/rattrapages/{$signature->id}/confirm")->assertStatus(409)
        ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains((string) $m, 'est clôturé'))->etc());
    expect($signature->refresh()->confirmed_at)->toBeNull();
});

it('keeps the trainer (« Ses séances ») out of confirmation', function () {
    [, , , $signature] = cuPending();

    $this->actingAs(userWithRole(Role::Formateur))->postJson("/sessions/rattrapages/{$signature->id}/confirm")->assertNotFound();
    expect($signature->refresh()->confirmed_at)->toBeNull();
});

it('lists pending catch-ups on the « Présences » tab', function () {
    [$session, , $leaId, $signature] = cuPending();

    $this->actingAs(atManager())->getJson("/sessions/{$session->id}/attendance?month=2026-09")->assertOk()
        ->assertJsonCount(1, 'catch_ups')
        ->assertJsonPath('catch_ups.0.enrolment_id', $leaId)->assertJsonPath('catch_ups.0.name', 'Léa Durand')
        ->assertJsonPath('catch_ups.0.half_label', 'Matin')->assertJsonPath('catch_ups.0.kind_label', 'À distance')
        ->assertJsonPath('catch_ups.0.manual_attendance', false);

    $this->postJson("/sessions/rattrapages/{$signature->id}/confirm")->assertOk();
    $this->getJson("/sessions/{$session->id}/attendance?month=2026-09")->assertJsonCount(0, 'catch_ups');
});
