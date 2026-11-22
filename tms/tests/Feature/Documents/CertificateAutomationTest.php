<?php

use App\Modules\Core\Automation\AutomationSettings;
use App\Modules\Documents\Automation\CertificateAutomation;
use App\Modules\Documents\Document;
use App\Modules\Documents\DocumentIssuer;
use App\Modules\Documents\DocumentStatus;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
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

function ctRun(): int
{
    return app(CertificateAutomation::class)->run(CarbonImmutable::now('Europe/Paris'));
}

it('issues and sends the certificates once the month of the last day is closed', function () {
    $x = docFinishedSession();
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
    $doc = app(DocumentIssuer::class)->issueForEnrolment(admin(), DocumentType::CompletionCertificate, $x['lea']->id);
    app(DocumentIssuer::class)->cancel(admin(), $doc, 'Erreur');
    app(Closures::class)->close(TrainingSession::query()->findOrFail($x['session']->id), '2026-11');

    AutomationSettings::save(['certificates' => false]);
    expect(ctRun())->toBe(0)->and(Document::query()->count())->toBe(1);

    AutomationSettings::save(['certificates' => true]);
    ctRun();
    expect(Document::query()->where('enrolment_id', $x['lea']->id)->where('type', DocumentType::CompletionCertificate->value)->count())->toBe(1);
});
