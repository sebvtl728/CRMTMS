<?php

namespace App\Modules\Admissions\Booklet;

use App\Modules\Core\Mail\MailTemplateDefinition;
use App\Modules\Core\Mail\MailTemplateRegistry;
use Illuminate\Mail\Mailable;

/**
 * The e-mails of the booklet follow-up (plain text), and their templates
 * (Administration › Modèles d'e-mails): the monthly link of the apprentice,
 * of the tutor, and the signing code.
 */
class BookletMail extends Mailable
{
    public const APPRENTICE = 'booklet_apprentice';

    public const TUTOR = 'booklet_tutor';

    public const CODE = 'booklet_code';

    public function __construct(public readonly string $mailSubject, public readonly string $text) {}

    public function build(): self
    {
        return $this->subject($this->mailSubject)->text('mail.plain-text', ['text' => $this->text]);
    }

    public static function registerTemplates(): void
    {
        $variables = [
            'prenom' => ['prénom du destinataire', 'Paul'],
            'nom' => ['nom du destinataire', 'Martin'],
            'mois' => ['mois du suivi', 'janvier 2027'],
            'apprenti' => ["prénom et nom de l'apprenti", 'Léa Durand'],
            'entreprise' => ["entreprise d'accueil", 'Hôtel du Parc'],
            'formation' => ['formation suivie', 'Employé polyvalent en restauration'],
            'lien' => ['lien personnel du suivi du mois', 'https://tms.exemple.fr/livret/…'],
            'date_limite' => ['dernier jour du lien', '28/02/2027'],
            'organisme' => ["nom de l'organisme", 'CAP FORMATION'],
        ];
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::APPRENTICE,
            label: 'Livret d\'apprentissage : suivi du mois (apprenti)',
            description: 'Envoyé à l\'apprenti pour remplir et signer sa page de suivi du mois ; {lien} est obligatoire.',
            subject: 'Votre livret d\'apprentissage : suivi de {mois}',
            body: "Bonjour {prenom},\n\nRemplissez votre page de suivi de {mois} dans votre livret d'apprentissage (travaux effectués chaque semaine), puis signez-la. Un code vous sera envoyé par e-mail pour signer.\n\nVotre lien personnel, valable jusqu'au {date_limite} :\n{lien}\n\nCordialement,\n{organisme}",
            variables: $variables,
            required: ['lien'],
        ));
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::TUTOR,
            label: 'Livret d\'apprentissage : suivi du mois (tuteur)',
            description: 'Envoyé au tuteur pour ses observations, la grille entreprise et sa signature du mois ; {lien} est obligatoire.',
            subject: 'Livret d\'apprentissage de {apprenti} : suivi de {mois}',
            body: "Bonjour {prenom},\n\nLa page de suivi de {mois} de {apprenti} (formation « {formation} ») attend vos observations, votre évaluation des compétences en entreprise et votre signature. Un code vous sera envoyé par e-mail pour signer.\n\nVotre lien personnel, valable jusqu'au {date_limite} :\n{lien}\n\nCordialement,\n{organisme}",
            variables: $variables,
            required: ['lien'],
        ));
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::CODE,
            label: 'Livret d\'apprentissage : code de signature',
            description: 'Le code à 6 chiffres qui valide la signature d\'une page du livret ; {code} est obligatoire.',
            subject: 'Votre code : {code}',
            body: "Bonjour,\n\nVotre code pour signer le livret d'apprentissage : {code}\nIl est valable 10 minutes.\n\nSi vous n'avez rien demandé, ignorez ce message.\n\n{organisme}",
            variables: [
                'code' => ['code à 6 chiffres', '123456'],
                'organisme' => ["nom de l'organisme", 'CAP FORMATION'],
            ],
            required: ['code'],
        ));
    }
}
