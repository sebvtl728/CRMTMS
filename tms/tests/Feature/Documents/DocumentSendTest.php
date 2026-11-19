<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Documents\DocumentIssuer;
use App\Modules\Documents\DocumentMail;
use App\Modules\Documents\DocumentSender;
use App\Modules\Documents\DocumentSending;
use App\Modules\Documents\DocumentStatus;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Dossiers\DossierStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportException;

require_once __DIR__.'/Support/documents.php';

beforeEach(function () {
    fakeImportDisk();
    plSetUp();
    docGotenberg();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Paris'));
});

function docMessage(array $over = []): array
{
    return ['to' => 'claire.martin@hotel-parc.test', 'subject' => 'Votre convention', 'body' => 'Bonjour, voici la convention.', ...$over];
}

it('proposes the frozen recipient e-mail and a French message naming the document', function () {
    $doc = app(DocumentIssuer::class)->issue(admin(), DocumentType::Convention, docCompanyDossier()->id);

    $defaults = DocumentMail::defaults($doc);
    expect($defaults['to'])->toBe('claire.martin@hotel-parc.test')
        ->and($defaults['subject'])->toContain('Convention de formation', $doc->number)
        ->and($defaults['body'])->toContain('convention de formation', 'HACCP 3 jours');
    expect(DocumentMail::defaults(app(DocumentIssuer::class)->issue(admin(), DocumentType::Contract, docIndividualDossier()->id))['to'])
        ->toBe('ana.roux@example.test');
});

it('sends the archived PDF and moves the dossier to convention sent', function () {
    Mail::fake();
    $dossier = docCompanyDossier();
    $doc = app(DocumentIssuer::class)->issue(admin(), DocumentType::Convention, $dossier->id);

    app(DocumentSender::class)->send(admin(), $doc, docMessage(['to' => 'direction@hotel-parc.test']));

    $mail = Mail::sent(DocumentMail::class)->sole();
    expect($mail->hasTo('direction@hotel-parc.test'))->toBeTrue();
    $mail->assertHasSubject('Votre convention');
    $mail->assertSeeInText('Bonjour, voici la convention.');
    $mail->assertHasAttachedData(DOC_PDF, $doc->number.'.pdf', ['mime' => 'application/pdf']);

    $doc->refresh();
    expect($doc->status)->toBe(DocumentStatus::Sent)
        ->and($doc->email_status)->toBe('sent')
        ->and($doc->sent_at)->not->toBeNull()
        ->and(DocumentSending::query()->sole()->status)->toBe('sent')
        ->and($dossier->fresh()->status)->toBe(DossierStatus::ConventionSent)
        ->and($dossier->fresh()->convention_sent_on->toDateString())->toBe('2026-11-19');
    expect(json_encode(AuditLog::query()->where('event', 'like', 'document.email%')->get()->toArray()))->not->toContain('direction@hotel-parc.test');
});

it('refuses to send a cancelled document, an altered archive, a bad address, or while a sending is under way', function () {
    $issuer = app(DocumentIssuer::class);
    $sender = app(DocumentSender::class);
    $doc = $issuer->issue(admin(), DocumentType::Convention, docCompanyDossier()->id);

    expect(fn () => $sender->send(admin(), $doc, docMessage(['to' => 'pas-une-adresse'])))->toThrow(ValidationException::class);

    Storage::disk(TenantStorage::DISK)->put($doc->pdf_path, '%PDF-1.7 autre');
    expect(fn () => $sender->send(admin(), $doc, docMessage()))->toThrow(fn (UserFacingHttpException $e) => expect($e->getStatusCode())->toBe(410));
    Storage::disk(TenantStorage::DISK)->put($doc->pdf_path, DOC_PDF);

    Queue::fake();
    $sender->send(admin(), $doc, docMessage());
    expect(fn () => $sender->send(admin(), $doc->fresh(), docMessage()))->toThrow(UserFacingHttpException::class, 'Un envoi est déjà en cours pour ce document.');

    $cancelled = $issuer->cancel(admin(), $issuer->issue(admin(), DocumentType::Convention, docCompanyDossier()->id), 'Erreur');
    expect(fn () => $sender->send(admin(), $cancelled, docMessage()))->toThrow(UserFacingHttpException::class, 'Ce document est annulé : il ne peut pas être envoyé.');
});

it('records a failure without the server text', function () {
    docSmtp(fn () => throw new TransportException('550 5.1.1 <claire.martin@hotel-parc.test>: rejected for user cap-user'));
    $doc = app(DocumentIssuer::class)->issue(admin(), DocumentType::Convention, docCompanyDossier()->id);

    app(DocumentSender::class)->send(admin(), $doc, docMessage());

    $sending = DocumentSending::query()->sole();
    expect($sending->status)->toBe('failed')
        ->and($sending->error)->toBe("Le serveur d'envoi a refusé le message (code 550).")
        ->and($sending->error_code)->toBe('smtp_550')
        ->and($doc->fresh()->email_status)->toBe('failed')
        ->and($doc->fresh()->status)->toBe(DocumentStatus::Issued);
    expect(json_encode(AuditLog::query()->get()->toArray()))->not->toContain('cap-user');
});
