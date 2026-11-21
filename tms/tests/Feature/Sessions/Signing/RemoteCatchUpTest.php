<?php

use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\People\Person;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Attendance\Signing\AttendanceSignature;
use App\Modules\Sessions\Attendance\Signing\CatchUpLink;
use App\Modules\Sessions\Attendance\Signing\CatchUpMail;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Public\AttendanceSigning;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../Support/lot2a.php';
require_once __DIR__.'/../Support/planning.php';
require_once __DIR__.'/../Support/attendance.php';
require_once __DIR__.'/../Support/signing.php';

beforeEach(function () {
    plSetUp();
    Storage::fake(TenantStorage::DISK);
    Mail::fake();
    sgNow();
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * A morning opened on 10/09 where Léa did not sign, the clock on 11/09.
 *
 * @return array{0: string, 1: string} [slot id, Léa's enrolment id]
 */
function rcSlot(): array
{
    $session = plSession();
    plSeance(plModule($session), '2026-09-10', '09:00', '12:00');
    $lea = atEnrol($session, 'Durand');
    $slotId = sgOpen($session)['slot']->id;
    sgNow('2026-09-11 10:00');

    return [$slotId, $lea->id];
}

/** Sends Léa her link by HTTP and returns the token read from the e-mail. */
function rcSend(string $slotId, string $enrolmentId): string
{
    test()->flushSession();
    test()->actingAs(atManager())->postJson("/sessions/emargement/{$slotId}/catch-up-links", ['enrolment_id' => $enrolmentId, 'email' => 'lea@example.test'])->assertOk();
    $token = null;
    Mail::assertSent(CatchUpMail::class, function (CatchUpMail $mail) use (&$token) {
        if (preg_match('#/emargement/l/([A-Za-z0-9]{40})#', $mail->text, $m) === 1) {
            $token = $m[1];
        }

        return true;
    });
    auth()->logout();

    return (string) $token;
}

/** The code e-mailed last. */
function rcCode(): string
{
    $code = null;
    Mail::assertSent(CatchUpMail::class, function (CatchUpMail $mail) use (&$code) {
        if (preg_match('/\b(\d{6})\b/', $mail->mailSubject, $m) === 1) {
            $code = $m[1];
        }

        return true;
    });

    return (string) $code;
}

it('sends a personal link by e-mail and lets the trainee sign with the e-mailed code', function () {
    [$slotId, $leaId] = rcSlot();
    $token = rcSend($slotId, $leaId);
    Mail::assertSent(CatchUpMail::class, fn (CatchUpMail $m) => $m->hasTo('lea@example.test') && str_contains($m->mailSubject, 'jeudi 10 septembre 2026'));

    $this->get("/emargement/l/{$token}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('public/attendance-signing')
        ->where('mode', 'catch_up_remote')->where('trainee_first_name', 'Léa')->missing('trainees.0'));
    $this->postJson("/emargement/l/{$token}/code")->assertOk();
    $this->postJson("/emargement/l/{$token}", ['code' => rcCode(), 'drawing' => sigDrawing()])->assertCreated();

    expect(AttendanceSignature::query()->sole())->kind->toBe(AttendanceSignature::KIND_REMOTE)->confirmed_at->toBeNull()
        ->and(CatchUpLink::query()->sole()->used_at)->not->toBeNull();
    $this->get("/emargement/l/{$token}")->assertNotFound();
});

it('replaces a previous link: the first one becomes a 404', function () {
    [$slotId, $leaId] = rcSlot();
    $first = rcSend($slotId, $leaId);
    Mail::fake();
    $second = rcSend($slotId, $leaId);

    $this->get("/emargement/l/{$first}")->assertNotFound();
    $this->get("/emargement/l/{$second}")->assertOk();
});

it('applies the code rules: one per minute, ten minutes, five tries', function () {
    [$slotId, $leaId] = rcSlot();
    $token = rcSend($slotId, $leaId);

    $this->postJson("/emargement/l/{$token}/code")->assertOk();
    $this->postJson("/emargement/l/{$token}/code")->assertStatus(429)->assertJsonPath('message', 'Patientez une minute avant de demander un nouveau code.');
    sgNow('2026-09-11 10:11');
    $this->postJson("/emargement/l/{$token}", ['code' => rcCode(), 'drawing' => sigDrawing()])->assertStatus(422)
        ->assertJsonValidationErrors(['code' => 'Ce code a expiré : demandez-en un nouveau.']);

    Mail::fake();
    $this->postJson("/emargement/l/{$token}/code")->assertOk();
    $good = rcCode();
    $wrong = $good === '000000' ? '111111' : '000000';
    foreach (range(1, 5) as $_) {
        $this->postJson("/emargement/l/{$token}", ['code' => $wrong, 'drawing' => sigDrawing()])->assertStatus(422);
    }
    $this->postJson("/emargement/l/{$token}", ['code' => $good, 'drawing' => sigDrawing()])->assertStatus(422)
        ->assertJsonValidationErrors(['code' => 'Trop d\'essais : demandez un nouveau code.']);
    expect(AttendanceSignature::query()->count())->toBe(0);
});

it('refuses a link for a trainee who already signed, or without an e-mail', function () {
    [$slotId, $leaId] = rcSlot();
    $this->actingAs(atManager());

    $this->postJson("/sessions/emargement/{$slotId}/catch-up-links", ['enrolment_id' => $leaId, 'email' => ''])->assertStatus(422)
        ->assertJsonValidationErrors(['email' => 'Indiquez une adresse e-mail valide.']);
    app(AttendanceSigning::class)->recordExternal($slotId, $leaId, CarbonImmutable::now(), null, 'fake');
    $this->postJson("/sessions/emargement/{$slotId}/catch-up-links", ['enrolment_id' => $leaId, 'email' => 'lea@example.test'])->assertStatus(422)
        ->assertJsonValidationErrors(['enrolment_id' => 'Ce stagiaire a déjà signé cette demi-journée.']);
    Mail::assertNothingSent();
});

it('answers the same 404 for an unknown, used, expired or closed-month link', function () {
    [$slotId, $leaId] = rcSlot();
    $this->get('/emargement/l/'.str_repeat('a', 40))->assertNotFound();

    $token = rcSend($slotId, $leaId);
    sgNow('2026-09-18 10:01');
    $this->get("/emargement/l/{$token}")->assertNotFound();

    sgNow('2026-10-02 10:00');
    Mail::fake();
    $token = rcSend($slotId, $leaId);
    $this->get("/emargement/l/{$token}")->assertOk();
    app(Closures::class)->close(TrainingSession::query()->sole(), '2026-09');
    $this->get("/emargement/l/{$token}")->assertNotFound();
});

it('shows the trainer the prefilled address and the link sent', function () {
    [$slotId, $leaId] = rcSlot();
    Person::query()->whereKey(Enrolment::query()->findOrFail($leaId)->person_id)->update(['email' => 'lea.durand@example.test']);
    $this->actingAs(atManager());

    $this->getJson("/sessions/emargement/{$slotId}/state")->assertOk()
        ->assertJsonPath('rows.0.email', 'lea.durand@example.test')->assertJsonPath('rows.0.catch_up_link', null);
    $this->postJson("/sessions/emargement/{$slotId}/catch-up-links", ['enrolment_id' => $leaId, 'email' => 'lea@example.test'])->assertOk()
        ->assertJsonPath('rows.0.catch_up_link.email_status', 'sent');
});
