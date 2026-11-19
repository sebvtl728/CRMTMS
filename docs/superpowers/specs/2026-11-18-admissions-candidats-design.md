# Admissions : candidats, apprenants, alternants — conception

Statut : brouillon à valider. Version de publication : à fixer (proposition : prochaine mineure après 0.9.0).

## 1. Intention

CAP FORMATION recrute des candidats avant de les former : un candidat est identifié, reçoit un test de positionnement (fait hors du TMS), le renvoie, passe un entretien, puis est admis ou non. Admis, il devient **apprenant**, ou **apprenant alternant** s'il signe un contrat d'apprentissage (CAP FORMATION est CFA) ou de professionnalisation.

Aujourd'hui, rien de ce parcours n'existe dans le TMS : on passe directement du CRM (personne) au dossier de formation. L'équipe veut :

1. suivre chaque candidature et son avancement ;
2. saisir les informations d'entrée en formation, plus poussées que celles d'un interlocuteur ;
3. voir les candidats et les alternants depuis le CRM ;
4. transformer un interlocuteur existant en candidat, sans ressaisie ;
5. ajouter ses propres champs (champs personnalisés) et un code RNCP.

Et, dans la foulée, renommer le menu CRM « Personnes » en « Interlocuteurs ».

## 2. Objectifs, non-objectifs, anti-objectifs

**Objectifs**
- Une fiche **candidature** rattachée à une personne du CRM, avec étapes, positionnement, entretien, décision, bloc alternance, champs personnalisés.
- Plusieurs candidatures possibles par personne (nouvelle année, autre formation), historique conservé.
- Passage « Admis » → création du dossier de formation et inscription, en réutilisant Sessions.
- Vue liste « Candidats » et « Alternants », accessible depuis le menu CRM.
- Code RNCP sur la formation du catalogue, repris (modifiable) sur la candidature.
- Champs personnalisés par organisme, **sur la candidature seulement** pour l'instant, conçus pour s'étendre à d'autres fiches plus tard.

**Non-objectifs (plus tard)**
- CERFA, NPEC, dépôt OPCO, API OPCO (option B ; passerait par un adaptateur `Connectors`).
- Test de positionnement réalisé dans le TMS.
- Espace candidat / formulaire de candidature en ligne.
- Champs personnalisés sur entreprises, interlocuteurs, dossiers, sessions.
- Signature électronique du contrat.

**Anti-objectifs (échec même si ça « marche »)**
- Que l'équipe commerciale voie des données sensibles (RQTH, besoins d'adaptation, compte rendu d'entretien) sans droit dédié.
- Ressaisir l'identité d'une personne déjà dans le CRM.
- Un dossier de formation créé « à moitié vide » pour un candidat non admis.
- Une donnée personnelle (champ personnalisé compris) en clair dans `audit_logs` ou les logs.

## 3. Contraintes (projet)

Règles de `CLAUDE.md` : RLS + `tenant_id` + `BelongsToTenant` sur chaque table ; listes métier = référentiels (`reference_items`, id stocké, `ReferenceUsage::register`) ; états = enums ; données personnelles dans `$auditPersonal` ; fichiers par `TenantStorage` ; jobs `TenantAwareJob` ; interface en français ; TDD ; frontières de modules vérifiées par `ModuleBoundariesArchTest`.

## 4. Approches envisagées

- **A. Candidature séparée, rattachée à la personne du CRM, dans un nouveau module `Admissions`** — **retenue** par l'utilisateur. Plusieurs candidatures par personne, identité jamais ressaisie, données sensibles isolées, Sessions réutilisé à l'admission. Coût : un module de plus.
- B. Champs « candidat » sur la personne. Rapide, mais une seule candidature par personne, fiche Interlocuteur encombrée, données sensibles mêlées au CRM.
- C. Dossier de formation à l'état « candidature ». Réutilise le dossier, mais impose contractant/financement/facturation à un non-admis et oblige la facturation à l'ignorer.

## 5. Conception retenue

### 5.1 Module et frontières

`tms/app/Modules/Admissions/` (Candidatures, Alternance, CustomFields, Gdpr, History, Settings).

