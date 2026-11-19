<?php

namespace App\Modules\Finance\Invoices;

use App\Modules\Core\Mail\MailTemplateDefinition;
use App\Modules\Core\Mail\MailTemplateRegistry;

/**
 * The editable e-mail of the Finance module (« Modèles d'e-mails »): the
 * sending of an invoice or a credit note, prefilled in the window and still
 * editable case by case.
 */
final class FinanceMailTemplates
{
    public const INVOICE = 'invoice';

    /** Reminders by level (0.9.1): invoice_reminder_1, _2, _3. */
    public const REMINDER = 'invoice_reminder_';

    public static function reminder(int $level): string
    {
        return self::REMINDER.$level;
    }

    public static function register(): void
    {
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::INVOICE,
            label: "Envoi d'une facture ou d'un avoir",
            description: 'Proposé dans la fenêtre « Envoyer », toujours modifiable avant l\'envoi. Le PDF archivé est joint automatiquement.',
            subject: '{piece} {numero} — {societe}',
            body: "Bonjour,\n\nVeuillez trouver ci-joint notre {type} {numero} d'un montant de {montant_ttc} TTC.{reglement}\n\nNous restons à votre disposition pour toute question.\n\nCordialement,\n{societe}",
            variables: [
                'numero' => ['numéro de la facture ou de l\'avoir', 'F-2026-00042'],
                'piece' => ['« Facture » ou « Avoir »', 'Facture'],
                'type' => ['« facture » ou « avoir » (minuscules)', 'facture'],
                'societe' => ['société émettrice', 'CAP FORMATION'],
                'destinataire' => ['nom du destinataire', 'Hôtel du Lac'],
                'montant_ttc' => ['total TTC', '1 440,00 €'],
                'echeance' => ["date d'échéance", '17/12/2026'],
                'reglement' => ['« À régler avant le … » (vide pour un avoir)', ' À régler avant le 17/12/2026.'],
                'organisme' => ["nom de l'organisme", 'CAP FORMATION'],
            ],
        ));

        $variables = [
            'numero' => ['numéro de la facture', 'F-2026-00042'],
            'societe' => ['société émettrice', 'CAP FORMATION'],
            'destinataire' => ['nom du destinataire', 'Hôtel du Lac'],
            'montant_ttc' => ['total TTC de la facture', '1 440,00 €'],
            'reste_du' => ['reste à payer', '1 440,00 €'],
            'echeance' => ["date d'échéance", '17/12/2026'],
            'jours_retard' => ['jours de retard', '7'],
            'niveau' => ['niveau de relance (1, 2 ou 3)', '1'],
            'penalites' => ['pénalités et indemnité de 40 € (professionnel seulement, vide pour un particulier)', "\n\nConformément à nos conditions…"],
            'organisme' => ["nom de l'organisme", 'CAP FORMATION'],
        ];
        $texts = [
            1 => ['Première relance', 'Rappel : facture {numero} — {societe}',
                "Bonjour,\n\nSauf erreur de notre part, notre facture {numero} d'un montant de {montant_ttc} TTC, échue le {echeance}, n'est pas encore réglée (reste dû : {reste_du}).\n\nNous vous remercions de bien vouloir procéder à son règlement. Si celui-ci vient d'être effectué, merci de ne pas tenir compte de ce message.\n\nCordialement,\n{societe}"],
            2 => ['Deuxième relance', 'Deuxième relance : facture {numero} — {societe}',
                "Bonjour,\n\nMalgré notre précédent rappel, notre facture {numero}, échue le {echeance}, reste impayée depuis {jours_retard} jours (reste dû : {reste_du}).\n\nNous vous remercions de procéder à son règlement dans les meilleurs délais.{penalites}\n\nCordialement,\n{societe}"],
            3 => ['Dernière relance', 'Dernière relance : facture {numero} — {societe}',
                "Bonjour,\n\nNotre facture {numero}, échue le {echeance}, reste impayée malgré nos relances (reste dû : {reste_du}, {jours_retard} jours de retard).\n\nSans règlement de votre part sous huit jours, nous serons contraints d'engager une procédure de recouvrement.{penalites}\n\nCordialement,\n{societe}"],
        ];
        foreach ($texts as $level => [$label, $subject, $body]) {
            MailTemplateRegistry::register(new MailTemplateDefinition(
                key: self::reminder($level),
                label: 'Relance de facture — niveau '.$level.' ('.mb_strtolower($label).')',
                description: 'Envoyée '.($level === 1 ? 'au premier' : ($level === 2 ? 'au deuxième' : 'au troisième')).' niveau de relance d\'une facture impayée (Finance › À relancer, ou automatiquement). Le PDF archivé est joint.',
                subject: $subject,
                body: $body,
                variables: $variables,
            ));
        }
    }
}
