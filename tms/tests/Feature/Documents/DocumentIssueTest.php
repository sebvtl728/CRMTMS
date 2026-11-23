<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Documents\Document;
use App\Modules\Documents\DocumentIssuer;
use App\Modules\Documents\DocumentPdf;
use App\Modules\Documents\DocumentStatus;
use App\Modules\Documents\Texts\DocumentTexts;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Dossiers\DossierWriter;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Support/documents.php';

beforeEach(function () {
    fakeImportDisk();
    plSetUp();
    docGotenberg();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Paris'));
});

it('issues a convention for a company contractor, numbered and archived', function () {
    $dossier = docCompanyDossier();
    app(DocumentTexts::class)->save(DocumentType::Convention, $dossier->company_id, 'litiges', 'Tribunal de Saint-Nazaire pour {societe}.', admin());

    $doc = app(DocumentIssuer::class)->issue(admin(), DocumentType::Convention, $dossier->id);

    expect($doc->number)->toBe('DOC-2026-00001')
        ->and($doc->type)->toBe('convention')
        ->and($doc->status)->toBe(DocumentStatus::Issued)
        ->and($doc->dossier_id)->toBe($dossier->id)
        ->and($doc->recipient_snapshot['name'])->toBe('Hôtel du Parc')
        ->and($doc->recipient_snapshot['email'])->toBe('claire.martin@hotel-parc.test')
        ->and($doc->pdf_sha256)->toBe(hash('sha256', DOC_PDF))
        ->and(Storage::disk(TenantStorage::DISK)->get($doc->pdf_path))->toBe(DOC_PDF)
        ->and(DocumentPdf::archived($doc))->toBe(DOC_PDF);

    $html = docSentHtml();
    $company = Company::query()->findOrFail($dossier->company_id);
    expect($html)->toContain('Convention de formation', 'DOC-2026-00001', 'HACCP 3 jours', 'du 07/12/2026 au 09/12/2026', "21\u{a0}h",
        '12 rue des Lilas, Nantes', 'Léa Durand', 'Tom Petit', "1\u{a0}000,00\u{a0}€", 'Tribunal de Saint-Nazaire pour '.e($company->name).'.');

    expect(app(DocumentIssuer::class)->issue(admin(), DocumentType::Convention, docCompanyDossier()->id)->number)->toBe('DOC-2026-00002');
});

it('issues a contract for a particulier with the 14-day withdrawal', function () {
    $doc = app(DocumentIssuer::class)->issue(admin(), DocumentType::Contract, docIndividualDossier()->id);

    expect($doc->type)->toBe('contract')
        ->and($doc->recipient_snapshot['email'])->toBe('ana.roux@example.test');
    expect(docSentHtml())->toContain('Contrat de formation', 'Ana Roux', '14 jours', 'L6353-5', '30 %', 'Distanciel');
});

it('refuses the wrong document for the contractor, a second valid one, or a trashed dossier', function () {
    $company = docCompanyDossier();
    $individual = docIndividualDossier();
    $issuer = app(DocumentIssuer::class);

    expect(fn () => $issuer->issue(admin(), DocumentType::Contract, $company->id))
        ->toThrow(UserFacingHttpException::class, 'Le contrat concerne un particulier : pour une entreprise, émettez une convention.');
    expect(fn () => $issuer->issue(admin(), DocumentType::Convention, $individual->id))
        ->toThrow(UserFacingHttpException::class, 'La convention concerne un contractant entreprise : pour un particulier, émettez un contrat.');

    $issuer->issue(admin(), DocumentType::Convention, $company->id);
    expect(fn () => $issuer->issue(admin(), DocumentType::Convention, $company->id))
        ->toThrow(UserFacingHttpException::class, 'Une convention est déjà émise : annulez-la avant d\'en émettre une nouvelle.');

    // A dossier with trainees cannot go to the trash: an empty one.
    $other = l2Dossier();
    app(DossierWriter::class)->trash($other);
    expect(fn () => $issuer->issue(admin(), DocumentType::Convention, $other->id))->toThrow(UserFacingHttpException::class);
    expect(Document::query()->count())->toBe(1);
});

it('never changes an issued document', function () {
    $doc = app(DocumentIssuer::class)->issue(admin(), DocumentType::Convention, docCompanyDossier()->id);

    expect(fn () => DB::table('documents')->where('id', $doc->id)->update(['pdf_path' => 'ailleurs.pdf']))->toThrow(QueryException::class);
    expect(fn () => DB::table('documents')->where('id', $doc->id)->delete())->toThrow(QueryException::class);
});