- Admissions lit et crée les personnes **uniquement** par `Crm\Public\*` (`Fiches`, `Trainees::search/create/updateProfile`), le catalogue par `Trainings`, `TrainingData`, `TrainingUsage`, et Sessions par un **nouveau** service public `Sessions\Public\Admission` (voir 5.6).
- Crm, Catalogue, Sessions, Finance, Core, Connectors n'importent jamais Admissions. Admissions se déclare dans leurs registres (`FicheBlocks`, `FicheLinks`, `FicheHistory`, `TrainingUsage`, `ReferenceUsage`, `MorphAliases`, `Permissions::registerModule`) depuis `AdmissionsServiceProvider::boot`. `ModuleBoundariesArchTest` étendu (tables d'Admissions interdites ailleurs ; tables CRM/Catalogue/Sessions interdites dans Admissions).

### 5.2 Candidature (`candidatures`)

| Champ | Détail |
|---|---|
| `number` | `C-<année Paris>-<NNNN>` (`CandidatureNumbering`, mécanique de `DossierNumbering`) |
| `person_id` | personne du CRM (obligatoire) |
| `status` | enum `CandidatureStatus` (voir 5.3) |
| `training_id` | formation visée (catalogue), facultative à l'identification, obligatoire pour « Admis » |
| `rncp_code` | prérempli depuis la formation (5.5), modifiable |
| `target_session_id` | session visée, facultative (lue par `Sessions\Public\Admission::sessionOptions`) |
| `das_id` | DAS de la formation (périmètre « das »), modifiable si pas de formation |
| `responsible_user_id`, `created_by` | périmètre « own » |
| `origin_id` | référentiel existant `origin` (prescripteur / canal) |
| `prescriber_organisation_id` | prescripteur facultatif (France Travail, Mission locale…) : organisation du CRM |
| `france_travail_id` | identifiant France Travail, facultatif |
| `situation_id`, `education_level_id` | référentiels existants (situation avant formation, niveau) ; préremplis depuis la personne |
| `last_diploma` | texte (dernier diplôme / dernière classe) |
| `legal_representative` | jsonb {nom, prénom, lien, téléphone, e-mail} — demandé si la personne est mineure à la date de début visée |
| `rqth` | booléen — **sensible** |
| `adaptation_needs` | texte — **sensible** |
| `positioning_sent_on`, `positioning_received_on` | dates |
| `positioning_result` | texte court (score ou niveau) |
| `interview_at`, `interviewer_user_id` | entretien |
| `interview_report` | texte — **sensible** |
| `decision_reason_id` | référentiel **nouveau** `candidature_refusal_reason` (obligatoire pour « Non retenu ») |
| `dossier_id` | dossier de formation créé à l'admission (5.6) |
| `custom_values` | jsonb, valeurs des champs personnalisés (5.4) |
| suppression logique | corbeille comme les fiches |

Fichiers (`candidature_files` : candidature, kind `positioning|contract|custom:<field_id>`, nom, taille, chemin `TenantStorage` zone `admissions`). Un PDF ou image, 20 Mo max (même limite que les pièces jointes du CRM).

Le **NIR** (numéro de sécurité sociale) n'est **pas** saisi.

`$auditPersonal` : `legal_representative`, `rqth`, `adaptation_needs`, `interview_report`, `positioning_result`, `france_travail_id`, `last_diploma`, `custom_values`.

### 5.3 Étapes

Enum `CandidatureStatus` : `identified` (Identifié) → `positioning_sent` (Test envoyé) → `positioning_received` (Test reçu) → `interview` (Entretien) → `admitted` (Admis) ; sorties `rejected` (Non retenu, motif obligatoire) et `withdrawn` (Désisté).

- `CandidatureTransitions` (verrou `lockForUpdate`) : on avance d'une étape ou on saute en avant (un candidat peut être admis sans test, ex. reprise) ; retour en arrière permis tant qu'aucun dossier n'est créé ; « Non retenu » / « Désisté » depuis toute étape non admise ; « Admis » exige formation + champs personnalisés « obligatoires pour l'admission » remplis.
- Saisir `positioning_sent_on` / `positioning_received_on` / `interview_at` propose d'avancer l'étape (pas automatique).
- **Apprenant** et **Alternant** ne sont pas des étapes : se calculent à la lecture. Apprenant = admis avec `dossier_id`. Alternant = apprenant avec contrat d'alternance.

### 5.4 Champs personnalisés

