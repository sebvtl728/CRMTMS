<?php

namespace App\Modules\Crm\Support;

use App\Modules\Core\Mail\MailTemplateDefinition;
use App\Modules\Core\Mail\MailTemplateRegistry;

/**
 * The CRM's editable e-mails (« Modèles d'e-mails »): sending and reminder
 * of a quote (prefilled in the window, still editable case by case) and the
 * daily digest (its list of actions stays generated).
 */
final class CrmMailTemplates
{
    public const QUOTE_SEND = 'quote.send';

    public const QUOTE_REMIND = 'quote.remind';

    public const DAILY_DIGEST = 'crm.daily_digest';

    public static function register(): void
    {
        $quote = [
            'numero_devis' => ['numéro du devis', 'DEV-2026-0042'],
            'societe' => ['société émettrice', 'CAP FORMATION'],
            'destinataire' => ['destinataire du devis', 'Mme Martin'],
            'montant_ttc' => ['total TTC', '1 440,00 €'],
            'date_validite' => ['date de fin de validité', '31/12/2026'],
            'commercial' => ["responsable de l'opportunité", 'Camille Commercial'],
            'organisme' => ["nom de l'organisme", 'CAP FORMATION'],
        ];

        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::QUOTE_SEND,
            label: "Envoi d'un devis",
            description: "Proposé dans la fenêtre « Envoyer le devis », toujours modifiable avant l'envoi. Le PDF est joint automatiquement.",
            subject: 'Devis {numero_devis} — {societe}',
            body: "Bonjour,\n\nVeuillez trouver ci-joint notre devis {numero_devis}, valable jusqu'au {date_validite}.\n\nNous restons à votre disposition pour toute question.\n\nCordialement,\n{societe}",
            variables: $quote,
        ));
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::QUOTE_REMIND,
            label: "Relance d'un devis",
            description: 'Proposé dans la fenêtre « Relancer », toujours modifiable avant l\'envoi. Le PDF archivé est joint de nouveau.',
            subject: 'Relance : devis {numero_devis} — {societe}',
            body: "Bonjour,\n\nNous revenons vers vous au sujet de notre devis {numero_devis}, valable jusqu'au {date_validite}, que vous trouverez de nouveau ci-joint.\n\nN'hésitez pas à nous contacter pour toute question.\n\nCordialement,\n{societe}",
            variables: $quote,
        ));
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::DAILY_DIGEST,
            label: 'Récapitulatif quotidien des relances',
            description: "Envoyé chaque matin aux utilisateurs qui l'ont demandé. Le texte avant {actions} sert d'introduction, celui après de signature ; la liste des actions et le lien vers « À faire » sont générés.",
            subject: "À faire aujourd'hui : {nombre_actions}",
            body: "Bonjour {nom_utilisateur},\n\n{actions}\n\nCordialement,",
            variables: [
                'prenom_utilisateur' => ["prénom de l'utilisateur", 'Camille'],
                'nom_utilisateur' => ["prénom et nom de l'utilisateur", 'Camille Commercial'],
                'nombre_actions' => ['nombre d\'actions (« 3 actions »)', '3 actions'],
                'organisme' => ["nom de l'organisme", 'CAP FORMATION'],
                'actions' => ['la liste des actions y est insérée', '[liste des actions en retard et du jour]'],
            ],
            required: ['actions'],
        ));
    }
}
