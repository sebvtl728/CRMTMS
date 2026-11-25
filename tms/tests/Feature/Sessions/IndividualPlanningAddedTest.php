<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Planning\EnrolmentAdjustment;
use App\Modules\Sessions\Planning\IndividualPlanning;
use App\Modules\Sessions\Planning\IndividualPlanningWriter as Writer;
use App\Modules\Sessions\Planning\Seances;
use App\Modules\Sessions\Public\Paperwork;
use App\Modules\Sessions\Sessions\SessionWriter;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';
require_once __DIR__.'/Support/attendance.php';

beforeEach(function () {
    plSetUp();
    $this->actingAs(atManager());
});

function ipaError(callable $write): string
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

it('expects a trainee at a séance of another session added to him, on his enrolment', function () {
    $own = plSession();
    $host = plSession();
    $catchUp = plSeance(plModule($host), '2026-09-15', '09:00', '12:30');
    $lea = atEnrol($own, 'Durand');
    $marc = atEnrol($host, 'Petit');

    app(Writer::class)->addSeances($lea, [$catchUp->id], 'Rattrapage');

    expect(Attendances::expected($catchUp)->pluck('id')->sort()->values()->all())->toBe(collect([$lea->id, $marc->id])->sort()->values()->all())
        ->and(IndividualPlanning::guestsAt($catchUp)->pluck('id')->all())->toBe([$lea->id])
        ->and($lea->refresh()->planned_hours)->toBe('24.50');

    $attendance = app(Attendances::class)->record($catchUp, $lea->id, 'present', null, null);
    expect($attendance->enrolment_id)->toBe($lea->id)->and($attendance->minutes)->toBe(210);
});

it('refuses a séance of his session, one already planned, one out of his dates', function () {
    $own = plSession();
    $mine = plSeance(plModule($own), '2026-09-02');
    $host = plSession();
    $module = plModule($host);
    $catchUp = plSeance($module, '2026-09-15');
    $late = plSeance($module, '2026-09-25');
    $lea = atEnrol($own);
    app(Writer::class)->addSeances($lea, [$catchUp->id], null);
    app(Enrolments::class)->abandon($lea->refresh(), CarbonImmutable::parse('2026-09-20'), crmItem(ReferenceList::AbandonReason, 'Maladie')->id);

    expect(ipaError(fn () => app(Writer::class)->addSeances($lea->refresh(), [$mine->id], null)))->toBe(Writer::OWN_SESSION)
        ->and(ipaError(fn () => app(Writer::class)->addSeances($lea, [$catchUp->id], null)))->toBe(Writer::ALREADY_PLANNED)
        ->and(ipaError(fn () => app(Writer::class)->addSeances($lea, [$late->id], null)))->toBe(Writer::OUT_OF_DATES)
        ->and(Attendances::expected($late)->pluck('id')->all())->not->toContain($lea->id);
});

it('refuses a cancelled host session, a cancelled séance and a closed month', function () {
    $own = plSession('2026-08-01', '2026-09-30');
    $host = plSession('2026-08-01', '2026-09-30');
    $module = plModule($host);
    $august = plSeance($module, '2026-08-20');
    $cancelled = plSeance($module, '2026-09-02');
    app(Seances::class)->cancel($cancelled, 'Formateur absent');
    $lea = atEnrol($own);
    app(Closures::class)->close($host, '2026-08');

    expect(ipaError(fn () => app(Writer::class)->addSeances($lea, [$cancelled->id], null)))->toBe('409 '.Attendances::CANCELLED_SEANCE)
        ->and(ipaError(fn () => app(Writer::class)->addSeances($lea, [$august->id], null)))->toStartWith('409 ');

    $other = plSession();
    $seance = plSeance(plModule($other), '2026-09-10');
    app(SessionWriter::class)->cancel($other->refresh(), crmItem(ReferenceList::CancelReason, 'Report')->id);
    expect(ipaError(fn () => app(Writer::class)->addSeances($lea, [$seance->id], null)))->toBe(Writer::HOST_UNAVAILABLE);
});

it('never counts the guest among the trainees of the host session', function () {
    $own = plSession();
    $host = plSession();
    $catchUp = plSeance(plModule($host), '2026-09-15');
    $lea = atEnrol($own);
    app(Writer::class)->addSeances($lea, [$catchUp->id], null);

    expect(app(Enrolments::class)->placesTaken($host->refresh()))->toBe(0)
        ->and(Paperwork::sessionEnrolments($host->id))->toBe([]);
});

it('removes an added séance unless an attendance is entered', function () {
    $own = plSession();
    $host = plSession();
    $catchUp = plSeance(plModule($host), '2026-09-15', '09:00', '12:30');
    $lea = atEnrol($own);
    app(Writer::class)->addSeances($lea, [$catchUp->id], null);
    app(Attendances::class)->record($catchUp, $lea->id, 'present', null, null);
    $added = EnrolmentAdjustment::query()->sole();

    expect(ipaError(fn () => app(Writer::class)->remove($added)))->toBe('409 '.Writer::HAS_ATTENDANCE);

    app(Attendances::class)->clear($catchUp, $lea->id);
    app(Writer::class)->remove($added);
    expect($lea->refresh()->planned_hours)->toBe('21.00')->and(Attendance::query()->count())->toBe(0);
});

it('gives an added séance his own hours', function () {
    $own = plSession();
    $host = plSession();
    $catchUp = plSeance(plModule($host), '2026-09-15', '09:00', '17:00', ['break_minutes' => 60]);
    $lea = atEnrol($own);
    app(Writer::class)->addSeances($lea, [$catchUp->id], null);

    app(Writer::class)->setOwnHours($lea, $catchUp->id, '14:00', '17:00', 0);

    expect($lea->refresh()->planned_hours)->toBe('24.00')
        ->and(EnrolmentAdjustment::query()->sole()->kind)->toBe('added_seance');
});