- Table `custom_field_definitions` : `entity` (valeur `candidature` seulement aujourd'hui ; colonne présente pour l'extension), `label`, `type` (enum : `text`, `textarea`, `number`, `date`, `boolean`, `choice`, `file`), `options` (jsonb, choix pour `choice`), `required_for_admission` (bool), `position`, `hidden_at`.
- Valeurs dans `candidatures.custom_values` (clé = id de la définition) ; fichiers dans `candidature_files` (`custom:<id>`). Validation par `CustomFieldValues::validate(entity, values)` (types, choix connus).
- Une définition qui a au moins une valeur ne se supprime pas : elle se **masque** (les valeurs restent lisibles dans l'historique et l'export RGPD).
- Écran **Administration › Champs personnalisés** (droit `settings.manage`) : créer, renommer, réordonner, masquer ; changement de type refusé si des valeurs existent.
- Les valeurs sont traitées comme données personnelles (audit masqué, anonymisation, export RGPD).

### 5.5 Code RNCP

- Colonne `rncp_code` (texte, facultatif, format `RNCP` + chiffres, normalisé en majuscules) sur la formation du catalogue ; saisie sur la fiche formation, exposée par `TrainingData`.
- La candidature reprend le code de la formation choisie ; l'utilisateur peut le modifier (certification hors catalogue). Changer de formation repropose le code de la nouvelle.

### 5.6 Admission → apprenant

