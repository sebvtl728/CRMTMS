<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\References\ReferenceList;
use Carbon\CarbonImmutable;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Attendance\MonthlyHours;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Planning\EnrolmentAdjustment;
use App\Modules\Sessions\Planning\IndividualPlanningWriter as Writer;
use App\Modules\Sessions\Planning\Seances;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';
require_once __DIR__.'/Support/attendance.php';

beforeEach(function () {
    plSetUp();
    $this->actingAs(atManager());
});

function ipwError(callable $write): string
{
    try {
        $write();
    } catch (ValidationException $e) {
        return collect($e->errors())->flatten()->first();
    } catch (UserFacingHttpException $e) {
        return $e->getStatusCode().' '.$e->getMessage();
    }

    return 'aucune erreur';
}

it('exempts a trainee from séances and computes his planned hours', function () {
    $session = plSession();
    $module = plModule($session);
    $first = plSeance($module, '2026-09-02', '09:00', '12:30');
    $second = plSeance($module, '2026-09-03', '09:00', '12:30');
    $lea = atEnrol($session);

    app(Writer::class)->exemptSeances($lea, [$first->id, $second->id], 'Acquis en VAE');

    expect(EnrolmentAdjustment::query()->where('kind', 'exempt_seance')->count())->toBe(2)
        ->and($lea->refresh()->planned_hours)->toBe('14.00')
        ->and(Attendances::expected($first)->count())->toBe(0);
    // Exempted again: nothing more.
    app(Writer::class)->exemptSeances($lea, [$first->id], null);
    expect(EnrolmentAdjustment::query()->count())->toBe(2);
});

it('journals the exemption without its reason', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02');
    $lea = atEnrol($session);

    app(Writer::class)->exemptSeances($lea, [$seance->id], 'Raison médicale');

    $log = AuditLog::query()->where('auditable_type', EnrolmentAdjustment::MORPH_ALIAS)->sole();
    expect($log->new_values['reason'])->toBe('[donnée personnelle]');
});

it('refuses to exempt a trainee with an attendance at the séance', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02');
    $lea = atEnrol($session);
    app(Attendances::class)->record($seance, $lea->id, 'present', null, null);

    expect(ipwError(fn () => app(Writer::class)->exemptSeances($lea, [$seance->id], null)))
        ->toBe('409 '.Writer::HAS_ATTENDANCE)
        ->and(EnrolmentAdjustment::query()->count())->toBe(0);
});

it('refuses a séance of another session or out of his dates', function () {
    $session = plSession();
    $other = plSeance(plModule(plSession()), '2026-09-02');
    $module = plModule($session);
    $early = plSeance($module, '2026-09-01');
    $late = plSeance($module, '2026-09-20');
    $lea = atEnrol($session, 'Durand', ['entered_on' => '2026-09-02']);

    expect(ipwError(fn () => app(Writer::class)->exemptSeances($lea, [$other->id], null)))->toBe(Writer::NOT_HIS)
        ->and(ipwError(fn () => app(Writer::class)->exemptSeances($lea, [$early->id], null)))->toBe(Writer::NOT_HIS);

    app(Enrolments::class)->abandon($lea->refresh(), CarbonImmutable::parse('2026-09-10'), crmItem(ReferenceList::AbandonReason, 'Maladie')->id);
    expect(ipwError(fn () => app(Writer::class)->exemptSeances($lea->refresh(), [$late->id], null)))->toBe(Writer::NOT_HIS);
});

it('refuses a cancelled séance and a closed month', function () {
    $session = plSession('2026-08-01', '2026-09-30');
    $module = plModule($session);
    $august = plSeance($module, '2026-08-20');
    $cancelled = plSeance($module, '2026-09-02');
    app(Seances::class)->cancel($cancelled, 'Formateur absent');
    $lea = atEnrol($session);
    app(Closures::class)->close($session, '2026-08');

    expect(ipwError(fn () => app(Writer::class)->exemptSeances($lea, [$cancelled->id], null)))->toBe('409 '.Attendances::CANCELLED_SEANCE)
        ->and(ipwError(fn () => app(Writer::class)->exemptSeances($lea, [$august->id], null)))->toStartWith('409 ');
});

