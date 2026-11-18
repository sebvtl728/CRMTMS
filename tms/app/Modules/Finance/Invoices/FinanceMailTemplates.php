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
    }
}
