<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Documents\PdfSource;
use App\Modules\Core\Signatures\SignatureMail;
use App\Modules\Core\Signatures\Signatures;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Documents\Document;
use App\Modules\Documents\DocumentBatch;
use App\Modules\Documents\DocumentIssuer;
use App\Modules\Documents\DocumentMail;
use App\Modules\Documents\DocumentSignatures;
use App\Modules\Documents\DocumentStatus;
use App\Modules\Documents\Texts\DocumentTexts;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Dossiers\DossierStatus;
use App\Modules\Sessions\Enrolments\Enrolments;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/documents.php';

beforeEach(function () {
    fakeImportDisk();
    plSetUp();
    docGotenberg();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Paris'));
});

it('declares the three trainee forms: per trainee, signable, issued from the dossier', function () {
    foreach ([DocumentType::IndividualContract, DocumentType::InternalRules, DocumentType::ImageRights] as $type) {
        expect($type->perEnrolment())->toBeTrue()
            ->and($type->signable())->toBeTrue()
            ->and($type->editable())->toBeTrue()
            ->and(DocumentTexts::blocks($type))->not->toBeEmpty();
    }
    expect(DocumentType::fromDossier())->toBe([DocumentType::IndividualContract, DocumentType::InternalRules, DocumentType::ImageRights])
        ->and(DocumentType::IndividualContract->label())->toBe('Contrat individuel de formation')
        ->and(DocumentType::InternalRules->label())->toBe('Règlement intérieur et charte du stagiaire')
        ->and(DocumentType::ImageRights->label())->toBe('Autorisation de droit à l\'image');
});

it('issues an individual contract to each trainee of a company dossier, before the training starts', function () {
    $dossier = docCompanyDossier();

    $count = app(DocumentBatch::class)->issueForDossier(admin(), DocumentType::IndividualContract, $dossier->id);

    expect($count)->toBe(2);
    $docs = Document::query()->where('dossier_id', $dossier->id)->where('type', 'individual_contract')->orderBy('number')->get();
    expect($docs)->toHaveCount(2)
        ->and($docs->pluck('enrolment_id')->filter())->toHaveCount(2)
        ->and($docs->map(fn (Document $d) => $d->recipient_snapshot['name'])->sort()->values()->all())->toBe(['Léa Durand', 'Tom Petit']);
    $html = docSentHtml();
    expect($html)->toContain('Contrat individuel de formation', 'L6353-3', 'Le stagiaire', PdfSource::SLOT);
    expect(PdfSource::parse((string) Storage::disk(TenantStorage::DISK)->get(PdfSource::pathFor($docs[0]->pdf_path)))?->hasSlot())->toBeTrue();

    // Every trainee already has one: nothing more to issue.
    expect(fn () => app(DocumentBatch::class)->issueForDossier(admin(), DocumentType::IndividualContract, $dossier->id))
        ->toThrow(UserFacingHttpException::class, 'Chaque stagiaire du dossier a déjà ce document');
});

it('issues the internal rules and the image rights form, with the trainee\'s choice boxes', function () {
    $dossier = docIndividualDossier();
    $batch = app(DocumentBatch::class);

    expect($batch->issueForDossier(admin(), DocumentType::InternalRules, $dossier->id))->toBe(1);
    expect(docSentHtml())->toContain('Règlement intérieur et charte du stagiaire', 'Ana Roux', 'Hygiène et sécurité', 'Charte : droits du stagiaire', 'Charte : devoirs du stagiaire');

    expect($batch->issueForDossier(admin(), DocumentType::ImageRights, $dossier->id))->toBe(1);
    expect(docSentHtml())->toContain('Autorisation de droit à l&#039;image', 'Ana Roux', 'J\'autorise', 'Je n\'autorise pas', 'ne signez pas');
});

it('skips a cancelled enrolment and re-issues after a cancellation', function () {
    $x = docFinishedSession();
    app(Enrolments::class)->cancel($x['tom']);
    $batch = app(DocumentBatch::class);

    expect($batch->issueForDossier(admin(), DocumentType::ImageRights, $x['dossier']->id))->toBe(1);
    $doc = Document::query()->where('type', 'image_rights')->sole();
    expect($doc->enrolment_id)->toBe($x['lea']->id);

    app(DocumentIssuer::class)->cancel(admin(), $doc, 'Mauvaise adresse');
    expect($batch->issueForDossier(admin(), DocumentType::ImageRights, $x['dossier']->id))->toBe(1);
});

