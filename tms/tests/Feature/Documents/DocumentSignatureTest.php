<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Signatures\SignatureMail;
use App\Modules\Core\Signatures\SignatureRequest;
use App\Modules\Core\Signatures\Signatures;
use App\Modules\Crm\Gdpr\Anonymiser;
use App\Modules\Crm\People\Person;
use App\Modules\Documents\DocumentIssuer;
use App\Modules\Documents\DocumentSignatures;
use App\Modules\Documents\DocumentStatus;
use App\Modules\Documents\Permissions;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Dossiers\DossierStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/documents.php';

beforeEach(function () {
    fakeImportDisk();
    plSetUp();
    docGotenberg();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Paris'));
});

/** Signs the pending request of the last link e-mailed (code read from the e-mails). */
function docSign(SignatureRequest $request): SignatureRequest
{
    app(Signatures::class)->sendCode($request);
    preg_match('/\b(\d{6})\b/', Mail::sent(SignatureMail::class)->last()->text, $m);

    return app(Signatures::class)->sign($request->fresh(), $m[1], '203.0.113.7', 'Firefox');
}

it('asks the contact to sign the convention; once signed the document and the dossier say so', function () {
    $dossier = docCompanyDossier();
    $doc = app(DocumentIssuer::class)->issue(admin(), DocumentType::Convention, $dossier->id);

    $request = app(DocumentSignatures::class)->request(admin(), $doc, 'claire.martin@hotel-parc.test');

    expect($doc->fresh()->status)->toBe(DocumentStatus::SignaturePending)
        ->and($request->signer_name)->toBe('Claire Martin')
        ->and($request->title)->toBe('Convention de formation '.$doc->number);
    Mail::assertSent(SignatureMail::class, fn ($m) => $m->hasTo('claire.martin@hotel-parc.test') && str_contains($m->text, '/signature/'));

    docSign($request);

    expect($doc->fresh()->status)->toBe(DocumentStatus::Signed)
        ->and($dossier->fresh()->status)->toBe(DossierStatus::ConventionSigned)
        ->and($dossier->fresh()->convention_signed_on->toDateString())->toBe('2026-11-19');

    $this->actingAs(admin(twoFactor: true))->get("/dossiers/{$dossier->id}")->assertInertia(fn (Assert $p) => $p
        ->where('blocks', fn ($blocks) => ($d = collect($blocks)->firstWhere('key', 'documents.dossier')['props']['documents'][0])['status'] === 'signed'
            && $d['signature']['status'] === 'signed' && $d['can_sign'] === false));
    $this->get("/documents/{$doc->id}/signed")->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('refuses to sign a convocation or a cancelled document, and cancelling a document cancels its link', function () {
    $x = docFinishedSession();
    $issuer = app(DocumentIssuer::class);
    $convocation = $issuer->issueForEnrolment(admin(), DocumentType::Convocation, $x['lea']->id);
    expect(fn () => app(DocumentSignatures::class)->request(admin(), $convocation, 'lea.durand@example.test'))
        ->toThrow(UserFacingHttpException::class, 'Seuls la convention et le contrat se signent en ligne.');

    $doc = $issuer->issue(admin(), DocumentType::Contract, docIndividualDossier()->id);
    $request = app(DocumentSignatures::class)->request(admin(), $doc, 'ana.roux@example.test');
    $issuer->cancel(admin(), $doc->fresh(), 'Erreur de dates');
    expect($request->fresh()->status)->toBe('cancelled')
        ->and(fn () => app(DocumentSignatures::class)->request(admin(), $doc->fresh(), 'ana.roux@example.test'))->toThrow(UserFacingHttpException::class);
});

it('asks from the dossier page, with the right to manage documents', function () {
    $dossier = docCompanyDossier();
    $doc = app(DocumentIssuer::class)->issue(admin(), DocumentType::Convention, $dossier->id);

    $this->actingAs(admin(twoFactor: true))->post("/documents/{$doc->id}/signature", ['email' => 'pas-une-adresse'])->assertSessionHasErrors('email');
    $this->post("/documents/{$doc->id}/signature", ['email' => 'claire.martin@hotel-parc.test'])->assertSessionHas('status');
    expect($doc->fresh()->status)->toBe(DocumentStatus::SignaturePending);

    $reader = l2User([Permissions::VIEW => null, App\Modules\Sessions\Permissions::DOSSIERS_VIEW => null]);
    $this->actingAs($reader)->post("/documents/{$doc->id}/signature", ['email' => 'claire.martin@hotel-parc.test'])->assertForbidden();
});

it('erases the signer of an anonymised person', function () {
    $doc = app(DocumentIssuer::class)->issue(admin(), DocumentType::Contract, docIndividualDossier()->id);
    $request = docSign(app(DocumentSignatures::class)->request(admin(), $doc, 'ana.roux@example.test'));

    app(Anonymiser::class)->anonymise(Person::query()->where('last_name', 'Roux')->firstOrFail(), 'request');

    expect($request->fresh()->only(['signer_name', 'signer_email', 'signed_ip', 'signed_user_agent']))
        ->toBe(['signer_name' => null, 'signer_email' => null, 'signed_ip' => null, 'signed_user_agent' => null])
        ->and($request->fresh()->status)->toBe('signed');
});
