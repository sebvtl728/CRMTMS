<?php

use App\Modules\Crm\Gdpr\Anonymiser;
use App\Modules\Crm\Gdpr\PersonDataExport;
use App\Modules\Crm\People\Person;
use App\Modules\Documents\DocumentIssuer;
use App\Modules\Documents\DocumentPdf;
use App\Modules\Documents\DocumentSender;
use App\Modules\Documents\DocumentSending;
use App\Modules\Documents\Texts\DocumentType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/Support/documents.php';

beforeEach(function () {
    fakeImportDisk();
    plSetUp();
    docGotenberg();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Paris'));
});

it('erases the recipient and the e-mails of an anonymised particulier, keeping the document', function () {
    $dossier = docIndividualDossier();
    $doc = app(DocumentIssuer::class)->issue(admin(), DocumentType::Contract, $dossier->id);
    app(DocumentSender::class)->send(admin(), $doc, ['to' => 'ana.roux@example.test', 'subject' => 'Contrat', 'body' => 'Bonjour Ana.']);
    $other = app(DocumentIssuer::class)->issue(admin(), DocumentType::Convention, docCompanyDossier()->id);

    app(Anonymiser::class)->anonymise(Person::query()->where('last_name', 'Roux')->firstOrFail(), 'request');

    $doc->refresh();
    expect($doc->recipient_snapshot)->toMatchArray(['name' => 'Personne anonymisée', 'email' => null, 'address_line1' => null, 'person_id' => null])
        ->and(DocumentPdf::archived($doc))->toBe(DOC_PDF)
        ->and(DocumentSending::query()->where('document_id', $doc->id)->sole()->only(['email', 'subject', 'body']))->toBe(['email' => null, 'subject' => null, 'body' => null])
        ->and($other->fresh()->recipient_snapshot['email'])->toBe('claire.martin@hotel-parc.test');
});

it('erases only the contact of a convention, keeping the company', function () {
    $doc = app(DocumentIssuer::class)->issue(admin(), DocumentType::Convention, docCompanyDossier()->id);

    app(Anonymiser::class)->anonymise(Person::query()->where('last_name', 'Martin')->firstOrFail(), 'request');

    expect($doc->fresh()->recipient_snapshot)->toMatchArray(['name' => 'Hôtel du Parc', 'attention' => null, 'email' => null, 'person_id' => null]);
});

it('lists the documents of a person in the GDPR export', function () {
    $doc = app(DocumentIssuer::class)->issue(admin(), DocumentType::Contract, docIndividualDossier()->id);
    $ana = Person::query()->where('last_name', 'Roux')->firstOrFail();

    $export = PersonDataExport::for($ana);

    expect($export['documents'] ?? null)->toBe([[
        'numero' => $doc->number, 'document' => 'Contrat de formation', 'etat' => 'Émis', 'emis_le' => '2026-11-19', 'envoye_le' => null,
    ]]);
});