it('is signed online by the trainee without marking the dossier « Convention signée »', function () {
    $x = docFinishedSession();
    app(DocumentBatch::class)->issueForDossier(admin(), DocumentType::IndividualContract, $x['dossier']->id);
    $doc = Document::query()->where('type', 'individual_contract')->where('enrolment_id', $x['lea']->id)->sole();

    $request = app(DocumentSignatures::class)->request(admin(), $doc, 'lea.durand@example.test');
    expect($request->signer_name)->toBe('Léa Durand')
        ->and($request->title)->toBe('Contrat individuel de formation '.$doc->number);
    app(Signatures::class)->sendCode($request);
    preg_match('/\b(\d{6})\b/', Mail::sent(SignatureMail::class)->last()->text, $m);
    app(Signatures::class)->sign($request->fresh(), $m[1], sigDrawing(), '203.0.113.7', 'Firefox');

    expect($doc->fresh()->status)->toBe(DocumentStatus::Signed)
        ->and($x['dossier']->fresh()->status)->not->toBe(DossierStatus::ConventionSigned)
        ->and($x['dossier']->fresh()->convention_signed_on)->toBeNull();
});

it('prefills the e-mail to the trainee with its own template', function () {
    $x = docFinishedSession();
    app(DocumentBatch::class)->issueForDossier(admin(), DocumentType::InternalRules, $x['dossier']->id);
    $doc = Document::query()->where('type', 'internal_rules')->where('enrolment_id', $x['lea']->id)->sole();

    $defaults = DocumentMail::defaults($doc);

    expect($defaults['to'])->toBe('lea.durand@example.test')
        ->and($defaults['body'])->toContain('Léa Durand', 'règlement intérieur');
});

it('offers the three forms on the dossier page and issues them from there', function () {
    $dossier = docCompanyDossier();
    $this->actingAs(admin(twoFactor: true));

    $this->get("/dossiers/{$dossier->id}")->assertInertia(fn (Assert $p) => $p
        ->where('blocks', fn ($blocks) => collect(collect($blocks)->firstWhere('key', 'documents.dossier')['props']['issuable'])->pluck('value')->all()
            === ['convention', 'individual_contract', 'internal_rules', 'image_rights']));

    $this->post("/dossiers/{$dossier->id}/documents", ['type' => 'image_rights'])->assertSessionHas('status', '2 documents émis.');
    expect(Document::query()->where('type', 'image_rights')->count())->toBe(2);
    $this->post("/dossiers/{$dossier->id}/documents", ['type' => 'internal_rules'])->assertSessionHas('status', '2 documents émis.');
});

it('offers « Demander la signature » on each trainee form and e-mails the link to the trainee', function () {
    $x = docFinishedSession();
    $this->actingAs(admin(twoFactor: true));
    foreach (DocumentType::fromDossier() as $type) {
        app(DocumentBatch::class)->issueForDossier(admin(), $type, $x['dossier']->id);
    }

    $this->get("/dossiers/{$x['dossier']->id}")->assertInertia(fn (Assert $p) => $p
        ->where('blocks', fn ($blocks) => collect(collect($blocks)->firstWhere('key', 'documents.dossier')['props']['documents'])
            ->filter(fn ($d) => in_array($d['type'], ['individual_contract', 'internal_rules', 'image_rights'], true))
            ->every(fn ($d) => $d['can_sign'] === true)));

    foreach (Document::query()->where('enrolment_id', $x['lea']->id)->get() as $doc) {
        $this->post("/documents/{$doc->id}/signature", ['email' => 'lea.durand@example.test'])->assertSessionHas('status');
        expect($doc->fresh()->status)->toBe(DocumentStatus::SignaturePending);
    }
    Mail::assertSent(SignatureMail::class, 3);
    Mail::assertSent(SignatureMail::class, fn ($m) => $m->hasTo('lea.durand@example.test') && str_contains($m->text, '/signature/'));
});
