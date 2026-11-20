# Questionnaires de satisfaction (0.11) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Questionnaires à chaud / à froid envoyés aux stagiaires (et en option au contact entreprise) par lien personnel et QR code de session, résultats par session et agrégés, mesures Qualiopi 30 et 2.

**Architecture:** Nouveau module `app/Modules/Questionnaires/` (provider propre) ; lit Sessions par un nouveau service `Sessions\Public\Surveys`, le CRM par `Crm\Public\Fiches` / `FicheLinks` ; s'affiche par `SessionBlocks` / `SessionCards` ; satisfaction sur la fiche par un nouveau registre `Sessions\Public\EnrolmentSatisfaction` ; mesures par `Qualite\Public\Measures`.

**Tech Stack:** Laravel 12, Pest, PostgreSQL RLS, Inertia React 19, `bacon/bacon-qr-code` (déjà installé).

**Spec:** `docs/superpowers/specs/2026-11-20-questionnaires-design.md`

## Global Constraints

- Toute table : `tenant_id uuid not null`, `Rls::enable`, modèle `BelongsToTenant` + `HasUuids` (UUID v7 comme l'existant).
- Migrations seulement `--database=pgsql_owner` ; jamais `migrate:fresh` ; pas de modification des données de dev.
- Interface et messages en français ; dates `d/m/Y` ; CSV `;`.
- Jetons publics : 64 hex (`bin2hex(random_bytes(32))`), seul le sha256 en base, `hash_equals` ; même 404 pour tout lien invalide.
- Aucun texte libre ni e-mail dans `audit_logs` ou les logs ; le jeton en clair seulement dans la charge chiffrée du job (`ShouldBeEncrypted`).
- Ne pas toucher `app/Modules/Sessions/Attendance/**` ni `app/Modules/Finance/**`.
- Tests locaux : ciblés (`--filter`) + `tests/Arch` ; une seule suite Pest à la fois sur la machine.
- Commits en anglais, conventionnels, trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Valeurs : lien 30 jours (`LINK_DAYS = 30`), délai à froid défaut 3 mois (0 = aucun, max 24), rattrapage 30 jours, satisfait = score ≥ 400 (moyenne ≥ 4/5), throttle public 30/min.

## Review Focus

1. Stagiaire qui répond par le QR puis clique le lien e-mail → page « Merci, vous avez déjà répondu », aucune 2ᵉ réponse (test Task 6).
2. Double envoi simultané du formulaire → une seule `survey_responses` (verrou + unique), le second reçoit 409 (test Task 6).
3. Lien d'une campagne close, d'une session à la corbeille ou d'un autre organisme → même 404 (test Task 6).
4. Session sans stagiaire avec e-mail → lancement réussi, invitations `email_status = none`, QR utilisable (test Task 5).
5. La commande quotidienne relancée le même jour ou après un lancement manuel → aucune 2ᵉ campagne (test Task 8).

---

### Task 1: Services publics de Sessions

**Files:**
- Create: `tms/app/Modules/Sessions/Public/Surveys.php`, `tms/app/Modules/Sessions/Public/EnrolmentSatisfaction.php`
- Modify: `tms/app/Modules/Sessions/Enrolments/EnrolmentRows.php` (ligne « Satisfaction : - »)
- Test: `tms/tests/Feature/Sessions/SurveysPublicTest.php`

**Interfaces — Produces:**
- `Surveys::session(string $sessionId): ?array{id, label, training_id, ends_on: string, cancelled: bool}` (null si absente ou à la corbeille)
- `Surveys::trainees(string $sessionId): list<array{enrolment_id, person_id, dossier_id}>` (statuts inscrit, confirmé ; parti et annulé exclus ; ordre d'entrée)
- `Surveys::clientContacts(string $sessionId): list<string>` (person_id `contact_person_id` distincts des dossiers non annulés ayant un compte organisation)
- `Surveys::endedOn(string $from, string $to): list<string>` (ids des sessions non annulées, non supprimées, `ends_on` entre deux dates incluses)
- `Surveys::allows(User $user, string $sessionId, bool $update): bool` (`SessionsVisibility::allows` avec `SESSIONS_VIEW` ou `SESSIONS_UPDATE`)
- `EnrolmentSatisfaction::register(Closure(list<string> $enrolmentIds): array<string,string>)`, `EnrolmentSatisfaction::for(list<string>): array<string,string>` (libellé « 4,5/5 » par inscription ; registre vide = [])

- [ ] **Step 1: Tests** — `Surveys::trainees` exclut parti/annulé/liste d'attente ; `endedOn` exclut annulée et à la corbeille ; `allows` vrai/faux selon le droit ; `EnrolmentSatisfaction::for` fusionne un registre factice ; `EnrolmentRows::personBlock` affiche « Satisfaction : 4,5/5 » quand le registre répond, « Satisfaction : - » sinon.
- [ ] **Step 2:** `docker compose exec app php artisan test --filter=SurveysPublicTest` → FAIL.
- [ ] **Step 3:** Implémenter (registre : même patron singleton que `SessionBlocks`).
- [ ] **Step 4:** Relancer → PASS ; `--filter=EnrolmentRows` → PASS.
- [ ] **Step 5:** Commit `feat(sessions): public survey service and enrolment satisfaction registry`.

### Task 2: Socle du module (tables, modèles, droits, frontières)

**Files:**
- Create: `tms/database/migrations/2027_01_25_000001_create_survey_tables.php` (4 tables de la spec § 3, CHECK sur `kind`, `audience`, `email_status`, `answered_via` ; index unique partiel `survey_templates (tenant_id, kind, audience) where is_default and deleted_at is null` ; unique `survey_campaigns (tenant_id, training_session_id, kind, audience)` ; unique `survey_invitations (tenant_id, campaign_id, person_id)`, `token_hash` unique ; `survey_responses.invitation_id` unique ; `survey_campaigns.qr_token_encrypted text null`)
- Create: `tms/app/Modules/Questionnaires/{QuestionnairesServiceProvider,Permissions}.php`, `Surveys/{SurveyTemplate,SurveyCampaign,SurveyInvitation,SurveyResponse}.php`
- Modify: `tms/bootstrap/providers.php`, `tms/tests/Arch/ModuleBoundariesArchTest.php`
- Test: `tms/tests/Feature/Questionnaires/SurveyTablesTest.php`

**Interfaces — Produces:** constantes `Permissions::VIEW = 'questionnaires.view'`, `Permissions::MANAGE = 'questionnaires.manage'`, `GROUP = 'Questionnaires'` ; défauts : admin et direction les deux, responsable DAS et assistant `VIEW` ; non scopés. Modèles : `SurveyCampaign::HOT|COLD`, `TRAINEE|CLIENT` ; `SurveyInvitation::findByToken(string): ?self`, `hashToken(string): string`, `linkActive(): bool` (campagne `closes_on` ≥ aujourd'hui Paris et `answered_at` null) ; `SurveyCampaign::findByQrToken(string): ?self`.
Arch : aucun autre module ne référence `App\Modules\Questionnaires` ; `Questionnaires` ne référence d'autres modules que `Core`, `Sessions\Public`, `Crm\Public`, `Qualite\Public`, `Catalogue\Trainings`, `Connectors\Smtp\(TenantMailer|SmtpHostRefused)`, `Connectors\Core\(ConnectorRun|PermanentDeliveryFailure)` (motif calqué sur `DOCUMENTS_FOREIGN_REFERENCE`) ; ajouter les 4 tables au motif des tables interdites aux autres modules.

- [ ] **Step 1: Tests** — RLS : une invitation d'un autre tenant est invisible ; `findByToken` sur jeton mal formé → null ; arch tests (nouveaux `it(...)`).
- [ ] **Step 2:** Lancer → FAIL.
- [ ] **Step 3:** Migration (`php artisan migrate --database=pgsql_owner`, puis base de test selon `tests` existants), modèles, provider, permissions.
- [ ] **Step 4:** `--filter=SurveyTablesTest` et `php artisan test tests/Arch` → PASS.
- [ ] **Step 5:** Commit `feat(questionnaires): survey tables, models, rights`.

### Task 3: Contenu et notation (purs)

**Files:** Create `tms/app/Modules/Questionnaires/Surveys/SurveyContent.php`, `SurveyScoring.php` ; Test `tms/tests/Unit/Questionnaires/SurveyContentTest.php`

**Interfaces — Produces:**
- `SurveyContent::validate(mixed $questions): list<array{id,type,label,required,options?}>` (lève `ValidationException`, clés `questions.<i>.<champ>` ; règles spec § 3 ; ids attribués s'ils manquent : `Str::random(8)` minuscule)
- `SurveyContent::validateAnswers(array $questions, mixed $answers): array<string,int|string>` (obligatoires, note entière 1-5, option existante, texte ≤ 2000, clés inconnues ignorées, réponses vides facultatives retirées)
- `SurveyScoring::score(array $questions, array $answers): ?int` (moyenne des notes ×100, `RoundingMode::HALF_UP` brick/math ; null sans note) ; `SurveyScoring::SATISFIED = 400` ; `SurveyScoring::label(?int): string` (« 4,3/5 » ou « - »)

- [ ] **Step 1: Tests** — 31 questions refusées ; `choice` à 1 option refusé ; note 0 et 6 refusées ; obligatoire manquant → erreur `answers.<id>` « Cette question est obligatoire. » ; score([5,4,4]) = 433 ; score([5,4]) = 450 ; sans note → null ; label(433) = « 4,3/5 ».
- [ ] **Step 2–4:** FAIL → implémenter → PASS.
- [ ] **Step 5:** Commit `feat(questionnaires): survey content validation and scoring`.

### Task 4: Modèles de questionnaires et réglage du délai

**Files:**
- Create: `tms/app/Modules/Questionnaires/Templates/{SurveyTemplates,DefaultTemplates,SurveyTemplateController}.php`, `tms/app/Modules/Questionnaires/Settings/ColdDelay.php`, `tms/routes/questionnaires.php` (inclus dans `routes/web.php`), `tms/resources/js/pages/admin/questionnaires/{index,form}.tsx`
- Modify: navigation d'administration (même endroit que « Règle d'abandon et suivi »)
- Test: `tms/tests/Feature/Questionnaires/SurveyTemplatesTest.php`

**Interfaces — Produces:**
- `DefaultTemplates::ensure(): void` — si l'organisme n'a **aucun** modèle (corbeille comprise), crée les 3 modèles de la spec § 8 (libellés exacts) ; appelé par l'index admin, le lancement et la commande.
- `SurveyTemplates::create|update(array, User)`, `delete(SurveyTemplate)` (logique), `setDefault(SurveyTemplate)` (sous verrou : retire l'ancien défaut du même `kind`+`audience`) ; `defaultFor(string $kind, string $audience): ?SurveyTemplate` (actif seulement).
- `ColdDelay::months(): int` (`tenants.settings.questionnaires.cold_months`, défaut 3, 0..24, relu à chaque appel comme `TrainerFollowUp`) ; `ColdDelay::set(int)`.
- Routes (`auth`, `can:questionnaires.manage`) : `admin/questionnaires` (index, create, store, edit, update, destroy, `default`), `PUT admin/questionnaires/delai`.
- Écran : liste (nom, type « À chaud / À froid », public « Stagiaires / Entreprise », actif, par défaut), éditeur de questions (ajouter, monter/descendre, supprimer, type, libellé, obligatoire, options), champ « Questionnaire à froid envoyé … mois après la fin (0 = jamais) ».

- [ ] **Step 1: Tests** — `ensure` crée 3 modèles une seule fois ; `setDefault` laisse un seul défaut ; `defaultFor` ignore un modèle inactif ; 403 sans `questionnaires.manage` ; `ColdDelay` hors bornes → défaut ; PUT délai 25 → erreur de validation.
- [ ] **Step 2–4:** FAIL → implémenter → PASS.
- [ ] **Step 5:** Commit `feat(questionnaires): templates administration and cold delay setting`.

### Task 5: Lancement des campagnes et envoi

**Files:**
- Create: `tms/app/Modules/Questionnaires/Campaigns/{SurveyLaunch,SurveyMailTemplates,SendSurveyInvitation,SurveyMail,SurveyMailFailed,SurveyCampaignController}.php`
- Test: `tms/tests/Feature/Questionnaires/SurveyLaunchTest.php`

**Interfaces — Produces:**
- `SurveyLaunch::launch(string $sessionId, string $kind, ?User $by): ?SurveyCampaign` — transaction ; `DefaultTemplates::ensure()` ; sans modèle par défaut actif `kind`/`trainee` → null ; session annulée → `UserFacingHttpException` 409 « La session est annulée. » (null en automatique) ; campagne existante → la renvoie après `inviteMissing` ; copie figée des questions ; `closes_on` = aujourd'hui Paris + 30 j ; QR (chaud seulement) : jeton stocké `qr_token_hash` + `qr_token_encrypted` (`Crypt::encryptString`) ; pour `hot`, lance aussi la campagne `client` si un modèle par défaut actif `hot`/`client` existe (destinataires `Surveys::clientContacts`).
- `SurveyLaunch::inviteMissing(SurveyCampaign): int` — une invitation par destinataire manquant ; e-mail via `Fiches::personEmails` ; avec e-mail : `pending` + `SendSurveyInvitation::dispatch($id, $token)->afterCommit()` ; sans : `none`.
- `SurveyMailTemplates::register()` : `survey_hot`, `survey_cold`, `survey_client` (variables spec § 8, `{lien}` requis).
- `SendSurveyInvitation` : patron `SendPositioningTest` (3 essais, `failed` + code fixe, lit la ligne courante).
- Routes (`auth`) : `POST sessions/{trainingSession}/questionnaires/{kind}` (lancer ou inviter les manquants ; `Surveys::allows(..., update: true)` sinon 403 ; `throttle:survey-launch`).

- [ ] **Step 1: Tests** — lancement : N invitations, e-mails en file (`Queue::fake`) seulement pour les stagiaires avec adresse, les autres `none` ; deuxième appel n'ajoute que le nouveau stagiaire ; campagne client créée seulement si modèle client actif ; session annulée → 409 ; sans droit → 403 ; le job remplace `{lien}` par `questionnaire/{token}` et passe `sent` ; échec SMTP → `failed` sans texte serveur.
- [ ] **Step 2–4:** FAIL → implémenter → PASS.
- [ ] **Step 5:** Commit `feat(questionnaires): launch campaigns and send invitations`.

### Task 6: Pages publiques de réponse

**Files:**
- Create: `tms/app/Modules/Questionnaires/Answering/{SurveyAnswers,SurveyAnswerController}.php`, `tms/resources/js/pages/public/{survey,survey-who}.tsx`
- Modify: `tms/routes/questionnaires.php` (groupe public `throttle:survey-public`), limiteur `survey-public` (30/min/IP) dans le provider
- Test: `tms/tests/Feature/Questionnaires/SurveyAnsweringTest.php`

**Interfaces — Produces:**
- Routes publiques (hors `auth`, `where token [0-9a-f]{64}`) : `GET|POST questionnaire/{token}` (`survey.show|submit`), `GET|POST questionnaire/s/{token}` (`survey.qr|qr.choose`), `GET|POST questionnaire/q/{invitation}` signées (`survey.signed.show|submit`, `URL::temporarySignedRoute`, 2 h).
- `SurveyAnswers::submit(SurveyInvitation, array $answers, string $via): void` — transaction, `lockForUpdate` de l'invitation, déjà répondu → `UserFacingHttpException` 409 « Vous avez déjà répondu à ce questionnaire. » ; campagne close → 404 ; `SurveyContent::validateAnswers` ; `SurveyResponse` + `score` ; `answered_at`, `answered_via`.
- Page `survey` : titre de la campagne, session, questions (note : 5 boutons 1-5 grand format mobile ; choix : radios ; texte : zone), « Envoyer » ; états « Merci » et « Déjà répondu ». Page `survey-who` : liste « Prénom N. » des invitations non répondues, choix → redirection signée.
- 404 : même vue que les autres liens publics (reprendre `PositioningUploadController::show` pour la réponse inconnue).

- [ ] **Step 1: Tests** — Review Focus 1, 2, 3 ; réponse valide → score enregistré ; liste QR n'expose que prénom + initiale et exclut les répondants ; POST qr.choose d'une invitation d'une autre campagne → 404 ; URL signée expirée → 403/404 ; jeton mal formé → 404.
- [ ] **Step 2–4:** FAIL → implémenter → PASS.
- [ ] **Step 5:** Commit `feat(questionnaires): public answer pages with personal link and session QR`.

### Task 7: Bloc et carte sur la page session, QR imprimable

**Files:**
- Create: `tms/app/Modules/Questionnaires/Results/{SurveyResults,SessionSurveysBlock}.php`, `tms/resources/js/components/questionnaires/session-surveys-block.tsx`, `tms/resources/js/pages/sessions/questionnaire-qr.tsx`
- Modify: `tms/resources/js/components/session-blocks.tsx` (clé `questionnaires.session`), provider (`SessionBlocks::register('questionnaires.session', …)`, `SessionCards::register('questionnaires', …)`)
- Test: `tms/tests/Feature/Questionnaires/SessionSurveysBlockTest.php`

**Interfaces — Produces:**
- `SurveyResults::campaign(SurveyCampaign): array{sent, invited, answered, response_rate: ?int, average: ?int, satisfaction_rate: ?int, questions: list<{id,label,type, average?, distribution?, counts?, texts?}>}` (textes sans nom, plus récents d'abord)
- `SurveyResults::rate(int $part, int $total): ?int` (pourcentage arrondi ; null si total 0)
- `SessionSurveysBlock::for(string $sessionId, User): ?array` (null sans droit de consulter la session ; `can_launch` = droit modifier ; props : campagnes + résultats + invitations {name, email_status, answered}) ; `cards(list<string>, User)` (règles spec § 5).
- Route `GET sessions/{trainingSession}/questionnaire-qr` (consulter la session) : page plein écran, QR SVG (`BaconQrCode\Writer` + `SvgImageBackEnd`) de `TenantUrl::route('survey.qr', $token)`, adresse en clair, bouton Imprimer ; 404 sans campagne à chaud.

- [ ] **Step 1: Tests** — bloc null sans droit ; `can_launch` faux avec seulement consulter ; taux de réponse 2/4 = 50 ; taux de satisfaction ; carte `todo` après la fin sans campagne, `done` avec réponse, `na` avant ; page QR 404 sans campagne, 200 et SVG présent avec.
- [ ] **Step 2–4:** FAIL → implémenter → PASS.
- [ ] **Step 5:** Commit `feat(questionnaires): session block, summary card and printable QR`.

### Task 8: Commande quotidienne

**Files:** Create `tms/app/Modules/Questionnaires/Campaigns/DailySurveys.php`, `tms/app/Console/Commands/QuestionnairesDaily.php` (ou commande dans le module selon le patron de `tms:sessions:follow-ups`) ; Modify `tms/routes/console.php` (`tms:questionnaires:daily`, `07:00`, `Europe/Paris`, `withoutOverlapping`, `onOneServer`) ; Test `tms/tests/Feature/Questionnaires/DailySurveysTest.php`

**Interfaces — Produces:** `DailySurveys::run(CarbonImmutable $today): array{hot:int, cold:int}` — chaud : `Surveys::endedOn(today−30j, today−1j)` sans campagne `hot` → `SurveyLaunch::launch(…, 'hot', null)` ; froid : si `ColdDelay::months() > 0`, `endedOn(today−30j−N mois, today−N mois)` sans campagne `cold` → `launch(…, 'cold', null)`. Commande : `ForEachTenant::run`.

- [ ] **Step 1: Tests** — Review Focus 5 ; session finie hier → chaud lancé ; finie il y a 31 jours → rien ; froid à N=3 : finie il y a 3 mois → lancé, N=0 → rien ; session annulée → rien ; sans modèle froid actif → rien, pas d'erreur.
- [ ] **Step 2–4:** FAIL → implémenter → PASS.
- [ ] **Step 5:** Commit `feat(questionnaires): daily hot catch-up and cold sending`.

### Task 9: Résultats agrégés, satisfaction sur la fiche, mesures Qualiopi

**Files:**
- Create: `tms/app/Modules/Questionnaires/Results/{SurveyOverview,SurveyOverviewController,SurveyMeasures}.php`, `tms/resources/js/pages/qualite/questionnaires.tsx`
- Modify: provider (`EnrolmentSatisfaction::register`, `SurveyMeasures::register()`), menu Qualité
- Test: `tms/tests/Feature/Questionnaires/SurveyOverviewTest.php`

**Interfaces — Produces:**
- `SurveyOverview::rows(array $filters): list<{session_id, session_label, ends_on, kind, audience, invited, answered, response_rate, average, satisfaction_rate}>` + `totals(...)` ; filtres `from`, `to` (fin de session), `kind`, `audience`, `training_id` ; deux requêtes groupées.
- Routes (`can:questionnaires.view`) : `GET qualite/questionnaires`, `GET qualite/questionnaires/export` (CSV `;`, BOM comme les autres exports, journal `questionnaires.results.exported`).
- `EnrolmentSatisfaction` : score de la réponse à chaud stagiaire de chaque inscription → `SurveyScoring::label`.
- `SurveyMeasures::register()` : clés `questionnaires.hot.response`, `questionnaires.hot.satisfaction`, `questionnaires.cold.response`, `questionnaires.cold.satisfaction`, `questionnaires.client.satisfaction` (seulement si au moins une campagne client) sur indicateurs 30 et 2 ; 12 derniers mois de Paris par fin de session ; `tone` `warn` si taux de réponse < 50 ou aucune campagne, sinon `ok`.

- [ ] **Step 1: Tests** — filtres et totaux ; CSV `;` avec en-têtes français ; 403 sans droit ; fiche apprenant « Satisfaction : 4,5/5 » ; mesures : valeurs « 75 % », détail « 3 réponses sur 4 », ton warn à 40 %.
- [ ] **Step 2–4:** FAIL → implémenter → PASS.
- [ ] **Step 5:** Commit `feat(questionnaires): aggregated results, trainee satisfaction, Qualiopi measures`.

### Task 10: RGPD

**Files:** Create `tms/app/Modules/Questionnaires/Gdpr/SurveyPersonData.php` ; Modify provider (`FicheLinks::registerLinked|registerMerge|registerAnonymisation|registerExport`) ; Test `tms/tests/Feature/Questionnaires/SurveyGdprTest.php`

**Interfaces — Produces:** `SurveyPersonData::hasLinked(string $personId, array $exceptIds): bool` (signature exacte : celle attendue par `FicheLinks::registerLinked`), `merge(string $type, string $keepId, string $dropId)` (repointe `person_id` ; une campagne où les deux ont une invitation : garde celle qui a répondu, supprime l'autre), `anonymise(list<string>)` (efface `email`, met à `null` les réponses `text` dans `answers`, ferme ses liens non répondus en remplaçant leur `token_hash` par le sha256 d'une valeur aléatoire jetée), `export(string): list<section>` (droit `questionnaires.view`).

- [ ] **Step 1: Tests** — anonymisation : e-mail null, texte retiré, note gardée, ancien lien → 404 ; fusion sans violation d'unicité ; export contient la campagne et la date.
- [ ] **Step 2–4:** FAIL → implémenter → PASS.
- [ ] **Step 5:** Commit `feat(questionnaires): GDPR anonymisation, merge, export`.

### Task 11: Documentation, vérifications, PR

**Files:** Create `tms/docs/questionnaires.md` ; Modify `tms/docs/admin.md` (§ Questionnaires), `tms/docs/sessions.md` (§ 11 « Satisfaction »), `tms/docs/qualite.md` (mesures), `tms/docs/data-dictionary.md` (4 tables), `CLAUDE.md` (section « Module Questionnaires »), `tms/CHANGELOG.md` (« Non publié »).

- [ ] **Step 1:** `docker compose exec app vendor/bin/pint` ; `vendor/bin/phpstan analyse --memory-limit=1G` → 0 erreur.
- [ ] **Step 2:** `docker compose run --rm vite sh -c "npm run lint && npm run types && npm run build"` → OK.
- [ ] **Step 3:** `php artisan test --filter=Questionnaires` + `--filter=SurveysPublicTest` + `tests/Arch` → PASS.
- [ ] **Step 4:** Vérification manuelle dans le navigateur (lancer, QR, répondre sur mobile 375 px, résultats).
- [ ] **Step 5:** Commit docs ; `git push -u origin HEAD` et `gh pr create` **seulement après le oui de l'utilisateur**.
