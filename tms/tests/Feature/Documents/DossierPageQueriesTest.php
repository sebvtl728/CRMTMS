<?php

use App\Modules\Documents\DocumentIssuer;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/documents.php';

beforeEach(function () {
    fakeImportDisk();
    plSetUp();
    docGotenberg();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Paris'));
});

/**
 * The queries of a page, the first visit reading permissions and settings.
 *
 * @return list<string>
 */
function dpQueries(string $url): array
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

/** One more trainee on the dossier's session, with his own trainee forms. */
function dpTrainee(Dossier $dossier, string $name): void
{
    $session = TrainingSession::query()->sole();
    $enrolment = l2Enrol($dossier, $session, makePerson($name, ['first_name' => 'Sam']));
    foreach (DocumentType::fromDossier() as $type) {
        app(DocumentIssuer::class)->issueForEnrolment(admin(), $type, $enrolment->id);
    }
}

it('serves the dossier page with a number of queries independent of its trainees and documents', function () {
    $dossier = docCompanyDossier();
    app(DocumentIssuer::class)->issue(admin(), DocumentType::Convention, $dossier->id);
    dpTrainee($dossier, 'Alpha');
    $this->actingAs(admin(twoFactor: true));
    $few = dpQueries("/dossiers/{$dossier->id}");

    foreach (['Bravo', 'Charlie', 'Delta', 'Echo'] as $name) {
        dpTrainee($dossier, $name);
    }
    $many = dpQueries("/dossiers/{$dossier->id}");

    // At most the names of the history's authors, read once when the history shows one.
    expect(count($many))->toBeLessThanOrEqual(count($few) + 1);
});
