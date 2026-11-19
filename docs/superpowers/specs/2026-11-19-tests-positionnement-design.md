# Tests de positionnement : bibliothèque par DAS et lien de dépôt — conception courte

Date : 2026-11-19. Demande de l'utilisateur : « dans chacun des DAS, il y a des tests de positionnement spécifiques aux formations ; rentrer les PDF de tous les DAS et, selon l'orientation, ne faire ressortir que les documents du DAS » + « générer un lien unique pour que l'apprenant remplisse le test directement ». Plan retenu par l'utilisateur : bibliothèque + envoi + **lien de dépôt** maintenant ; vrai test en ligne (questions, score) plus tard avec le moteur de questionnaires (0.11).

## Intention

Le chargé d'admission ne cherche plus le bon test : la carte « Test de positionnement » de la candidature propose les tests du DAS (ou de la formation) visés, les envoie au candidat en un clic, et le candidat renvoie sa copie par un lien personnel sans compte. La copie arrive sur la candidature, « Reçu le » se remplit.

Non-objectifs : questionnaire à l'écran, correction automatique, signature, relance automatique du candidat.
Anti-objectifs : un lien qui expose des données d'un autre candidat ou d'un autre organisme ; un dépôt qui accepte n'importe quel fichier ; un test qui part chez un prospect avec les plaquettes.

## Approches

1. **Réutiliser la bibliothèque des plaquettes avec un type** (`brochures.kind` : `brochure` | `positioning_test`) — retenue. Elle a déjà dépôt, versions immuables, rattachement aux formations **et** aux DAS, corbeille, téléchargements, et un service public (`Catalogue\Brochures`). Coût : filtrer `kind = brochure` partout où des plaquettes sont proposées (devis, envoi depuis une fiche, page formation, options).
2. Bibliothèque propre au module Admissions (tables à elle, écran à elle) : duplique dépôt/versions/rattachements ; écartée.
3. Simple champ fichier sur le référentiel DAS : pas de version, pas de rattachement à une formation ; écartée.

## Ce qui est décidé

- **Bibliothèque** (Catalogue › Plaquettes, droit `catalogue.manage`) : champ **Type** (Plaquette / Test de positionnement), filtre par type dans la liste. Un test se rattache à des DAS et/ou des formations, comme une plaquette.
- **Service public** `Brochures::positioningTests(?string $trainingId, ?string $dasId)` : versions courantes des tests (hors corbeille) rattachés à la formation, ou au DAS de la formation, ou au DAS donné ; triés par titre. Les autres lectures (`forTrainings`, `forTrainingPage`, `options`, `offered`) ne renvoient que des plaquettes.
- **Carte « Test de positionnement »** : liste des tests proposés (téléchargement) ; bouton **Envoyer le test** (droit de modifier la candidature) : choix des tests (cochés par défaut), destinataire = e-mail de la personne (modifiable), objet et message du modèle **`positioning_test`** (variables `{prenom}`, `{nom}`, `{formation}`, `{lien}`, `{date_limite}`, `{organisme}`), PDF joints (limite commune de 20 Mo de `BrochureAttachments`).
- **Envoi** (`Admissions\Positioning\PositioningSendings`, table `positioning_sendings` : candidature, versions envoyées (`positioning_sending_versions`), e-mail, objet, message, `token_hash` (sha256), `expires_at` (envoi + 14 jours, fin de journée de Paris), `email_status`, `submitted_at`, `created_by`) ; job `SendPositioningTest` (`TenantAwareJob`, après commit, `TenantMailer`, texte simple). Un nouvel envoi **révoque** le lien précédent (`revoked_at`). « Envoyé le » se remplit s'il est vide ; l'étape avance à « Test envoyé » si elle est « Identifié » (jamais en arrière).
- **Lien de dépôt** `GET|POST /test-positionnement/{token}` (64 hexadécimaux) : hors connexion, organisme par l'hôte (`ResolveTenant`), `throttle:positioning-upload`. Token inconnu, expiré, révoqué, déjà utilisé, candidature à la corbeille ou sortie (non retenue, désistée) : **la même page « lien invalide ou expiré »**, rien ne les distingue. Page : nom de l'organisme, prénom du candidat, intitulé de la formation, tests à télécharger (par le token), dépôt de **1 à 5 fichiers** PDF/JPG/PNG vérifiés par leur contenu, 20 Mo chacun, case « J'ai terminé », bouton Envoyer. Une seule remise : ensuite la page dit « Copie reçue le … ».
- **Remise** : fichiers enregistrés comme `candidature_files` de type `positioning` (`uploaded_by` null, déposé par le candidat), `submitted_at` posé, « Reçu le » rempli s'il est vide, étape avancée à « Test reçu » si elle est avant (jamais en arrière), ligne d'audit `candidature.positioning_submitted`. La carte affiche « Copie déposée par le candidat le … ».
- **RGPD** : `email`, `subject`, `message` dans `$auditPersonal` ; l'anonymisation d'une candidature efface ses envois (et révoque les liens). Pas de nom de famille ni d'e-mail sur la page publique.

## Laissé au constructeur

Mise en page de la page publique (sobre, mobile d'abord), libellés exacts des messages, ordre des colonnes de la table.
