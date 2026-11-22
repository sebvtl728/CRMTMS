<?php

namespace App\Modules\Core\Signatures;

use App\Modules\Core\Mail\MailTemplateDefinition;
use App\Modules\Core\Mail\MailTemplateRegistry;

/** The editable e-mails of the online signature (« Modèles d'e-mails »). */
final class SignatureMailTemplates
{
    public const REQUEST = 'signature_request';

    public const CODE = 'signature_code';

    public const DONE = 'signature_done';

    public const REMINDER = 'signature_reminder';

    public static function register(): void
    {
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::REQUEST,
            label: 'Demande de signature en ligne',
            description: 'Envoyé au signataire avec son lien personnel ; {lien} est obligatoire.',
            subject: 'Document à signer : {document}',
            body: "Bonjour {destinataire},\n\n{organisme} vous invite à signer en ligne : {document}.\n\nOuvrez ce lien personnel, lisez le document puis signez avec le code qui vous sera envoyé par e-mail (lien valable jusqu'au {echeance}) :\n{lien}\n\nCordialement,\n{organisme}",
            variables: [
                'document' => ['document à signer', 'Convention de formation DOC-2026-00001'],
                'destinataire' => ['nom du signataire', 'Claire Martin'],
                'lien' => ['lien personnel de signature', 'https://tms.example/signature/…'],
                'echeance' => ['date de fin de validité du lien', '19/12/2026'],
                'organisme' => ["nom de l'organisme", 'CAP FORMATION'],
            ],
            required: ['lien'],
        ));
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::REMINDER,
            label: 'Relance de signature en ligne',
            description: 'Envoyé automatiquement quand un document attend sa signature (Administration › Automatismes) ; le nouveau lien remplace le précédent ; {lien} est obligatoire.',
            subject: 'Rappel – document à signer : {document}',
            body: "Bonjour {destinataire},\n\n{organisme} attend toujours votre signature : {document}.\n\nSignez avec ce nouveau lien personnel (valable jusqu'au {echeance}) ; il remplace le précédent :\n{lien}\n\nCordialement,\n{organisme}",
            variables: [
                'document' => ['document à signer', 'Convention de formation DOC-2026-00001'],
                'destinataire' => ['nom du signataire', 'Claire Martin'],
                'lien' => ['nouveau lien personnel de signature', 'https://tms.example/signature/…'],
                'echeance' => ['date de fin de validité du lien', '19/12/2026'],
                'organisme' => ["nom de l'organisme", 'CAP FORMATION'],
            ],
            required: ['lien'],
        ));
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::CODE,
            label: 'Code de signature',
            description: 'Le code à 6 chiffres demandé sur la page de signature, valable 10 minutes ; {code} est obligatoire.',
            subject: 'Votre code de signature : {code}',
            body: "Bonjour,\n\nVotre code pour signer « {document} » : {code}\n\nIl est valable 10 minutes. Si vous n'avez rien demandé, ignorez ce message.\n\n{organisme}",
            variables: [
                'code' => ['code à 6 chiffres', '482913'],
                'document' => ['document à signer', 'Convention de formation DOC-2026-00001'],
                'organisme' => ["nom de l'organisme", 'CAP FORMATION'],
            ],
            required: ['code'],
        ));
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::DONE,
            label: 'Confirmation de signature',
            description: 'Envoyé au signataire après sa signature, avec le PDF signé joint.',
            subject: 'Document signé : {document}',
            body: "Bonjour {destinataire},\n\nMerci, votre signature de « {document} » est enregistrée. Le document signé, avec sa page de preuve, est joint à ce message.\n\nCordialement,\n{organisme}",
            variables: [
                'document' => ['document signé', 'Convention de formation DOC-2026-00001'],
                'destinataire' => ['nom du signataire', 'Claire Martin'],
                'organisme' => ["nom de l'organisme", 'CAP FORMATION'],
            ],
        ));
    }
}
