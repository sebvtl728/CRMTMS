<?php

use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';
require_once __DIR__.'/Support/attendance.php';

beforeEach(function () {
    plSetUp();
    $this->travelTo(CarbonImmutable::parse('2026-09-10 10:00', 'Europe/Paris'));
});

/**
 * The queries of a page, the first visit reading permissions and settings.
 *
 * @return list<string>
 */
function spQueries(string $url): array
{
    test()->get($url)->assertOk();
    Cache::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();
    test()->get($url)->assertOk();
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();
    DB::flushQueryLog();

    return $queries;
}

/** A trainee on his own dossier, present at the séances already held. */
function spTrainee(TrainingSession $session, string $name): void
{
    $enrolment = atEnrol($session, $name);
    foreach (Seance::query()->where('training_session_id', $session->id)->where('date', '<', '2026-09-10')->get() as $seance) {
        app(Attendances::class)->record($seance, $enrolment->id, 'present', null, null);
    }
}

it('serves each step of the session page with a number of queries independent of its trainees', function (string $step) {
    $session = plSession('2026-09-01', '2026-09-30');
    $module = plModule($session);
    foreach (['2026-09-02', '2026-09-08', '2026-09-15', '2026-09-22'] as $day) {
        plSeance($module, $day, '09:00', '12:00');
    }
    spTrainee($session, 'Alpha');
    spTrainee($session, 'Bravo');
    $this->actingAs(admin(twoFactor: true));
    $url = "/sessions/{$session->id}?etape={$step}";
    $few = spQueries($url);

    foreach (['Charlie', 'Delta', 'Echo', 'Foxtrot'] as $name) {
        spTrainee($session, $name);
    }
    $many = spQueries($url);

    expect(count($many))->toBe(count($few));
})->with(['synthese', 'configuration', 'planning', 'stagiaires', 'suivi']);
