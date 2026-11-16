<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Sessions\Attendance\MonthlyHour;
use App\Modules\Sessions\Attendance\MonthlyHours;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\History\SessionsHistory;
use App\Modules\Sessions\Modules\SessionModule;
use App\Modules\Sessions\Modules\SessionModules;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\Trainers\Trainers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';
require_once __DIR__.'/Support/attendance.php';

beforeEach(function () {
    plSetUp();
});

/** The first module of a session made by plSession: an interval module on the session's dates. */
function mhModule(TrainingSession $session): SessionModule
{
    return SessionModule::query()->where('training_session_id', $session->id)->orderBy('position')->firstOrFail();
}

it('records the hours of an interval module per Paris month, one row per trainee, module and month', function () {
    $session = plSession('2026-09-01', '2026-11-30');
    $module = mhModule($session);
    $lea = atEnrol($session);
    $trainer = plTrainer();
    $hours = app(MonthlyHours::class);

    $hours->record($lea->id, $module, '2026-09', 1230, $trainer->id);
    $hours->record($lea->id, $module, '2026-10', 600, null);
    $hours->record($lea->id, $module, '2026-09', 1200, null);

    $rows = MonthlyHour::query()->orderBy('month')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->month->toDateString())->toBe('2026-09-01')
        ->and($rows[0]->minutes)->toBe(1200)
        ->and($rows[0]->trainer_id)->toBeNull()
        ->and($rows[1]->minutes)->toBe(600);
    expect($module->hasActivity())->toBeTrue()
        ->and(SessionModules::rows($session)[0]['has_activity'])->toBeTrue();
});

it('records monthly hours for interval modules only, within the module months', function () {
    $session = plSession('2026-09-01', '2026-10-31');
    $interval = mhModule($session);
    $seances = SessionModule::query()->create(['training_session_id' => $session->id, 'position' => 2, 'title' => 'Ateliers', 'planned_minutes' => 600, 'dating_mode' => 'seances']);
    $lea = atEnrol($session);
    $hours = app(MonthlyHours::class);
    $error = function (callable $call): ?string {
        try {
            $call();
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first();
        }

        return null;
    };

    expect($error(fn () => $hours->record($lea->id, $seances, '2026-09', 60, null)))
        ->toBe('Ce module est daté par séances : ses heures se saisissent par les présences.')
        ->and($error(fn () => $hours->record($lea->id, $interval, '2026-11', 60, null)))->toBe('Ce mois est hors des dates du module (septembre 2026 à octobre 2026).')
        ->and($error(fn () => $hours->record($lea->id, $interval, '2026-13', 60, null)))->toBe('Mois invalide.')
        ->and($error(fn () => $hours->record($lea->id, $interval, '2026-09', -5, null)))->toBe('Indiquez un nombre de minutes entre 0 et 44 640.')
        ->and($error(fn () => $hours->record($lea->id, $interval, '2026-09', 60, '0190d6c5-0000-7000-8000-000000000000')))->toBe('Formateur introuvable.')
        ->and(MonthlyHour::query()->count())->toBe(0);
});

it('takes monthly hours from the entry month of a late trainee and up to the month of an abandon', function () {
    $session = plSession('2026-09-01', '2026-12-31');
    $module = mhModule($session);
    $late = atEnrol($session, 'Tardif', ['entered_on' => '2026-10-15']);
    $leaving = atEnrol($session, 'Parti');
    app(Enrolments::class)->abandon($leaving, CarbonImmutable::parse('2026-11-20'), crmItem(ReferenceList::AbandonReason, 'Maladie')->id);
    $hours = app(MonthlyHours::class);

    expect(fn () => $hours->record($late->id, $module, '2026-09', 60, null))
        ->toThrow(ValidationException::class, 'Ce stagiaire est entré en formation le 15/10/2026 : pas d\'heures avant octobre 2026.');
    $hours->record($late->id, $module, '2026-10', 60, null);
    $hours->record($leaving->id, $module, '2026-11', 60, null);
    expect(fn () => $hours->record($leaving->id, $module, '2026-12', 60, null))
        ->toThrow(ValidationException::class, 'Ce stagiaire a quitté la formation le 20/11/2026 : pas d\'heures après novembre 2026.');
    expect(MonthlyHour::query()->count())->toBe(2);
});

it('enters monthly hours through the session (managers of its scope; a trainer never)', function () {
    $session = plSession('2026-09-01', '2026-10-31');
    $module = mhModule($session);
    $lea = atEnrol($session);
    $formateur = userWithRole(Role::Formateur);
    $mine = Trainers::forUser($formateur) ?? Trainers::ensureForUser($formateur);
    assert($mine !== null);
    plSeance(plModule(plSession()), '2026-09-02', extra: ['trainer_id' => $mine->id]);

    $this->actingAs(atManager())->postJson("/sessions/{$session->id}/monthly-hours", [
        'enrolment_id' => $lea->id, 'session_module_id' => $module->id, 'month' => '2026-09', 'minutes' => 750,
    ])->assertOk();
    expect(MonthlyHour::query()->sole()->minutes)->toBe(750);

    $this->actingAs($formateur)->postJson("/sessions/{$session->id}/monthly-hours", [
        'enrolment_id' => $lea->id, 'session_module_id' => $module->id, 'month' => '2026-09', 'minutes' => 10,
    ])->assertNotFound();
    expect(MonthlyHour::query()->sole()->minutes)->toBe(750);
});

it('journals monthly hours with ids and minutes, and shows them in the history of the dossier', function () {
    $session = plSession('2026-09-01', '2026-10-31');
    $module = mhModule($session);
    $lea = atEnrol($session, 'Durand');
    app(MonthlyHours::class)->record($lea->id, $module, '2026-09', 750, null);
    app(MonthlyHours::class)->record($lea->id, $module, '2026-09', 600, null);
    app(MonthlyHours::class)->clear($lea->id, $module, '2026-09');

    $logs = DB::table('audit_logs')->where('auditable_type', 'monthly_hour')->orderBy('created_at')->get();
    expect($logs->pluck('event')->all())->toBe(['created', 'updated', 'deleted'])
        ->and($logs->map(fn ($l) => $l->old_values.' '.$l->new_values)->implode(' '))->not->toContain('Durand');

    $titles = collect(SessionsHistory::dossierPage($lea->dossier()->firstOrFail()))->pluck('title')->all();
    expect($titles)->toContain('Léa Durand — Heures de septembre 2026 (HACCP 3 jours) : 12 h 30')
        ->toContain('Léa Durand — Heures de septembre 2026 (HACCP 3 jours) modifiées : 10 h')
        ->toContain('Léa Durand — Heures de septembre 2026 (HACCP 3 jours) effacées');
});