it('refuses an inactive trainee', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02');
    $lea = atEnrol($session);
    app(Enrolments::class)->cancel($lea);

    expect(ipwError(fn () => app(Writer::class)->exemptSeances($lea->refresh(), [$seance->id], null)))->toBe(Writer::NOT_LIVE);
});

it('exempts from a whole module, replacing the séance adjustments of it', function () {
    $session = plSession();
    $module = plModule($session);
    $seance = plSeance($module, '2026-09-02', '09:00', '17:00', ['break_minutes' => 60]);
    $lea = atEnrol($session);
    app(Writer::class)->exemptSeances($lea, [$seance->id], null);

    app(Writer::class)->exemptModule($lea, $module->id, null);

    expect(EnrolmentAdjustment::query()->pluck('kind')->all())->toBe(['exempt_module'])
        ->and($lea->refresh()->planned_hours)->toBe('0.00')
        ->and(ipwError(fn () => app(Writer::class)->exemptSeances($lea, [$seance->id], null)))->toBe(Writer::ALREADY_MODULE);
});

it('refuses to exempt from a module with attendance or hours entered', function () {
    $session = plSession();
    $module = plModule($session);
    $seance = plSeance($module, '2026-09-02');
    $workshop = atIntervalModule($session, 600);
    $lea = atEnrol($session);
    app(Attendances::class)->record($seance, $lea->id, 'present', null, null);
    app(MonthlyHours::class)->record($lea->id, $workshop, '2026-09', 120, null);

    expect(ipwError(fn () => app(Writer::class)->exemptModule($lea, $module->id, null)))->toBe('409 '.Writer::HAS_ATTENDANCE)
        ->and(ipwError(fn () => app(Writer::class)->exemptModule($lea, $workshop->id, null)))->toBe('409 '.Writer::HAS_MONTHLY_HOURS);
});

it('sets own hours within the séance and reviews his attendance', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '17:00', ['break_minutes' => 60]);
    $lea = atEnrol($session);
    app(Attendances::class)->record($seance, $lea->id, 'present', null, null);

    expect(ipwError(fn () => app(Writer::class)->setOwnHours($lea, $seance->id, '08:00', '12:00', 0)))->toBe(Writer::OUT_OF_SEANCE);

    app(Writer::class)->setOwnHours($lea, $seance->id, '14:00', '17:00', 0);
    app(Writer::class)->setOwnHours($lea, $seance->id, '13:30', '17:00', 0);

    $attendance = Attendance::query()->sole();
    expect(EnrolmentAdjustment::query()->sole()->starts_at)->toBe('13:30:00')
        ->and($attendance->minutes)->toBe(210)->and($attendance->needs_review)->toBeTrue()
        ->and($lea->refresh()->planned_hours)->toBe('17.50');

    app(Writer::class)->clearOwnHours($lea, $seance->id);
    expect(EnrolmentAdjustment::query()->count())->toBe(0)
        ->and(Attendance::query()->sole()->minutes)->toBe(420)
        ->and($lea->refresh()->planned_hours)->toBe('21.00')
        ->and($lea->planned_hours_base)->toBeNull();
});

it('removes an adjustment and comes back to the base hours', function () {
    $session = plSession();
    $seance = plSeance(plModule($session), '2026-09-02', '09:00', '12:30');
    $lea = atEnrol($session, 'Durand', ['planned_hours' => '20']);
    app(Writer::class)->exemptSeances($lea, [$seance->id], null);
    expect($lea->refresh()->planned_hours)->toBe('16.50');

    app(Writer::class)->remove(EnrolmentAdjustment::query()->sole());

    expect($lea->refresh()->planned_hours)->toBe('20.00')->and(Attendances::expected($seance)->count())->toBe(1);
});
