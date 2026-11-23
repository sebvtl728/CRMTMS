<?php

use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Attendance\Signing\AttendanceSignature;
use App\Modules\Sessions\Attendance\Signing\AttendanceSigning;
use App\Modules\Sessions\Attendance\Signing\CatchUpLink;
use App\Modules\Sessions\Attendance\Signing\CatchUpMail;
use App\Modules\Sessions\Attendance\Signing\SignSlot;
use App\Modules\Sessions\Planning\Seance;
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
    sgNow('2026-09-14 10:00');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * Three days of September (8, 9, 10), morning and afternoon each, none
 * signed by Léa; the 9th afternoon she was excused (Wednesday). The clock on 14/09.
 *
 * @return array{session: TrainingSession, lea: string, excused: Seance}
 */
function gcSession(): array
{
    $session = plSession();
    $module = plModule($session);
    $excused = null;
    foreach (['2026-09-08', '2026-09-09', '2026-09-10'] as $day) {
        plSeance($module, $day, '09:00', '12:00');
        $afternoon = plSeance($module, $day, '13:00', '17:00');
        if ($day === '2026-09-09') {
            $excused = $afternoon;
        }
    }
    $lea = atEnrol($session, 'Durand');
    app(Attendances::class)->record($excused, $lea->id, 'excused', null, 'Rendez-vous médical');

    return ['session' => $session, 'lea' => $lea->id, 'excused' => $excused];
}

/** The code e-mailed last. */
function gcCode(): string
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

it('lists the half-days a trainee can still sign, never an excused or absent one', function () {
    $x = gcSession();

    $this->actingAs(atManager())->getJson("/sessions/{$x['session']->id}/rattrapages/demi-journees?enrolment={$x['lea']}")
        ->assertOk()
        ->assertJsonCount(5, 'halves')
        ->assertJsonPath('halves.0', ['date' => '2026-09-08', 'half' => 'morning', 'label' => 'mardi 8 septembre 2026 - matin'])
        ->assertJsonPath('halves', fn (array $h) => ! in_array(['date' => '2026-09-09', 'half' => 'afternoon', 'label' => 'mercredi 9 septembre 2026 - après-midi'], $h, true));
});

it('sends one link for several half-days, signed once with one code', function () {
    $x = gcSession();
    $halves = [['date' => '2026-09-08', 'half' => 'morning'], ['date' => '2026-09-08', 'half' => 'afternoon'], ['date' => '2026-09-10', 'half' => 'morning']];

    $this->actingAs(atManager())->postJson("/sessions/{$x['session']->id}/rattrapages/groupe", ['enrolment_id' => $x['lea'], 'email' => 'lea@example.test', 'halves' => $halves])
        ->assertOk()->assertJsonPath('count', 3);
    Mail::assertSent(CatchUpMail::class, 1);
    $token = null;
    Mail::assertSent(CatchUpMail::class, function (CatchUpMail $mail) use (&$token) {
        preg_match('#/emargement/l/([A-Za-z0-9]{40})#', $mail->text, $m);
        $token = $m[1] ?? null;

        return str_contains($mail->text, 'mardi 8 septembre 2026 - matin') && str_contains($mail->text, 'jeudi 10 septembre 2026 - matin');
    });
    auth()->logout();

    $this->get("/emargement/l/{$token}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('halves', [
        'mardi 8 septembre 2026 - matin', 'mardi 8 septembre 2026 - après-midi', 'jeudi 10 septembre 2026 - matin',
    ]));
    $this->postJson("/emargement/l/{$token}/code")->assertOk();
    $this->postJson("/emargement/l/{$token}", ['code' => gcCode(), 'drawing' => sigDrawing()])->assertCreated();

    expect(AttendanceSignature::query()->where('enrolment_id', $x['lea'])->count())->toBe(3)
        ->and(AttendanceSignature::query()->pluck('kind')->unique()->all())->toBe([AttendanceSignature::KIND_REMOTE])
        ->and(SignSlot::query()->count())->toBe(3)
        ->and(CatchUpLink::query()->sole()->used_at)->not->toBeNull();
    $this->get("/emargement/l/{$token}")->assertNotFound();
});

it('refuses an excused half-day in a group', function () {
    $x = gcSession();

    $this->actingAs(atManager())->postJson("/sessions/{$x['session']->id}/rattrapages/groupe", ['enrolment_id' => $x['lea'], 'email' => 'lea@example.test',
        'halves' => [['date' => '2026-09-09', 'half' => 'afternoon']]])
        ->assertUnprocessable()->assertJsonPath('errors.halves.0', 'Le mercredi 9 septembre 2026 - après-midi ne peut pas être rattrapé (signé, absence notée, mois clôturé ou stagiaire non attendu).');
    Mail::assertNothingSent();
});

it('confirms all the catch-ups of a trainee at once', function () {
    $x = gcSession();
    $this->actingAs(atManager())->postJson("/sessions/{$x['session']->id}/rattrapages/groupe", ['enrolment_id' => $x['lea'], 'email' => 'lea@example.test',
        'halves' => [['date' => '2026-09-08', 'half' => 'morning'], ['date' => '2026-09-10', 'half' => 'morning']]])->assertOk();
    $link = CatchUpLink::query()->sole();
    foreach ($link->slotIds() as $slotId) {
        app(AttendanceSigning::class)->sign(SignSlot::query()->findOrFail($slotId), $x['lea'], sigDrawing(), '', '', requireOpen: false, kind: AttendanceSignature::KIND_REMOTE);
    }

    $this->actingAs(atManager())->postJson("/sessions/{$x['session']->id}/rattrapages/confirmer-tout", ['enrolment_id' => $x['lea']])
        ->assertOk()->assertJsonPath('count', 2);

    expect(AttendanceSignature::query()->whereNull('confirmed_at')->count())->toBe(0);
});
