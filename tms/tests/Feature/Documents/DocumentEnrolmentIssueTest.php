<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Documents\DocumentIssuer;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Enrolments\Enrolments;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Support/documents.php';

beforeEach(function () {
    fakeImportDisk();
    plSetUp();
    docGotenberg();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Paris'));
});

it('issues a convocation to a trainee with the dates, hours and place of the séances', function () {
    $x = docFinishedSession();

    $doc = app(DocumentIssuer::class)->issueForEnrolment(admin(), DocumentType::Convocation, $x['lea']->id);

    expect($doc->type)->toBe('convocation')
        ->and($doc->enrolment_id)->toBe($x['lea']->id)
        ->and($doc->dossier_id)->toBe($x['dossier']->id)
        ->and($doc->recipient_snapshot)->toMatchArray(['name' => 'Léa Durand', 'email' => 'lea.durand@example.test', 'person_id' => $x['lea']->person_id]);
    expect(docSentHtml())->toContain('Convocation', 'Léa Durand', 'HACCP 3 jours', '02/11/2026', '09:00', '12:30', '13:30', '17:00', 'Salle B, Nantes');
});

it('issues the attestation and the ministry certificate with the realised hours, once the training is over', function () {
    $x = docFinishedSession();
    $issuer = app(DocumentIssuer::class);

    $issuer->issueForEnrolment(admin(), DocumentType::CompletionCertificate, $x['lea']->id);
    expect(docSentHtml())->toContain('Attestation de fin de formation', 'Léa Durand', 'du 02/11/2026 au 04/11/2026');

    $issuer->issueForEnrolment(admin(), DocumentType::RealisationCertificate, $x['lea']->id);
    expect(docSentHtml())->toContain('Certificat de réalisation', 'Je soussigné(e)', 'Hôtel du Parc', 'Action de formation', 'conserver l\'ensemble des pièces justificatives');

    expect(fn () => $issuer->issueForEnrolment(admin(), DocumentType::RealisationCertificate, $x['lea']->id))
        ->toThrow(UserFacingHttpException::class, 'Un certificat de réalisation est déjà émis pour ce stagiaire : annulez-le avant d\'en émettre un nouveau.');
});

it('refuses the attestation before the end of the training, unless the trainee left', function () {
    $dossier = l2Dossier();
    $session = l2Session(null, ['starts_on' => '2026-11-16', 'ends_on' => '2026-12-18', 'planned_hours' => '35']);
    $lea = l2Enrol($dossier, $session, makePerson('Durand', ['first_name' => 'Léa']));
    $tom = l2Enrol($dossier, $session, makePerson('Petit', ['first_name' => 'Tom']));
    $issuer = app(DocumentIssuer::class);

    expect(fn () => $issuer->issueForEnrolment(admin(), DocumentType::CompletionCertificate, $lea->id))
        ->toThrow(UserFacingHttpException::class, 'L\'attestation et le certificat s\'émettent à la fin de la formation ou à la sortie du stagiaire.');

    app(Enrolments::class)->abandon($tom, CarbonImmutable::parse('2026-11-18'), crmItem(ReferenceList::AbandonReason, 'Maladie')->id);
    expect($issuer->issueForEnrolment(admin(), DocumentType::CompletionCertificate, $tom->id)->type)->toBe('completion_certificate');
    expect(docSentHtml())->toContain('au 18/11/2026');
});

it('never issues for a cancelled enrolment, a cancelled session, or a per-dossier type', function () {
    $x = docFinishedSession();
    $issuer = app(DocumentIssuer::class);
    app(Enrolments::class)->cancel($x['tom']);

    expect(fn () => $issuer->issueForEnrolment(admin(), DocumentType::Convocation, $x['tom']->id))
        ->toThrow(UserFacingHttpException::class, 'Ce stagiaire n\'est pas inscrit : aucun document ne peut être émis.');
    expect(fn () => $issuer->issueForEnrolment(admin(), DocumentType::Convention, $x['lea']->id))->toThrow(InvalidArgumentException::class);
});

it('issues the attestation and the certificate on the last day of the training', function () {
    $dossier = l2Dossier();
    $session = l2Session(null, ['starts_on' => '2026-11-16', 'ends_on' => '2026-11-19', 'planned_hours' => '28']);
    $lea = l2Enrol($dossier, $session, makePerson('Durand', ['first_name' => 'Léa']));
    $issuer = app(DocumentIssuer::class);

    expect($issuer->issueForEnrolment(admin(), DocumentType::CompletionCertificate, $lea->id)->type)->toBe('completion_certificate')
        ->and($issuer->issueForEnrolment(admin(), DocumentType::RealisationCertificate, $lea->id)->type)->toBe('realisation_certificate');
});
