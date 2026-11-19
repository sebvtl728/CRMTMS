<?php

namespace App\Modules\Documents;

use App\Modules\Core\Mail\MailTemplateDefinition;
use App\Modules\Core\Mail\MailTemplateRegistry;
use App\Modules\Documents\Texts\DocumentType;

/**
 * The editable e-mails of the documents (« Modèles d'e-mails »): one per
 * type, prefilled in the « Envoyer » window and editable case by case.
 */
final class DocumentMailTemplates
{
    public static function key(DocumentType $type): string
    {
        return 'document_'.$type->value;
    }

    public static function register(): void
    {
        $variables = [
            'numero' => ['numéro du document', 'DOC-2026-00001'],
            'document' => ['nom du document', 'Convention de formation'],
            'formation' => ['intitulé de la formation', 'HACCP 3 jours'],
            'dates' => ['dates des sessions', 'du 07/12/2026 au 09/12/2026'],
            'societe' => ['société émettrice', 'CAP FORMATION'],
            'destinataire' => ['nom du destinataire', 'Hôtel du Parc'],
        ];
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::key(DocumentType::Convention),
            label: "Envoi d'une convention de formation",
            description: 'Proposé dans la fenêtre « Envoyer » du document, toujours modifiable avant l\'envoi. Le PDF est joint automatiquement.',
            subject: '{document} {numero} - {societe}',
            body: "Bonjour,\n\nVeuillez trouver ci-jointe la convention de formation {numero} pour la formation « {formation} » ({dates}).\n\nMerci de nous la retourner signée.\n\nCordialement,\n{societe}",
            variables: $variables,
        ));
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::key(DocumentType::Contract),
            label: "Envoi d'un contrat de formation",
            description: 'Proposé dans la fenêtre « Envoyer » du document, toujours modifiable avant l\'envoi. Le PDF est joint automatiquement.',
            subject: '{document} {numero} - {societe}',
            body: "Bonjour,\n\nVeuillez trouver ci-joint votre contrat de formation {numero} pour la formation « {formation} » ({dates}).\n\nVous disposez d'un délai de rétractation de 14 jours à compter de sa signature. Merci de nous le retourner signé.\n\nCordialement,\n{societe}",
            variables: $variables,
        ));
        $trainee = [...$variables, 'destinataire' => ['nom du stagiaire', 'Léa Durand']];
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::key(DocumentType::Convocation),
            label: "Envoi d'une convocation",
            description: 'Proposé dans la fenêtre « Envoyer » et pour l\'envoi groupé depuis la session. Le PDF est joint automatiquement.',
            subject: 'Convocation - {formation} - {societe}',
            body: "Bonjour {destinataire},\n\nVous trouverez ci-jointe votre convocation à la formation « {formation} » ({dates}) : horaires, lieu et informations pratiques.\n\nÀ bientôt,\n{societe}",
            variables: $trainee,
        ));
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::key(DocumentType::CompletionCertificate),
            label: "Envoi d'une attestation de fin de formation",
            description: 'Proposé dans la fenêtre « Envoyer » et pour l\'envoi groupé depuis la session. Le PDF est joint automatiquement.',
            subject: 'Attestation de fin de formation - {formation}',
            body: "Bonjour {destinataire},\n\nVous trouverez ci-jointe votre attestation de fin de formation pour « {formation} » ({dates}).\n\nCordialement,\n{societe}",
            variables: $trainee,
        ));
        MailTemplateRegistry::register(new MailTemplateDefinition(
            key: self::key(DocumentType::RealisationCertificate),
            label: "Envoi d'un certificat de réalisation",
            description: 'Proposé dans la fenêtre « Envoyer » et pour l\'envoi groupé depuis la session. Le PDF est joint automatiquement.',
            subject: 'Certificat de réalisation - {formation}',
            body: "Bonjour,\n\nVous trouverez ci-joint le certificat de réalisation de {destinataire} pour la formation « {formation} » ({dates}).\n\nCordialement,\n{societe}",
            variables: $trainee,
        ));
    }
}
