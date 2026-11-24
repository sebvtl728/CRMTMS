<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Attendance\Signing\AttendanceSignature;
use App\Modules\Sessions\Attendance\Signing\AttendanceSigning;
use App\Modules\Sessions\Attendance\Signing\SignSlot;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Public\Admission;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';
require_once __DIR__.'/Support/attendance.php';
require_once __DIR__.'/Support/signing.php';

beforeEach(function () {
    plSetUp();
    Storage::fake(TenantStorage::DISK);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * A session of September with three séances (8th past, 10th today morning,
 * 15th to come), Léa and Paul enrolled; Léa present on the 8th, both signed
 * the 10th's morning, Léa caught up the 8th's morning remotely (pending).
 *
 * @return array{session: TrainingSession, lea: Enrolment, paul: Enrolment, paulSignature: AttendanceSignature}
 */
function fuSetUp(): array
{
    sgNow('2026-09-08 09:00');
    $session = plSession();
    $module = plModule($session);
    $trainer = plTrainer('Martin');
    $past = plSeance($module, '2026-09-08', '09:00', '12:30', ['trainer_id' => $trainer->id, 'room_id' => plRoom('Salle A')->id]);
    plSeance($module, '2026-09-10', '09:00', '12:00');
    plSeance($module, '2026-09-15', '13:30', '17:00', ['place_text' => 'Classe virtuelle Teams']);
    $lea = atEnrol($session, 'Durand');
    $paul = atEnrol($session, 'Petit');
    test()->actingAs(atManager());
    app(Attendances::class)->record($past, $lea->id, 'present', null, null);

    sgNow('2026-09-10 15:00');
    $slot = sgOpen($session, '2026-09-10')['slot'];
    app(AttendanceSigning::class)->sign($slot, $lea->id, sigDrawing(), '', '');
    app(AttendanceSigning::class)->sign($slot, $paul->id, sigDrawing(), '', '');
    $closed = SignSlot::query()->create(['training_session_id' => $session->id, 'date' => '2026-09-08', 'half' => SignSlot::MORNING,
        'status' => SignSlot::CLOSED, 'opened_at' => now()]);
    AttendanceSignature::query()->create(['slot_id' => $closed->id, 'enrolment_id' => $lea->id, 'drawing_path' => null,
        'signed_at' => now(), 'kind' => AttendanceSignature::KIND_REMOTE]);

    return [
        'session' => $session, 'lea' => $lea, 'paul' => $paul,
        'paulSignature' => AttendanceSignature::query()->where('enrolment_id', $paul->id)->firstOrFail(),
    ];
}

it('gives the planning, the attendance and the signatures of the trainee', function () {
    ['session' => $session, 'lea' => $lea] = fuSetUp();

    $followUp = Admission::followUp($lea->dossier_id, $lea->person_id, atManager());

    expect($followUp['session']['label'])->toContain($session->number)
        ->and($followUp['session']['href'])->toBe("/sessions/{$session->id}")
        ->and($followUp['seances'])->toHaveCount(3);
    [$first, $today, $next] = $followUp['seances'];
    expect($first)->toMatchArray(['date' => '2026-09-08', 'starts_at' => '09:00', 'ends_at' => '12:30', 'duration_minutes' => 210,
        'place' => 'Salle A', 'remote' => false, 'past' => true, 'cancelled' => false])
        ->and($first['trainer'])->toContain('Martin')
        ->and($first['attendance'])->toMatchArray(['status' => 'present', 'label' => 'présent', 'minutes' => 210])
        ->and($today['past'])->toBeTrue()->and($today['attendance'])->toBeNull()
        ->and($next)->toMatchArray(['past' => false, 'place' => 'Classe virtuelle Teams', 'attendance' => null]);

    expect($followUp['hours'])->toMatchArray(['realised_minutes' => 210]);

    $signatures = $followUp['signatures'];
    expect($signatures)->toHaveCount(2)
        ->and($signatures[0])->toMatchArray(['date' => '2026-09-08', 'half' => 'morning', 'half_label' => 'Matin', 'signed' => true,
            'kind' => 'catch_up_remote', 'state' => 'pending', 'drawing_url' => null])
        ->and($signatures[1])->toMatchArray(['date' => '2026-09-10', 'half' => 'morning', 'signed' => true, 'kind' => 'on_time', 'state' => null])
        ->and($signatures[1]['drawing_url'])->toStartWith('/sessions/signatures/')
        ->and($signatures[1]['signed_at'])->not->toBeNull();
});

it('never shows another trainee', function () {
    ['lea' => $lea, 'paul' => $paul, 'paulSignature' => $paulSignature] = fuSetUp();

    $followUp = Admission::followUp($lea->dossier_id, $lea->person_id, atManager());

    expect(collect($followUp['signatures'])->pluck('id')->all())->not->toContain($paulSignature->id)
        // Paul's own person in Léa's dossier: nothing.
        ->and(Admission::followUp($lea->dossier_id, $paul->person_id, atManager()))->toBeNull();
});

it('sends the attendance and the signatures only to who manages the attendance', function () {
    ['lea' => $lea] = fuSetUp();

    $planner = l2User([Permissions::SESSIONS_VIEW => null]);
    $followUp = Admission::followUp($lea->dossier_id, $lea->person_id, $planner);
    expect($followUp['seances'])->toHaveCount(3)
        ->and($followUp['seances'][0])->not->toHaveKey('attendance')
        ->and($followUp)->not->toHaveKey('hours')
        ->and($followUp)->not->toHaveKey('signatures');

    // « Ses séances » of the attendance: a trainer's right, never the follow-up of a trainee.
    $trainer = l2User([Permissions::SESSIONS_VIEW => null, Permissions::ATTENDANCE_MANAGE => AccessScope::Trainer]);
    expect(Admission::followUp($lea->dossier_id, $lea->person_id, $trainer))->not->toHaveKey('signatures');
});

it('sends nothing without the right to see the session', function () {
    ['lea' => $lea] = fuSetUp();

    expect(Admission::followUp($lea->dossier_id, $lea->person_id, l2User([Permissions::ATTENDANCE_MANAGE => null])))->toBeNull()
        ->and(Admission::followUp($lea->dossier_id, $lea->person_id, l2User([Permissions::SESSIONS_VIEW => AccessScope::Own])))->toBeNull()
        ->and(Admission::followUp('not-a-uuid', $lea->person_id, atManager()))->toBeNull();
});

it('serves the drawing of a signature to an attendance manager only', function () {
    ['lea' => $lea, 'paulSignature' => $paulSignature] = fuSetUp();
    $url = Admission::followUp($lea->dossier_id, $lea->person_id, atManager())['signatures'][1]['drawing_url'];

    $this->actingAs(atManager())->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->actingAs(l2User([Permissions::SESSIONS_VIEW => null]))->get($url)->assertForbidden();
    $this->actingAs(l2User([Permissions::ATTENDANCE_MANAGE => AccessScope::Own]))->get($url)->assertNotFound();
    $this->actingAs(atManager())->get('/sessions/signatures/'.$paulSignature->id.'x/dessin')->assertNotFound();
});
