# Déroulement automatique et « À traiter » — conception

Date : 2026-11-22. **Approuvée par l'utilisateur le 2026-11-22.** Livraison visée : avant la démonstration du 30/11/2026 (code figé le 27-28/11).

## 1. Intention

Montrer pendant la démonstration que le TMS **travaille seul** (les envois partent sans clic) et que chacun **voit d'un
coup d'œil ce qui l'attend**. Pour l'équipe de CAP FORMATION, c'est du temps gagné dès le test réel de décembre :
plus de convocations oubliées, plus de stagiaires qui ignorent l'horaire, plus de conventions qui dorment sans
signature.

Deux parties, livrées ensemble (choix de l'utilisateur : « C, va pour les deux ») :

- **A. Automatismes** : trois envois automatiques fixes, réglables par organisme.
- **B. « À traiter »** : une page en lecture seule qui rassemble ce que chaque module déclare à traiter.

## 2. Objectifs, non-objectifs, anti-objectifs

**Objectifs**

- A1. **Convocation à J-7** : les convocations manquantes des sessions confirmées démarrant dans N jours (7 par
  défaut) sont émises et envoyées aux stagiaires.
- A2. **Rappel la veille** : un e-mail rappelle aux stagiaires et au(x) formateur(s) la journée du lendemain (date,
  horaires, lieu).
- A3. **Relance des signatures en attente** : une demande de signature en ligne non signée (convention, contrat,
  devis) est renvoyée après N jours (3 par défaut), au plus 2 fois.
- A4. Réglages par organisme : chaque automatisme activable, délai en jours ; modèles d'e-mails modifiables comme les
  autres.
- B1. Page **À traiter** : une ligne par sujet (nombre + lien vers l'écran concerné, filtré si possible), selon les
  droits et périmètres de l'utilisateur ; compteur total dans le menu.

**Non-objectifs (cette livraison)**

- SMS (passerelle à choisir, consentements) ; moteur de règles « si… alors… » configurable ; relances
  « intelligentes » (score, priorisation) ; envois aux financeurs ou aux entreprises autres que ceux listés ;
  actions depuis la page À traiter (elle ne fait que mener aux écrans).

**Anti-objectifs (échec même si « ça marche »)**

- Un e-mail qui part **deux fois** (relance de la tâche, deux serveurs, rattrapage) ou qui part pour une session
  annulée, à la corbeille, ou à une personne anonymisée.
- Un e-mail qui contient un prix, une note, un besoin d'adaptation, ou le nom d'autres stagiaires.
- Une page À traiter qui montre à quelqu'un un nombre ou un lien hors de son périmètre.
- Une page À traiter lente (elle sert aussi au compteur du menu, chargé à chaque page).

## 3. Contraintes du projet (rappel)

Toutes les règles de `CLAUDE.md` s'appliquent, en particulier :

- Isolation des organismes (RLS, `BelongsToTenant`), traitements planifiés par `ForEachTenant::run()`, jobs
  `TenantAwareJob` (envois après commit, `TenantMailer`, motif d'échec sans texte du serveur), cache préfixé par le
  tenant.
- Frontières de modules (`ModuleBoundariesArchTest`) : chaque automatisme vit dans le module propriétaire de la
  donnée ; un module ne lit pas les tables d'un autre ; Core et Crm n'importent jamais Sessions/Documents/Finance.
- Jours et heures de Paris ; aucun texte d'interface en anglais ; TDD.
- Données personnelles : rien en clair dans le journal d'audit ni les logs.

## 4. Approches envisagées

1. **Une tâche quotidienne par module + un registre pour À traiter** (retenue). Chaque automatisme est une petite
   classe du module propriétaire, lancée par une commande planifiée sur le modèle de `tms:finance:remind` /
   `tms:sessions:follow-ups` ; l'idempotence repose sur une table d'envois uniques. À traiter suit le modèle des
   registres existants (`DashboardIndicators`, `Measures`, `FicheBlocks`). Respecte les frontières, réutilise
   `DocumentBatch`, `Signatures`, `MailTemplates`.
2. **Moteur de règles central** (déclencheur, délai, action, cible paramétrables). Écarté : trop gros pour 8 jours,
   difficile à tester exhaustivement, risque pour la démo.
3. **Boutons « à envoyer » suggérés** (l'utilisateur clique). Écarté : ce n'est plus automatique, ce qu'on veut montrer.

## 5. Partie A — automatismes

### 5.1 Socle commun

- **Table `automation_sends`** (RLS, `BelongsToTenant`) : `kind` (`convocation`, `day_before`, `signature_reminder`),
  `subject_id` (uuid), `occurrence` (texte : date de Paris `YYYY-MM-DD` ou numéro de relance), `sent_at`,
  index **unique** (`tenant_id`, `kind`, `subject_id`, `occurrence`). Une ligne est insérée dans la transaction qui
  met l'envoi en file : un second passage (même jour, autre serveur, rattrapage) n'envoie rien. Même mécanique que
  `follow_up_reminders`.
  *Choix du concepteur* : une table commune dans Core (`Core\Automation\AutomationSends`, service public
  `claim(kind, subjectId, occurrence): bool`) plutôt qu'une table par module — elle ne contient aucune donnée métier.
- **Réglages** : `tenants.settings.automation` lus à chaque appel (comme `TrainerFollowUp`) par
  `Core\Automation\AutomationSettings` : `convocation_days` (défaut 7, 0 = désactivé, max 30), `day_before`
  (défaut vrai), `signature_reminder_days` (défaut 3, 0 = désactivé, max 30), `signature_reminder_max` (défaut 2,
  max 5). Écran Administration › **Automatismes** (droit `settings.manage`), journalisé.
- **Commande** `tms:automation:daily` à **7 h 15 Paris** (`ForEachTenant`, `withoutOverlapping`, `onOneServer`) qui
  appelle les automatismes déclarés par les modules dans un registre `Core\Automation\DailyAutomations::register(
  'cle', callable)` ; une erreur sur un automatisme ou un organisme n'arrête pas les autres (journal sans donnée
  personnelle).
- **Rattrapage** : fenêtre limitée — un automatisme ne traite que ce qui tombe **aujourd'hui** selon son délai, plus
  ce qui aurait dû partir hier (une nuit sans tâche ne fait rien perdre ; au-delà, rien n'est rattrapé : une
  convocation à J-2 serait trompeuse). *Choix du concepteur, à confirmer.*
- **Historique** : chaque envoi apparaît là où l'envoi manuel apparaît déjà (document « Envoyé », demande de
  signature, historique du dossier) avec la mention « automatique ».

### 5.2 A1 — Convocation à J-N (module Documents)

- Sélection : sessions **confirmées** (jamais planifiées, annulées, à la corbeille), dont la **première séance non
  annulée** tombe **entre aujourd'hui + 2 et aujourd'hui + N** (jours de Paris). Le cas courant part donc à J-N ; une
  session confirmée tard (à J-3 par exemple) reçoit ses convocations **le lendemain matin** de sa confirmation
  (décision de l'utilisateur, 2026-11-22) ; la veille du début et après, plus rien ne part (la ligne reste dans
  À traiter). Cette fenêtre remplace, pour A1, la règle de rattrapage « aujourd'hui + hier » du § 5.1, par `Sessions\Public\Paperwork` (nouvelle méthode
  `sessionsStartingOn(date)` si besoin).
- Pour chaque stagiaire attendu (inscription active) sans convocation vivante : émission
  (`DocumentIssuer::issueForEnrolment`) puis envoi (`DocumentSender`) avec le modèle `document_convocation` existant.
  Une convocation déjà émise ou envoyée à la main n'est ni réémise ni renvoyée.
- Auteur : l'émission exige aujourd'hui un `User` ; *choix du concepteur* : `issued_by` nul pour une émission
  automatique (la colonne est déjà nullable) via un chemin dédié (`DocumentBatch::issueAutomatically` /
  `sendAutomatically`) qui ne passe pas par le périmètre d'un utilisateur. Le journal d'audit enregistre l'acteur
  « Automatisme ».
- Stagiaire sans e-mail : convocation émise, non envoyée ; elle apparaît dans À traiter (« Convocations non
  envoyées »).
- Effet existant conservé : quand tous les stagiaires attendus ont reçu la leur, le dossier passe « Convocations
  envoyées ».
- Clé d'unicité : (`convocation`, session, date de la première séance).

### 5.3 A2 — Rappel la veille (module Sessions)

- Chaque jour, pour chaque session non annulée ayant au moins une séance non annulée **demain** (Paris) :
  - un e-mail à chaque **stagiaire attendu** ce jour-là (règle de `Attendances::expected`, date d'entrée et de sortie
    comprises) ayant un e-mail ;
  - un e-mail à chaque **formateur** des séances du lendemain (fiche formateur avec e-mail), listant ses séances.
- Contenu : intitulé de la session, date, horaires de chaque séance, lieu (salle et adresse, ou « à distance » avec
  le lien de classe virtuelle s'il existe), contact de l'organisme. **Jamais** prix, financement, notes, besoins
  d'adaptation ni liste des autres stagiaires.
- Modèles d'e-mails `session_day_before_trainee` et `session_day_before_trainer` (variables fermées, déclarés au
  `MailTemplateRegistry`, modifiables dans l'administration).
- Job `SendDayBeforeReminder` (`TenantAwareJob`, `ShouldBeEncrypted`, après commit, `TenantMailer`).
- Personne anonymisée ou à la corbeille : rien. Clé d'unicité : (`day_before`, inscription ou formateur, date).

### 5.4 A3 — Relance des signatures en attente (Core\Signatures)

- Demandes `pending`, non expirées, dont le dernier envoi (création ou relance) date d'au moins N jours, et dont le
  nombre de relances est < max. Le garde du module propriétaire (`Signatures::registerGuard`) est consulté : un devis
  qui n'attend plus de réponse n'est pas relancé.
- Le jeton n'est gardé qu'en sha256 : la relance **génère un nouveau jeton** (l'ancien lien cesse de fonctionner,
  mention dans l'e-mail : « ce lien remplace le précédent »), met à jour `token_hash`, `reminders_sent`,
  `last_sent_at`, et envoie le modèle `signature_reminder` (nouveau, `{lien}` obligatoire, échéance rappelée). La
  date d'expiration ne change pas.
- Clé d'unicité : (`signature_reminder`, demande, numéro de relance).

## 6. Partie B — « À traiter »

### 6.1 Registre

- `Core\Todo\TodoSources::register(string $key, string $permission, Closure $count, Closure $link, string $label,
  string $group)` : `$count(User): int` applique le périmètre de l'utilisateur (même source que l'écran lié) ;
  `$link` donne l'URL de l'écran, filtré quand le filtre existe.
- Une source n'est évaluée que si l'utilisateur détient son droit ; une source en erreur affiche « Indisponible »
  (comme les mesures Qualiopi) sans faire tomber la page.
- Cache 60 s par utilisateur, clé `tenant:<id>:todo:<user>` (le compteur du menu lit le total en cache).

### 6.2 Sources livrées (chacune déclarée par son module, dans son `boot`)

| Groupe | Ligne | Module | Droit | Lien |
| --- | --- | --- | --- | --- |
| Sessions | Sessions qui démarrent dans 7 jours avec des étapes à faire | Sessions | `sessions.sessions.view` | liste des sessions |
| Sessions | Rattrapages d'émargement à confirmer | Sessions | `sessions.attendance.manage` (hors « Ses séances ») | session concernée ou liste |
| Sessions | Demi-journées passées non validées | Sessions | `sessions.attendance.manage` | session |
| Documents | Convocations non envoyées (stagiaire sans e-mail, échec) | Documents | droit documents | session |
| Signatures | Documents et devis en attente de signature depuis plus de N jours | Documents, Crm | droits respectifs | dossier / devis |
| Commercial | Devis envoyés sans réponse depuis plus de 15 jours | Crm | `crm.quotes.view` | liste des devis filtrée |
| Admissions | Candidatures à traiter (étape initiale) | Admissions | `admissions.candidatures.view` | liste filtrée |
| Finance | Dossiers à facturer | Finance | droit facturation | Finance › À facturer |
| Finance | Factures échues non soldées | Finance | droit factures | Finance › À relancer |

*Choix du concepteur* : la liste exacte peut être réduite par le constructeur si une source n'a pas de requête
fiable déjà existante ; mieux vaut 6 lignes justes que 9 approximatives. Les seuils (7 jours, 15 jours) sont des
constantes de cette livraison, pas des réglages.

### 6.3 Écran

- Menu : entrée **À traiter** sous Accueil, compteur (total) ; visible dès qu'au moins une source est accessible.
- Page `/a-traiter` : lignes regroupées (Sessions, Documents, Commercial, Admissions, Finance), chaque ligne = libellé,
  nombre, bouton « Voir » ; les lignes à zéro sont repliées sous « Rien à traiter » ; message global si tout est à
  zéro.
- Pas de confusion avec « À faire » du CRM (actions commerciales) : libellés distincts, À traiter peut inclure une
  ligne « Actions commerciales en retard » qui mène à « À faire ».

## 7. Démonstration

Ajout au plan de démo : régler les délais (Administration › Automatismes), lancer la tâche à la main (commande ou
bouton réservé à l'administrateur « Exécuter maintenant », *choix du concepteur* pour la démo), montrer les e-mails
reçus (convocation, rappel, relance de signature) puis la page À traiter.

## 8. Décisions prises après relecture (choix du concepteur, sauf mention)

1. **Lien remplacé par la relance (A3)** : accepté. Garde : une demande dont un code a été demandé depuis moins de
   24 h (`code_sent_at`) n'est pas relancée ce jour-là (le signataire est en train de signer).
2. **Colonnes A3** : migration ajoutant `reminders_sent` (entier, défaut 0) et `last_sent_at` (nul jusqu'à la
   première relance ; « dernier envoi » = `last_sent_at` sinon `created_at`) à `signature_requests`. La relance part à
   `signer_email` seulement ; sans adresse, rien.
3. **Convocation « vivante » (A1)** : non annulée (`status != cancelled`). Une convocation **annulée** par un
   gestionnaire n'est jamais réémise automatiquement (décision humaine respectée).
4. **Clé A1** : (`convocation`, **inscription**) et non (session, date) : une séance déplacée ne renvoie rien ; la
   nouvelle date se communique à la main. Une inscription ajoutée après le passage reçoit la sienne au passage
   suivant si elle est encore dans la fenêtre.
5. **Échec d'envoi** : la ligne d'unicité est écrite quand l'envoi est mis en file ; un échec SMTP n'est pas
   retenté par l'automatisme. A1 : il garde l'état d'échec du document (`email_status = failed`) et apparaît dans
   À traiter (« Convocations non envoyées ») ; A2 et A3 : journal sans donnée personnelle seulement (un rappel la
   veille raté ne se rattrape pas).
6. **Stagiaires concernés (A1, A2)** : ceux de `Attendances::expected` pour la date concernée (inscrits, ni en liste
   d'attente ni abandonnés, dans leurs dates d'entrée et de sortie) ; personne à la corbeille ou anonymisée exclue.
7. **Sessions concernées (A2)** : **confirmées** seulement, comme A1 (une session planifiée n'est pas encore
   annoncée aux stagiaires).
8. **Formateur (A2)** : formateur des séances du lendemain, e-mail lu sur sa personne (`Crm\Public\Fiches`) ; sans
   e-mail, ignoré. Lieu = salle et `place_text` de la séance ; pas de lien de classe virtuelle (aucun champ existant).
9. **« Exécuter maintenant »** : réservé à `settings.manage`, met la tâche en file (job `TenantAwareJob`), applique
   les mêmes règles et la même fenêtre que la tâche de 7 h 15. Pour la démo, on prépare une session confirmée qui
   démarre à J+N et une séance le lendemain.
10. **Droits des sources À traiter** : le constructeur prend les droits existants de l'écran lié. « Signatures en
    attente » = deux lignes, une par module (Documents, Crm), pas de nouveau registre.
11. **Compteur du menu** : prop Inertia **différée** (chargée après la page, comme une donnée paresseuse), lue dans
    le cache de 60 s ; jamais calculée dans le chemin de chaque requête.

## 9. Laissé au constructeur

Noms exacts des classes et des routes, découpage des tâches, requêtes de chaque source (en réutilisant les requêtes
de liste existantes), présentation exacte des e-mails, emplacement du bouton « Exécuter maintenant ».