it('cancels with a reason, then issues again with a new number', function () {
    $dossier = docCompanyDossier();
    $issuer = app(DocumentIssuer::class);
    $first = $issuer->issue(admin(), DocumentType::Convention, $dossier->id);

    expect(fn () => $issuer->cancel(admin(), $first, '  '))->toThrow(ValidationException::class);
    $cancelled = $issuer->cancel(admin(), $first, 'Mauvais nombre de stagiaires');
    expect($cancelled->status)->toBe(DocumentStatus::Cancelled)
        ->and($cancelled->cancel_reason)->toBe('Mauvais nombre de stagiaires')
        ->and(DocumentPdf::archived($cancelled))->toBe(DOC_PDF);

    expect($issuer->issue(admin(), DocumentType::Convention, $dossier->id)->number)->toBe('DOC-2026-00002');
});

it('answers 503 and consumes no number when Gotenberg is down', function () {
    $dossier = docCompanyDossier();
    // A new fake in place of the one of beforeEach (the first match would win).
    Http::swap(new Factory);
    Http::fake(['gotenberg:3000/*' => Http::response('down', 500)]);

    expect(fn () => app(DocumentIssuer::class)->issue(admin(), DocumentType::Convention, $dossier->id))
        ->toThrow(fn (UserFacingHttpException $e) => expect($e->getStatusCode())->toBe(503));
    expect(Document::query()->count())->toBe(0)
        ->and(Storage::disk(TenantStorage::DISK)->allFiles(TenantStorage::path('documents')))->toBe([]);

    Http::swap(new Factory);
    docGotenberg();
    expect(app(DocumentIssuer::class)->issue(admin(), DocumentType::Convention, $dossier->id)->number)->toBe('DOC-2026-00001');
});

it('answers 410 when the archive is lost or altered', function () {
    $doc = app(DocumentIssuer::class)->issue(admin(), DocumentType::Convention, docCompanyDossier()->id);

    Storage::disk(TenantStorage::DISK)->put($doc->pdf_path, '%PDF-1.7 autre');
    expect(fn () => DocumentPdf::archived($doc))->toThrow(fn (UserFacingHttpException $e) => expect($e->getStatusCode())->toBe(410));

    Storage::disk(TenantStorage::DISK)->delete($doc->pdf_path);
    expect(fn () => DocumentPdf::archived($doc))->toThrow(fn (UserFacingHttpException $e) => expect($e->getStatusCode())->toBe(410));
});

it('prints the SIRET and the IDCC of the entreprise on its convention', function () {
    $dossier = docCompanyDossier();
    Organisation::query()->whereKey($dossier->account_organisation_id)->update(['siret' => '73282932000074', 'idcc' => '1979']);

    $doc = app(DocumentIssuer::class)->issue(admin(), DocumentType::Convention, $dossier->id);

    expect($doc->recipient_snapshot)->toMatchArray(['siret' => '73282932000074', 'idcc' => '1979'])
        ->and(docSentHtml())->toContain('SIRET 732 829 320 00074', 'IDCC 1979 (convention collective)');
});

it('prints the fundings of the dossier and what is left to the contractant on its convention', function () {
    $dossier = docCompanyDossier();
    l2Funding($dossier, ['status' => 'agreed', 'agreement_number' => 'ACC-42', 'agreed_on' => '2026-11-10', 'pays_directly' => true]);
    l2Funding($dossier, ['funder_organisation_id' => Organisation::query()->create(['name' => 'Région'])->id, 'amount' => '100,00', 'status' => 'requested']);
    l2Funding($dossier, ['funder_organisation_id' => Organisation::query()->create(['name' => 'Agefiph'])->id, 'amount' => '50,00', 'status' => 'refused']);

    app(DocumentIssuer::class)->issue(admin(), DocumentType::Convention, $dossier->id);

    expect(docSentHtml())->toContain('Financement', 'OPCO EP', 'accord n° ACC-42', '600,00', 'réglé directement à l&#039;organisme de formation',
        'Région', 'demande en cours', '100,00', 'Reste à la charge du contractant', '300,00', 'sous réserve des accords en cours')
        ->not->toContain('Agefiph');
});

it('prints no funding section on a convention without funding', function () {
    app(DocumentIssuer::class)->issue(admin(), DocumentType::Convention, docCompanyDossier()->id);

    expect(docSentHtml())->not->toContain('Reste à la charge du contractant');
});
