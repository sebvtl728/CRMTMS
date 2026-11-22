<?php

namespace App\Modules\Sessions\Automation;

use App\Modules\Core\Mail\MailTemplateDefinition;
use App\Modules\Core\Mail\MailTemplateRegistry;

/** The two day-before reminders (Administration › Modèles d'e-mails). */
final class DayBeforeMailTemplates
{
    public const TRAINEE = 'session_day_before_trainee';

    public const TRAINER = 'session_day_before_trainer';

    public static function register(): void
    {
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::TRAINEE,
            label: 'Rappel la veille (stagiaire)',
            description: 'Envoyé automatiquement la veille d\'une journée de formation à chaque stagiaire attendu (Administration › Automatismes).',
            subject: 'Rappel : votre formation demain, {date}',
            body: "Bonjour {stagiaire},\n\nNous vous rappelons votre formation de demain, {date} – {session} :\n{seances}\n\nEn cas d'empêchement, prévenez-nous au plus tôt.\n\nCordialement,\n{organisme}",
            variables: [
                'stagiaire' => ['prénom et nom du stagiaire', 'Léa Durand'],
                'session' => ['session de formation', 'Hygiène alimentaire (S-2026-0001)'],
                'date' => ['jour de la formation', 'mardi 8 décembre 2026'],
                'seances' => ['horaires, module et lieu de chaque séance', '09:00–12:30 · HACCP · Salle B, Nantes'],
                'organisme' => ["nom de l'organisme", 'CAP FORMATION'],
            ],
            required: [],
        ));
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::TRAINER,
            label: 'Rappel la veille (formateur)',
            description: 'Envoyé automatiquement la veille au formateur des séances du lendemain (Administration › Automatismes).',
            subject: 'Rappel : vos séances de demain, {date}',
            body: "Bonjour {formateur},\n\nVoici vos séances de demain, {date} :\n{seances}\n\nCordialement,\n{organisme}",
            variables: [
                'formateur' => ['prénom et nom du formateur', 'Marie Martin'],
                'date' => ['jour des séances', 'mardi 8 décembre 2026'],
                'seances' => ['horaires, session, module et lieu de chaque séance', '09:00–12:30 · Hygiène alimentaire (S-2026-0001) · HACCP · Salle B, Nantes'],
                'organisme' => ["nom de l'organisme", 'CAP FORMATION'],
            ],
            required: [],
        ));
    }
}
