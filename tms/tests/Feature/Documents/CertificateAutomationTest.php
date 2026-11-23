<?php

use App\Modules\Core\Automation\AutomationSettings;
use App\Modules\Documents\Automation\CertificateAutomation;
use App\Modules\Documents\Document;
use App\Modules\Documents\DocumentIssuer;
use App\Modules\Documents\DocumentStatus;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Attendance\Attendance;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/Support/documents.php';

beforeEach(function () {
    fakeImportDisk();
    plSetUp();
    docGotenberg();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Paris'));
    AutomationSettings::save(['certificates' => true]);
});

/** Every séance of the session attended by all. */
function ctPresent(TrainingSession $session): void
{
    Seance::query()->where('training_session_id', $session->id)->get()
        ->each(fn ($seance) => app(Attendances::class)->allPresent($seance));
}

function ctRun(): int
{
    return app(CertificateAutomation::class)->run(CarbonImmutable::now('Europe/Paris'));
}

it('issues and sends the certificates once the month of the last day is closed', function () {
    $x = docFinishedSession();
    ctPresent($x['session']);
    expect(ctRun())->toBe(0);

    app(Closures::class)->close(TrainingSession::query()->findOrFail($x['session']->id), '2026-11');
    expect(ctRun())->toBe(2);

    $lea = Document::query()->where('enrolment_id', $x['lea']->id)->get()->keyBy('type');
    expect($lea[DocumentType::CompletionCertificate->value]->status)->toBe(DocumentStatus::Sent)
        ->and($lea[DocumentType::RealisationCertificate->value]->status)->toBe(DocumentStatus::Sent)
        ->and(Document::query()->where('enrolment_id', $x['tom']->id)->where('status', DocumentStatus::Issued->value)->count())->toBe(2);
    expect(ctRun())->toBe(0);
});

it('never issues again a cancelled certificate, and does nothing when off', function () {
    $x = docFinishedSession();
    ctPresent($x['session']);
    $doc = app(DocumentIssuer::class)->issueForEnrolment(admin(), DocumentType::CompletionCertificate, $x['lea']->id);
    app(DocumentIssuer::class)->cancel(admin(), $doc, 'Erreur');
    app(Closures::class)->close(TrainingSession::query()->findOrFail($x['session']->id), '2026-11');

    AutomationSettings::save(['certificates' => false]);
    expect(ctRun())->toBe(0)->and(Document::query()->count())->toBe(1);

    AutomationSettings::save(['certificates' => true]);
    ctRun();
    expect(Document::query()->where('enrolment_id', $x['lea']->id)->where('type', DocumentType::CompletionCertificate->value)->count())->toBe(1);
});

it('waits until every month of the session is closed', function () {
    $dossier = l2Dossier();
    $session = l2Session(null, ['starts_on' => '2026-10-28', 'ends_on' => '2026-11-04', 'planned_hours' => '7', 'location_kind' => 'address', 'location_text' => 'Salle B']);
    $module = plModule($session);
    plSeance($module, '2026-10-28', '09:00', '12:30');
    plSeance($module, '2026-11-03', '13:30', '17:00');
    l2Enrol($dossier, $session, makePerson('Durand', ['first_name' => 'Léa', 'email' => 'lea.durand@example.test']));
    ctPresent($session);
    app(Closures::class)->close($session, '2026-11');

    expect(ctRun())->toBe(0);
    app(Closures::class)->close($session, '2026-10');
    expect(ctRun())->toBe(2);
});

it('leaves a trainee with no realised hour to a manager', function () {
    $x = docFinishedSession();
    Attendance::query()->delete();
    app(Closures::class)->close(TrainingSession::query()->findOrFail($x['session']->id), '2026-11');

    expect(ctRun())->toBe(0)->and(Document::query()->count())->toBe(0);
});

// ------------------------------------------------------- last day, 13:30

/** A session ending on Wed. 25/11/2026: Mon. 23 (3 h 30, attended), Wed. 25 morning and afternoon (3 h 30 each). */
function ctLastDaySession(): array
{
    $dossier = l2Dossier();
    $session = l2Session(null, ['starts_on' => '2026-11-23', 'ends_on' => '2026-11-25', 'planned_hours' => '10.5', 'location_kind' => 'address', 'location_text' => 'Salle B, Nantes']);
    $module = plModule($session);
    $monday = plSeance($module, '2026-11-23', '09:00', '12:30');
    $morning = plSeance($module, '2026-11-25', '09:00', '12:30');
    plSeance($module, '2026-11-25', '13:30', '17:00');
    $lea = l2Enrol($dossier, $session, makePerson('Durand', ['first_name' => 'Léa', 'email' => 'lea.durand@example.test']));
    $tom = l2Enrol($dossier, $session, makePerson('Petit', ['first_name' => 'Tom', 'email' => 'tom.petit@example.test']));
    app(Attendances::class)->allPresent($monday);

    return compact('dossier', 'session', 'lea', 'tom', 'morning');
}

function ctLastDayRun(): int
{
    return app(CertificateAutomation::class)->runLastDay(CarbonImmutable::now('Europe/Paris'));
}

it('sends the certificates at 13:30 on the last day, the afternoon planned counted', function () {
    $x = ctLastDaySession();
    $this->travelTo(CarbonImmutable::parse('2026-11-25 13:30', 'Europe/Paris'));

    expect(ctLastDayRun())->toBe(4);

    $doc = Document::query()->where('enrolment_id', $x['lea']->id)->where('type', DocumentType::RealisationCertificate->value)->sole();
    expect($doc->status)->toBe(DocumentStatus::Sent)
        // Monday 3 h 30 recorded + Wednesday morning (not recorded yet) and afternoon: 10 h 30.
        ->and(collect(Http::recorded())->map(fn ($pair) => collect($pair[0]->data())->firstWhere('filename', 'index.html')['contents'] ?? '')
            ->filter(fn (string $html) => str_contains($html, 'Léa'))->implode(''))->toMatch('/10\s*h\s*30/u');
    expect(ctLastDayRun())->toBe(0);
});

it('leaves out a trainee marked absent this morning', function () {
    $x = ctLastDaySession();
    app(Attendances::class)->record($x['morning'], $x['tom']->id, 'absent', null, null);
    $this->travelTo(CarbonImmutable::parse('2026-11-25 13:30', 'Europe/Paris'));

    ctLastDayRun();

    expect(Document::query()->where('enrolment_id', $x['tom']->id)->count())->toBe(0)
        ->and(Document::query()->where('enrolment_id', $x['lea']->id)->count())->toBe(2);
});

it('does nothing on another day than the last one, nor when the automatism is off', function () {
    ctLastDaySession();
    $this->travelTo(CarbonImmutable::parse('2026-11-23 13:30', 'Europe/Paris'));
    expect(ctLastDayRun())->toBe(0);

    AutomationSettings::save(['certificates' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-11-25 13:30', 'Europe/Paris'));
    expect(ctLastDayRun())->toBe(0)->and(Document::query()->count())->toBe(0);
});
