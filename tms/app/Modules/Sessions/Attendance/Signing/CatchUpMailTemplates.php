<?php

namespace App\Modules\Sessions\Attendance\Signing;

use App\Modules\Core\Mail\MailTemplateDefinition;
use App\Modules\Core\Mail\MailTemplateRegistry;

/** The two e-mails of a remote signing catch-up (Administration › Modèles d'e-mails). */
final class CatchUpMailTemplates
{
    public const LINK = 'attendance_catch_up';

    public const CODE = 'attendance_catch_up_code';

    public static function register(): void
    {
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::LINK,
            label: 'Rattrapage d\'émargement',
            description: 'Envoyé au stagiaire qui n\'a pas signé une demi-journée, avec son lien personnel ; {lien} est obligatoire.',
            subject: 'Signez votre présence du {date}',
            body: "Bonjour {stagiaire},\n\nVous n'avez pas signé la feuille d'émargement du {date} ({demi_journee}) – {session}.\n\nSi vous étiez présent(e), signez avec ce lien personnel (valable jusqu'au {echeance}) ; un code vous sera envoyé par e-mail :\n{lien}\n\nCordialement,\n{organisme}",
            variables: [
                'lien' => ['lien personnel de rattrapage', 'https://tms.example/emargement/l/…'],
                'stagiaire' => ['prénom et nom du stagiaire', 'Léa Durand'],
                'date' => ['jour de la demi-journée', 'jeudi 10 septembre 2026'],
                'demi_journee' => ['matin ou après-midi', 'matin'],
                'session' => ['session de formation', 'Hygiène alimentaire (S-2026-0001)'],
                'echeance' => ['date de fin de validité du lien', '17/09/2026'],
                'organisme' => ["nom de l'organisme", 'CAP FORMATION'],
            ],
            required: ['lien'],
        ));
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::CODE,
            label: 'Code de rattrapage d\'émargement',
            description: 'Le code à 6 chiffres qui valide la signature à distance ; {code} est obligatoire.',
            subject: 'Votre code : {code}',
            body: "Bonjour,\n\nVotre code pour signer votre présence : {code}\nIl est valable 10 minutes.\n\nSi vous n'avez rien demandé, ignorez ce message.\n\n{organisme}",
            variables: [
                'code' => ['code à 6 chiffres', '123456'],
                'organisme' => ["nom de l'organisme", 'CAP FORMATION'],
            ],
            required: ['code'],
        ));
    }
}
