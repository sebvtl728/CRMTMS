# Contrat individuel, règlement intérieur et droit à l'image (2026-11-25)

Demande : depuis le bloc Documents d'un dossier, émettre en plus de la convention (ou du contrat du particulier) un **contrat individuel de formation**, le **règlement intérieur et la charte des droits et devoirs du stagiaire**, et une **autorisation de droit à l'image**.

## Choix validés

- Les trois documents sont **par stagiaire** (une inscription), dans **tout dossier** (contractant entreprise ou particulier). Le « Contrat de formation » actuel du particulier (un par dossier, L6353-3) reste inchangé.
- Deux documents pour la vie du stagiaire : règlement intérieur + charte (un PDF), droit à l'image (un PDF).
- Les trois sont **signables en ligne** par le stagiaire (`Core\Signatures`, encadré « Le stagiaire »).
- Droit à l'image : cases « J'autorise » / « Je n'autorise pas » sur le PDF ; en ligne, signer vaut autorisation (texte explicite : pour refuser, ne pas signer).

## Conception

- `DocumentType` : `individual_contract` (« Contrat individuel de formation »), `internal_rules` (« Règlement intérieur et charte du stagiaire »), `image_rights` (« Autorisation de droit à l'image ») ; `perEnrolment()` et `signable()` vrais ; `fromDossier()` = ces trois (émis depuis la page du dossier). Migration : contrainte `documents_type_check` élargie.
- Émission : `DocumentBatch::issueForDossier($by, $type, $dossierId)` : une transaction, `DocumentIssuer::issueForEnrolment` pour chaque inscription inscrite ou confirmée du dossier qui n'a pas déjà un document vivant de ce type ; 409 « Aucun stagiaire à qui émettre ce document » si aucun. Pas de condition de fin de formation (réservée à l'attestation et au certificat).
- `POST /dossiers/{id}/documents` accepte ces types (droit `documents.manage`), message « N documents émis. ».
- Bloc Documents : boutons « Émettre : … » pour les trois types (toujours proposés au gestionnaire) ; chaque document s'affiche avec le nom du stagiaire, aperçu, envoi, signature, annulation comme les autres.
- Signature : la complétion d'un document par stagiaire ne touche jamais l'état du dossier (« Convention signée » reste réservé à la convention et au contrat du dossier).
- Textes : blocs d'origine dans `DocumentTexts::blocks()` (modifiables dans Administration › Modèles de documents) ; gabarits `training-individual_contract`, `training-internal_rules`, `training-image_rights`. Le contrat individuel ne reprend pas de prix : il renvoie aux conditions financières du dossier conclu avec le contractant.
- E-mails : modèles `document_individual_contract`, `document_internal_rules`, `document_image_rights`.
- RGPD : `recipient_snapshot.person_id` = le stagiaire : anonymisation et export existants s'appliquent.
