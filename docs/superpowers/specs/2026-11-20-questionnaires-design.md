# Questionnaires de satisfaction (0.11) — conception

Date : 2026-11-20. Échéance : démonstration le 30/11/2026, test par une équipe réelle début décembre.
Principe directeur : **simple, solide, démontrable** ; aucune sur-ingénierie.

## 1. Intention

Recueillir l'appréciation des stagiaires (et, en option, de l'entreprise donneuse d'ordre) pour
**Qualiopi indicateur 30** (« recueil des appréciations ») et l'indicateur 2 (taux de satisfaction
publiés) :

- **à chaud** : à la fin de la session (e-mail + QR code affiché en salle le dernier jour) ;
- **à froid** : N mois après la fin (N réglable par organisme), envoi automatique ;
- réponses **sans compte**, par un lien personnel ;
- modèles paramétrables par organisme (note 1-5, choix, texte libre) ;
- résultats par session, agrégés, et mesures automatiques sur la page Qualiopi ;
- la satisfaction apparaît sur la fiche apprenant (« Formations suivies », aujourd'hui « Satisfaction : - »).

### Objectifs / non-objectifs / anti-objectifs

- Objectifs : lancer, répondre, lire les résultats, mesures Qualiopi, envoi à froid planifié.
- Non-objectifs (plus tard) : questionnaire intervenant ou financeur, évaluation des enseignements
  par les apprentis (ind. 33), pondération, logique conditionnelle, relances automatiques, SMS,
  objectifs qualité chiffrés, export PDF des résultats.
- Anti-objectifs : un stagiaire qui répond deux fois (QR + e-mail) ; une réponse rattachée au
  mauvais organisme ; une adresse e-mail ou un texte libre en clair dans le journal d'audit ou les
  logs ; un lien qui laisse deviner s'il a existé (même 404 pour tout lien invalide).

## 2. Approches considérées

| Approche | Pour | Contre | Décision |
|---|---|---|---|
| **A. Nouveau module `Questionnaires`** (lit Sessions/CRM par services publics, s'affiche par registres) | frontières respectées, rien dans les zones des autres sessions | une petite API publique à ajouter dans `Sessions\Public` | **retenue** |
| B. Réutiliser le moteur des tests en ligne d'Admissions | modèle sections → questions existant | Admissions ne s'importe pas (`ModuleBoundariesArchTest`), notation « juste/faux » inadaptée | écartée |
| C. Dans le module Qualité | proche de Qualiopi | Qualité n'importe que Core | écartée |

QR code en salle — choix de l'utilisateur : **A. QR de session puis « Qui êtes-vous ? »** :
le stagiaire choisit son nom ; sa réponse compte pour son invitation, son lien e-mail se ferme.

## 3. Modèle de données (module `Questionnaires`, toutes tables `tenant_id` + RLS + `BelongsToTenant`)

- **`survey_templates`** : `name`, `kind` (`hot` | `cold`), `audience` (`trainee` | `client`),
  `questions` jsonb, `active` bool, `is_default` bool (au plus un par `kind`+`audience` : index
  unique partiel), horodatages, `created_by`/`updated_by`, suppression logique.
  - `questions` : liste de `{id (uuid court), type: rating|choice|text, label, required, options?}`,
    validée par `SurveyContent` (1 à 30 questions, libellé ≤ 300, 2 à 10 options ≤ 120 pour `choice`,
    choix unique). Une question `rating` = note 1 à 5 (« 1 = pas du tout satisfait, 5 = très satisfait »).
  - Un modèle déjà utilisé reste modifiable : la campagne **fige** sa copie (ci-dessous).
- **`survey_campaigns`** : `training_session_id`, `template_id`, `kind`, `audience`, `title`,
  `questions` jsonb (**copie figée**), `qr_token_hash` (sha256, null hors chaud stagiaire),
  `opened_at`, `closes_on` (date de Paris, fin de journée), `launched_by` (null = automatique).
  Unique (`training_session_id`, `kind`, `audience`) : une campagne par session, type et public.
- **`survey_invitations`** : `campaign_id`, `enrolment_id` (stagiaire) ou `person_id` (contact
  client), `person_id` toujours rempli, `email` (null si aucun e-mail), `token_hash` (sha256 unique),
  `sent_at`, `email_status` (`pending|sent|failed|none`), `email_error_code`, `answered_at`,
  `answered_via` (`email` | `qr`). Unique (`campaign_id`, `person_id`).
- **`survey_responses`** : `invitation_id` unique, `answers` jsonb (`{question_id: valeur}`),
  `score` smallint null (moyenne des notes ×100, arrondi demi supérieur ; null sans note),
  `submitted_at`. Pas de modèle `Auditable` (écriture publique) ; jamais de texte dans les logs.

Les réponses sont **nominatives en base** (taux de réponse exact, satisfaction sur la fiche) mais
**affichées sans nom** dans les résultats (texte libre listé sans auteur).

## 4. Déroulement

### 4.1 Lancement à chaud

- Bouton « Lancer le questionnaire à chaud » dans le bloc **Questionnaires** de la page session
  (étape « Stagiaires », registre `SessionBlocks`), droit : modifier la session
  (`Sessions\Public\Surveys::allows($user, $sessionId)`), session non annulée.
- Crée la campagne depuis le modèle par défaut actif (`hot`, `trainee`), une invitation par
  stagiaire suivi (inscrit, confirmé ; parti exclu), et met en file un e-mail par stagiaire ayant une
  adresse (modèle d'e-mail `survey_hot`, `{lien}` obligatoire). Les invitations sans adresse
  restent joignables par le QR.
- Si un modèle `client` actif par défaut existe, la même action crée la campagne **entreprise** :
  une invitation par **contact de dossier** (`contact_person_id`) des dossiers d'entreprise de la
  session, dédoublonnée (modèle d'e-mail `survey_client`).
- **Lancement automatique** (mon choix) : la commande quotidienne lance la campagne à chaud le
  lendemain du dernier jour si personne ne l'a fait (rattrapage 30 jours, jamais au-delà).
- Fermeture : `closes_on` = lancement + 30 jours. Après : page « questionnaire clos » (même gabarit
  que la 404, pas d'indice).
- Stagiaire ajouté après le lancement : bouton « Inviter les nouveaux stagiaires » (même action,
  idempotente : crée les invitations manquantes seulement).

### 4.2 QR code en salle

- Le bloc affiche « Afficher le QR code » : page plein écran imprimable (`/sessions/{id}/questionnaire-qr`)
  avec le QR (SVG, `bacon/bacon-qr-code`) et l'adresse courte.
- Lien `questionnaire/s/{token}` (64 caractères hexadécimaux, sha256 seul gardé + jeton chiffré
  `qr_token_encrypted` pour réafficher le QR), **stable** pendant la campagne pour qu'une affiche
  imprimée reste valable.
- Page publique « Qui êtes-vous ? » : liste des stagiaires **n'ayant pas encore répondu**,
  « Prénom N. » seulement, triés ; le choix ouvre le formulaire de son invitation (`answered_via = qr`).
  Une fois répondu, il disparaît de la liste et son lien e-mail affiche « Merci, déjà répondu ».

### 4.3 Envoi à froid

- Réglage organisme : `tenants.settings.questionnaires.cold_months` (défaut **3**, 0 = aucun envoi,
  max 24), écran Administration › Questionnaires (avec la liste des modèles).
- Commande `tms:questionnaires:daily` (7 h 00 Paris, `ForEachTenant`) :
  1. lancements à chaud automatiques (4.1) ;
  2. campagnes à froid pour les sessions non annulées terminées il y a N mois (fenêtre de
     rattrapage 30 jours, unique (`session`, `cold`, `trainee`) = jamais deux fois), stagiaires
     suivis non anonymisés, modèle par défaut actif `cold`/`trainee` ; e-mail `survey_cold`.
  Sans modèle par défaut actif : rien (pas d'erreur).

### 4.4 Répondre (pages publiques)

- Routes web hors `auth` : `questionnaire/{token}` (GET formulaire, POST réponses) et
  `questionnaire/s/{token}` (GET liste, POST choix → redirige vers le formulaire signé de
  l'invitation). `throttle:survey-public` (30/min par IP). Même page 404 pour tout lien invalide
  (jeton inconnu, campagne d'un autre organisme, session supprimée).
- L'organisme vient de l'hôte de la requête, comme `test-positionnement/{token}` ; le jeton (64
  caractères hexadécimaux) est cherché par son sha256 sous RLS (`findByToken`, `hash_equals`).
- Le choix sur la page QR redirige vers `questionnaire/q/{campaignToken}/{invitationId}` avec une
  **URL signée** Laravel de courte durée (2 h), jamais le jeton personnel.
- Validation : questions obligatoires, note entière 1-5, option existante, texte ≤ 2000.
  Une seule réponse par invitation (verrou de ligne + `answered_at`) ; deuxième envoi : 409 « déjà
  répondu ». Page « Merci ».
- Mobile d'abord (QR scanné au téléphone), interface en français, sans dépendance externe.

## 5. Résultats

- **Bloc session** : par campagne : envoyés / répondus (taux de réponse), satisfaction moyenne
  (/5) et **taux de satisfaction** (part des répondants dont la moyenne des notes ≥ 4), par question :
  moyenne et répartition des notes, décompte des choix, textes libres (sans nom). Liste des
  invitations : nom, e-mail envoyé/échec/sans adresse, « Répondu » oui/non (pas le contenu).
- **Carte de synthèse** (`SessionCards`, clé `questionnaires`) : « Satisfaction » : `done` si la
  campagne à chaud a ≥ 1 réponse (valeur « 4,3/5 · 8/10 réponses »), `todo` après le dernier jour
  sans campagne, `na` avant.
- **Page agrégée** « Questionnaires » (menu Qualité, droit `questionnaires.view`) : filtres période
  (fin de session), type (chaud/froid), public, formation ; tableau par session (réponses, taux,
  moyenne) + totaux ; export CSV `;` (droit `questionnaires.view`, journalisé).
- **Fiche apprenant** : « Satisfaction : 4,5/5 » (score de sa réponse à chaud) via un registre
  `Sessions\Public\EnrolmentSatisfaction` (Sessions ne connaît pas Questionnaires) ; « - » sinon.

## 6. Mesures Qualiopi (`Qualite\Public\Measures`, déclarées dans le `boot` du module)

Sur les 12 derniers mois (sessions terminées, jours de Paris), indicateurs **30** et **2** :
- taux de réponse à chaud stagiaires ; taux de satisfaction à chaud (répondants ≥ 4/5) et moyenne ;
- taux de réponse et de satisfaction à froid ;
- (si des campagnes entreprise existent) taux de réponse et satisfaction entreprise.
Ton `warn` si taux de réponse < 50 % ou aucune campagne, sinon `ok`.

## 7. Droits

- `questionnaires.view` (résultats agrégés, export) et `questionnaires.manage` (modèles, réglage
  du délai), **sans périmètre** ; défauts : administrateur, direction (+ `view` responsable DAS et
  assistant).
- Lancer / inviter / QR / lire le bloc d'une session : droits de **la session** (consulter pour lire,
  modifier pour lancer), par `Sessions\Public\Surveys::allows`.

## 8. Modèles par défaut

Créés pour chaque organisme (migration de données pour les organismes existants, et à la création
d'un organisme) :
- **À chaud — stagiaires** (défaut, actif) : satisfaction globale (note), atteinte des objectifs
  (note), qualité de l'animation (note), supports et moyens (note), organisation et accueil (note),
  recommanderiez-vous (choix Oui/Non), commentaires (texte, facultatif).
- **À froid — stagiaires** (défaut, actif) : mise en pratique des acquis (note), impact sur le poste
  (note), satisfaction avec le recul (note), besoins de formation complémentaires (texte).
- **À chaud — entreprise** (défaut, **inactif**) : satisfaction globale (note), réponse au besoin
  (note), organisation administrative (note), commentaires (texte).

Modèles d'e-mails (`MailTemplateRegistry`, modifiables) : `survey_hot`, `survey_cold`,
`survey_client`, variables `{prenom}`, `{nom}`, `{formation}`, `{session}`, `{date_limite}`,
`{organisme}`, `{lien}` (obligatoire).

## 9. Envoi

`SendSurveyInvitation` (`TenantAwareJob`, après commit, `TenantMailer`, jeton en clair seulement
dans la charge **chiffrée** du job, `ShouldBeEncrypted`) ; échecs : `email_status = failed` +
code, sans texte du serveur ; `HostPolicy` via `TenantMailer`. Texte simple (`mail/plain-text`).

## 10. RGPD

- `SurveyAnonymisation` déclarée dans `FicheLinks` : pour la personne, efface `email` des
  invitations et les **réponses de type texte** (les notes et choix restent, non identifiants une
  fois la personne anonymisée), ferme ses liens.
- `FicheLinks` données liées : une invitation rattache la personne (annulation d'import).
  Fusion : repointe `person_id`.
- Export RGPD de la personne : section « Questionnaires » (campagnes, date de réponse, réponses).
- Conservation : pas de purge automatique en 0.11 (à décider avec Qualiopi : 3 ans d'audit).

## 11. Frontières

- `Questionnaires` lit Sessions par un nouveau service `Sessions\Public\Surveys` (sessions et
  stagiaires suivis, dernier jour, statut, sessions terminées à une date, contacts de dossier,
  `allows`) et le CRM par `Crm\Public\Fiches` (nom, e-mail) / `FicheLinks`. Aucun autre module
  n'importe `Questionnaires` ni ne nomme ses tables (`ModuleBoundariesArchTest`, motif ajouté).
- Zones à ne pas toucher (autres sessions) : `Sessions/Attendance` (émargement), `Finance`.
  Seules modifications hors du module : `Sessions\Public\Surveys` (nouveau),
  `Sessions\Public\EnrolmentSatisfaction` (nouveau registre), une ligne de `EnrolmentRows`,
  `routes/console.php`, l'arch test, la navigation.

## 12. Laissé au constructeur

Noms exacts des classes et composants, mise en page des écrans (cohérente avec la page session et
les écrans d'administration existants), découpage des tests, emplacement exact du mécanisme de
résolution du jeton public (reprendre celui des liens publics existants).
