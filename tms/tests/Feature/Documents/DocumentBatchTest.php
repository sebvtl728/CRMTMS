<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Crm\People\Person;
use App\Modules\Documents\Document;
use App\Modules\Documents\DocumentBatch;
use App\Modules\Documents\DocumentIssuer;
use App\Modules\Documents\DocumentMail;
use App\Modules\Documents\DocumentStatus;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Dossiers\DossierStatus;
use App\Modules\Sessions\Dossiers\DossierTransitions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/Support/documents.php';

beforeEach(function () {
    fakeImportDisk();
    plSetUp();
    docGotenberg();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Paris'));
});

it('issues a document for every trainee of the session, skipping those who already have one', function () {
    $x = docFinishedSession();
    app(DocumentIssuer::class)->issueForEnrolment(admin(), DocumentType::Convocation, $x['lea']->id);

    expect(app(DocumentBatch::class)->issue(admin(), DocumentType::Convocation, $x['session']->id))->toBe(1)
        ->and(Document::query()->where('type', 'convocation')->count())->toBe(2)
        ->and(app(DocumentBatch::class)->issue(admin(), DocumentType::Convocation, $x['session']->id))->toBe(0);
});

it('issues nothing when one document fails', function () {
    $x = docFinishedSession();
    // The PDF engine answers once, then fails: the whole batch is rolled back.
    Http::swap(new Factory);
    Http::fake(['gotenberg:3000/*' => Http::sequence()
        ->push(DOC_PDF, 200, ['Content-Type' => 'application/pdf'])->push('down', 500)]);

    expect(fn () => app(DocumentBatch::class)->issue(admin(), DocumentType::CompletionCertificate, $x['session']->id))->toThrow(UserFacingHttpException::class);
    expect(Document::query()->count())->toBe(0);
});

it('sends the issued documents to the trainees who have an e-mail and moves the dossier to convocations sent', function () {
    $x = docFinishedSession();
    $transitions = app(DossierTransitions::class);
    $transitions->sendConvention($x['dossier'], CarbonImmutable::parse('2026-10-20'));
    $transitions->signConvention($x['dossier']->fresh(), CarbonImmutable::parse('2026-10-22'));
    app(DocumentBatch::class)->issue(admin(), DocumentType::Convocation, $x['session']->id);

    $result = app(DocumentBatch::class)->send(admin(), DocumentType::Convocation, $x['session']->id);

    expect($result)->toBe(['queued' => 1, 'without_email' => 1])
        ->and(Document::query()->where('enrolment_id', $x['lea']->id)->sole()->status)->toBe(DocumentStatus::Sent);
    Mail::assertSent(DocumentMail::class, fn ($m) => $m->hasTo('lea.durand@example.test'));
    // Tom has no e-mail: not every trainee is convened yet.
    expect($x['dossier']->fresh()->status)->toBe(DossierStatus::ConventionSigned);

    Person::query()->whereKey($x['tom']->person_id)->update(['email' => 'tom.petit@example.test']);
    // The snapshot of Tom's convocation keeps no e-mail: a new one is issued after cancelling.
    $tomDoc = Document::query()->where('enrolment_id', $x['tom']->id)->sole();
    app(DocumentIssuer::class)->cancel(admin(), $tomDoc, 'Adresse ajoutée');
    app(DocumentBatch::class)->issue(admin(), DocumentType::Convocation, $x['session']->id);
    expect(app(DocumentBatch::class)->send(admin(), DocumentType::Convocation, $x['session']->id))->toBe(['queued' => 1, 'without_email' => 0])
        ->and($x['dossier']->fresh()->status)->toBe(DossierStatus::ConvocationsSent);
});
