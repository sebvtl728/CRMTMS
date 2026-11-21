<?php

namespace App\Modules\Questionnaires\Campaigns;

use App\Modules\Core\Mail\MailTemplateDefinition;
use App\Modules\Core\Mail\MailTemplateRegistry;
use App\Modules\Questionnaires\Surveys\SurveyCampaign;

/**
 * The e-mails of the questionnaires (Administration › Modèles d'e-mails):
 * hot and cold for the trainees, hot for the entreprise; {lien} is the
 * personal link and is required.
 */
final class SurveyMailTemplates
{
    public const HOT = 'survey_hot';

    public const COLD = 'survey_cold';

    public const CLIENT = 'survey_client';

    public static function keyFor(SurveyCampaign $campaign): string
    {
        return match (true) {
            $campaign->audience === SurveyCampaign::CLIENT => self::CLIENT,
            $campaign->kind === SurveyCampaign::COLD => self::COLD,
            default => self::HOT,
        };
    }

    public static function register(): void
    {
        $variables = [
            'prenom' => ['prénom du destinataire', 'Léa'],
            'nom' => ['nom du destinataire', 'Durand'],
            'formation' => ['intitulé de la formation', 'HACCP 3 jours'],
            'session' => ['session', 'HACCP 3 jours (S-2026-0005)'],
            'date_limite' => ['dernier jour pour répondre', '01/10/2026'],
            'organisme' => ["nom de l'organisme", 'CAP FORMATION'],
            'lien' => ['lien personnel du questionnaire', 'https://tms.exemple.fr/questionnaire/…'],
        ];
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::HOT,
            label: 'Questionnaire de satisfaction à chaud',
            description: 'Envoyé aux stagiaires au lancement du questionnaire à chaud d\'une session (à la fin de la formation).',
            subject: 'Votre avis sur la formation « {formation} » - {organisme}',
            body: "Bonjour {prenom},\n\nVous venez de suivre la formation « {formation} ». Votre avis nous aide à l'améliorer : répondez à notre questionnaire de satisfaction (2 minutes), avant le {date_limite} :\n{lien}\n\nMerci,\n{organisme}",
            variables: $variables,
            required: ['lien'],
        ));
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::COLD,
            label: 'Questionnaire de satisfaction à froid',
            description: 'Envoyé automatiquement aux stagiaires quelques mois après la fin de leur session.',
            subject: 'Et depuis la formation « {formation} » ? - {organisme}',
            body: "Bonjour {prenom},\n\nIl y a quelques mois, vous avez suivi la formation « {formation} ». Avez-vous pu mettre en pratique ce que vous avez appris ? Répondez à notre court questionnaire, avant le {date_limite} :\n{lien}\n\nMerci,\n{organisme}",
            variables: $variables,
            required: ['lien'],
        ));
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::CLIENT,
            label: 'Questionnaire de satisfaction entreprise',
            description: 'Envoyé au contact du dossier de l\'entreprise avec le questionnaire à chaud, quand le modèle entreprise est actif.',
            subject: 'Votre avis sur la formation « {formation} » - {organisme}',
            body: "Bonjour {prenom},\n\nVos collaborateurs viennent de suivre la formation « {formation} ». Votre avis nous aide à l'améliorer : répondez à notre questionnaire, avant le {date_limite} :\n{lien}\n\nMerci,\n{organisme}",
            variables: $variables,
            required: ['lien'],
        ));
    }
}
