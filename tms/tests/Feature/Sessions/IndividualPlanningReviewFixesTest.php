<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Attendance\Signing\GroupCatchUp;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Planning\EnrolmentAdjustment;
use App\Modules\Sessions\Planning\IndividualPlanningWriter as Writer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';
require_once __DIR__.'/Support/attendance.php';
require_once __DIR__.'/Support/signing.php';

beforeEach(function () {
    plSetUp();
    $this->actingAs(atManager());
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('prints every trainee expected at one séance of the day on the day sheet', function () {
    fakeSheetGotenberg();
    $session = plSession();
    $module = plModule($session);
    $morning = plSeance($module, '2026-09-10', '09:00', '12:00');
    $afternoon = plSeance($module, '2026-09-10', '14:00', '17:00');
    $lea = atEnrol($session, 'Durand');
    atEnrol($session, 'Petit');
    app(Writer::class)->exemptSeances($lea, [$morning->id], null);
    $host = plSession();
    $guestSeance = plSeance(plModule($host), '2026-09-10', '14:00', '17:00');
    $zoe = atEnrol($host, 'Rattrapee');
    app(Writer::class)->exemptSeances($zoe, [$guestSeance->id], null); // irrelevant: Zoé is a guest elsewhere
    $guest = atEnrol(plSession(), 'Invitee');
    app(Writer::class)->addSeances($guest, [$afternoon->id], null);

    $this->get("/seances/{$morning->id}/sheet?day=1")->assertOk();

    expect(sheetHtml())->toContain('Durand')->toContain('Petit')->toContain('Invitee')->not->toContain('Rattrapee');
});

it('offers in a group catch-up only the half-days of his own planning', function () {
    Storage::fake(TenantStorage::DISK);
    sgNow('2026-09-14 10:00');
    $session = plSession();
    $module = plModule($session);
    $first = plSeance($module, '2026-09-10', '09:00', '12:00');
    plSeance($module, '2026-09-10', '14:00', '17:00');
    $full = plSeance($module, '2026-09-11', '09:00', '17:00', ['break_minutes' => 60]);
    $lea = atEnrol($session);
    app(Writer::class)->exemptSeances($lea, [$first->id], null);
    app(Writer::class)->setOwnHours($lea, $full->id, '14:00', '17:00', 0);

    $halves = array_map(fn (array $h) => $h['date'].'|'.$h['half'], GroupCatchUp::candidates($session->refresh(), $lea->id));

    expect($halves)->toBe(['2026-09-10|afternoon', '2026-09-11|afternoon']);
});

it('refuses to remove a module exemption over a closed month', function () {
    $session = plSession('2026-08-01', '2026-09-30');
    $module = plModule($session);
    plSeance($module, '2026-08-20');
    $lea = atEnrol($session);
    app(Writer::class)->exemptModule($lea, $module->id, null);
    app(Closures::class)->close($session, '2026-08');

    expect(fn () => app(Writer::class)->remove(EnrolmentAdjustment::query()->sole()))->toThrow(UserFacingHttpException::class)
        ->and(EnrolmentAdjustment::query()->count())->toBe(1);
});

it('never exempts a trainee from a séance added to him: it is removed instead', function () {
    $lea = atEnrol(plSession());
    $added = plSeance(plModule(plSession()), '2026-09-15');
    app(Writer::class)->addSeances($lea, [$added->id], null);

    try {
        app(Writer::class)->exemptSeances($lea, [$added->id], null);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect(collect($e->errors())->flatten()->first())->toBe(Writer::ADDED_NOT_EXEMPT);
    }
    expect(EnrolmentAdjustment::query()->sole()->kind)->toBe('added_seance');
});

it('keeps a sound base when the planned hours are emptied on a session without hours', function () {
    $session = plSession();
    $module = plModule($session);
    $seance = plSeance($module, '2026-09-02', '09:00', '12:30');
    $lea = atEnrol($session, 'Durand', ['planned_hours' => '20']);
    app(Writer::class)->exemptSeances($lea, [$seance->id], null);
    expect($lea->refresh()->planned_hours)->toBe('16.50');
    DB::table('training_sessions')->where('id', $session->id)->update(['planned_hours' => null]);

    $lea = app(Enrolments::class)->update($lea->refresh(), ['planned_hours' => ''])->refresh();

    expect($lea->planned_hours_base)->toBe('20.00')->and($lea->planned_hours)->toBe('16.50');
});