Nouveau service public `Sessions\Public\Admission` (Sessions n'importe pas Admissions) :

- `sessionOptions(trainingId)` : sessions planifiées/confirmées non terminées de la formation.
- `createDossier(array $data): string` : crée le dossier par `DossierWriter::create` et inscrit la personne par `Enrolments::enrolOne`, tout dans une transaction ; renvoie l'id du dossier ; erreurs de validation renvoyées telles quelles.

Bouton « Créer le dossier » sur une candidature admise (droits `admissions.candidatures.update` **et** `sessions.dossiers.update`) : boîte de dialogue préremplie —
- **sans contrat** : contractant = la personne (dossier de particulier) ou une entreprise choisie ;
- **avec contrat** : contractant = employeur du contrat ; un financement OPCO est proposé si l'OPCO est renseigné.

Formation et session visées préremplies. Une candidature ne crée qu'**un** dossier (verrou + unique `dossier_id`). Le lien inverse (dossier → candidature) n'est pas stocké par Sessions ; Admissions déclare un bloc dans `Sessions\Public\DossierBlocks` (« Candidature C-… ») pour l'afficher.

### 5.7 Alternance (`alternance_contracts`)

Une ligne par candidature (unique) :
- `contract_type` : enum `apprenticeship` (Contrat d'apprentissage) / `professionalisation` (Contrat de professionnalisation) — fixé par la loi, donc enum ;
- `employer_organisation_id` (entreprise d'accueil, CRM), `tutor_person_id` (maître d'apprentissage ou tuteur, CRM, interlocuteur de l'employeur) ;
- `starts_on`, `ends_on` (fin ≥ début) ;
- `opco_organisation_id` (OPCO, organisation du CRM) ;
- contrat signé : fichier (`candidature_files`, kind `contract`).

Saisissable dès l'étape « Entretien ». Supprimable tant que le dossier n'est pas créé.

### 5.8 Écrans

- **Menu CRM** : « Personnes » devient **« Interlocuteurs »** (même page `/crm/people`, particuliers inclus avec leur badge ; titre de page et fil d'Ariane renommés). Nouvelle entrée **« Candidats »** (droit `admissions.candidatures.view`).
- **Liste** `/admissions/candidatures` : onglets **Candidats** (non admis + admis sans dossier), **Apprenants**, **Alternants**, **Tous** ; filtres étape, formation, DAS, responsable, type de contrat ; recherche nom / numéro ; export CSV `;`.
- **Fiche candidature** : en-tête (nom, numéro, étape, boutons d'étape) ; blocs Identité (lecture, lien « Modifier dans le CRM »), Orientation, Positionnement, Entretien, Alternance, Informations complémentaires (champs personnalisés), Décision, Historique ; blocs sensibles masqués sans droit.
- **Fiche personne (CRM)** : bloc « Candidatures » (via `FicheBlocks`) avec le bouton **« Ouvrir une candidature »** = transformation interlocuteur → candidat.
- **Nouvelle candidature** depuis la liste : recherche d'une personne existante (`Trainees::search`, doublons) ou création (`Trainees::create`).

### 5.9 Droits

`Permissions::registerModule('admissions', …)` :
- `admissions.candidatures.view` / `.update` / `.delete` — à périmètre (`own` : responsable ou créateur ; `das` : `das_id`) ;
- `admissions.sensitive.view` — voir et saisir RQTH, besoins d'adaptation, compte rendu d'entretien ;
- champs personnalisés : `settings.manage` existant.

Droits par défaut par rôle système : à proposer par le constructeur sur le modèle de Sessions (admin : tout ; direction : consulter ; responsable DAS : périmètre DAS ; commercial / chargé de relations / assistant : consulter + modifier ; formateur : rien), **sensible** pour admin et direction seulement.

### 5.10 RGPD, historique, accueil

- Historique lu dans `audit_logs` (pattern `Sessions\History`), affiché sur la candidature et sur la fiche personne (`FicheHistory`).
- `FicheLinks` : données liées (annulation d'import), fusion (repointage `person_id`, `tutor_person_id`, organisations), anonymisation (efface champs sensibles, `custom_values`, fichiers), export RGPD.
- Conservation d'une candidature non retenue / désistée : 2 ans puis anonymisation (§ 8).

### 5.11 Documentation

`tms/docs/admissions.md` (guide), `admin.md` (champs personnalisés, droits), `crm.md` (renommage du menu, bloc Candidatures), `data-dictionary.md`, section `CLAUDE.md` « Module Admissions ».

## 6. Laissé au constructeur

Découpage des contrôleurs et composants React, noms de routes, pagination, ordre des colonnes de liste, messages d'erreur exacts (en français), forme exacte des tests, index SQL (colonnes simples, pas d'index d'expression sous RLS).

## 7. Précisions après relecture (décisions de conception, à vérifier)

1. **Visibilité** : la candidature a son propre périmètre (`admissions.candidatures.view`), indépendant du CRM. Le bloc Identité affiche l'identité minimale (`Fiches::person` : nom, prénom, naissance, coordonnées) à qui voit la candidature ; le lien « Ouvrir dans le CRM » n'apparaît que si `Fiches::openable` l'autorise.
2. **Création du dossier** : `Sessions\Public\Admission::createDossier` passe par `DossierWriter::create` puis la méthode publique `Enrolments::enrol` (pas `enrolOne`, privée). Contractant particulier = la personne elle-même (règle « un dossier de particulier n'inscrit que ce particulier »). Ligne : formation visée, prix et heures par défaut de `TrainingData`, modifiables dans la boîte de dialogue. Session facultative : sans session, le dossier est créé avec sa ligne et **sans inscription** (à faire depuis le dossier).
3. **Financement d'un alternant** : la boîte de dialogue propose un financement avec l'OPCO pour financeur, type choisi dans `funding_type`, montant saisi par l'utilisateur, `pays_directly` coché ; la facturation suit la règle normale de Finance. Pas de calcul NPEC (option B).
4. **Référentiels** : l'origine utilise `contact_origin` (existant). Situation professionnelle et niveau restent **sur la personne** (une seule source) : la candidature les affiche et les modifie par `Trainees::updateProfile`. Seul nouveau référentiel : `candidature_refusal_reason`.
5. **Dossier mis à la corbeille** : `dossier_id` est conservé ; si le dossier est à la corbeille, le bouton « Créer le dossier » réapparaît et le nouveau dossier remplace l'ancien. Retour d'étape après « Admis » permis seulement dans ce cas.
6. **Mineur** : avertissement, pas blocage. Date de référence : début du contrat, sinon début de la session visée, sinon aujourd'hui.
7. **Personne anonymisée** : la candidature est gardée (numéro, étape, formation, dates) pour les statistiques, ses champs personnels, sensibles, champs personnalisés et fichiers sont effacés.
8. **Onglet Alternants** = contrat d'alternance **et** dossier créé ; un candidat avec contrat sans dossier reste dans « Candidats » avec un badge « Contrat ».
9. **Champs personnalisés obligatoires** : un champ fichier compte comme rempli s'il a un fichier ; un champ masqué n'est plus exigé.
10. **Droits par défaut** : ceux proposés au § 5.9.

## 8. Conservation (décision de l'utilisateur)

Une candidature **non retenue** ou **désistée** est anonymisée **2 ans** après sa dernière modification (étape, saisie ou action) : commande planifiée `tms:admissions:purge` (quotidienne, `ForEachTenant`, une erreur sur un organisme n'arrête pas les autres). L'anonymisation suit la règle du § 7.7 (numéro, étape, formation et dates gardés ; champs personnels, sensibles, personnalisés et fichiers effacés). La personne du CRM n'est pas touchée (elle a sa propre règle). Délai mentionné dans `tms/docs/admissions.md`.
