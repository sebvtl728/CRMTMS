# Déroulement automatique et « À traiter » Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Trois envois automatiques (convocation J-N, rappel la veille, relance des signatures) réglables par organisme, et une page « À traiter » qui rassemble ce que chaque module déclare.

**Architecture:** Un socle `Core\Automation` (table d'unicité `automation_sends`, réglages, registre `DailyAutomations`, commande `tms:automation:daily` à 7 h 15 Paris) ; chaque automatisme vit dans le module propriétaire (Documents, Sessions, Core\Signatures) et s'y déclare. « À traiter » = registre `Core\Todo\TodoSources` alimenté par chaque module dans son `boot`, page `/a-traiter`, compteur du menu en prop Inertia différée.

**Tech Stack:** Laravel 12, PHP 8.4, Inertia + React 19 TS, PostgreSQL 17 (RLS), Pest.

**Spec:** `docs/superpowers/specs/2026-11-22-deroulement-automatique-design.md` (approuvée le 2026-11-22 ; § 8 fait autorité sur les points tranchés après relecture).

## Global Constraints

- Toute table métier : `tenant_id uuid not null`, `BelongsToTenant`, `Rls::enable` ; migrations via `--database=pgsql_owner`.
- Traitement planifié : `ForEachTenant::run()` ; jobs `TenantAwareJob` (+ `ShouldBeEncrypted` s'ils portent un jeton ou une adresse), envoi après commit par `TenantMailer`, échec journalisé sans texte du serveur ni donnée personnelle.
- Frontières : Core n'importe aucun module métier ; Documents lit Sessions par `Sessions\Public\Paperwork` ; Sessions lit le CRM par `Crm\Public\*` ; `ModuleBoundariesArchTest` vert.
- Jours de Paris partout (`TrainingSession::today()`, `CarbonImmutable::now('Europe/Paris')`).
- Interface et e-mails en français ; e-mails sans prix, financement, notes, besoins d'adaptation ni liste des autres stagiaires.
- Cache préfixé `tenant:<id>:` ; nouveaux modèles d'e-mails déclarés au `MailTemplateRegistry` (mettre à jour `MailTemplatesTest`).
- Réglages : `tenants.settings.automation` — `convocation_days` défaut 7 (0 = désactivé, max 30), `day_before` défaut `true`, `signature_reminder_days` défaut 3 (0 = désactivé, max 30), `signature_reminder_max` défaut 2 (max 5).
- Commande `tms:automation:daily` à `07:15` Paris, `withoutOverlapping()->onOneServer()`.
- Pint sur chaque fichier modifié ; phpstan sans erreur ; TDD ; tests datés avec horloge figée (`CarbonImmutable::setTestNow`).

## Review Focus

1. Double passage le même jour (ou deux serveurs) : aucun e-mail en double — `claim()` unique sous contrainte ; test dans les tâches 2, 3, 4.
2. Session confirmée tard (J-3) : convocations le lendemain matin ; la veille du début : rien — test tâche 2.
3. Signataire en train de signer (code demandé il y a < 24 h) : pas de relance, son lien reste valide — test tâche 4.
4. Personne anonymisée, à la corbeille, inscription en liste d'attente ou abandonnée : aucun envoi — test tâches 2 et 3.
5. Utilisateur au périmètre « Ses fiches » / « Ses séances » : la page À traiter ne compte que son périmètre — test tâche 6.

---

### Task 1: Socle Core\Automation

**Files:**
- Create: `tms/database/migrations/2027_01_28_000001_create_automation_sends.php`
- Create: `tms/app/Modules/Core/Automation/AutomationSend.php` (modèle, `BelongsToTenant`)
- Create: `tms/app/Modules/Core/Automation/AutomationSends.php`
- Create: `tms/app/Modules/Core/Automation/AutomationSettings.php`
- Create: `tms/app/Modules/Core/Automation/DailyAutomations.php`
- Create: `tms/app/Console/Commands/AutomationDaily.php`
- Modify: `tms/routes/console.php` (planification)
- Test: `tms/tests/Feature/Core/Automation/AutomationSocleTest.php`

**Interfaces:**
- Produces:
  - `AutomationSends::claim(string $kind, string $subjectId, string $occurrence): bool` — insère la ligne (`INSERT … ON CONFLICT DO NOTHING`) et rend `true` seulement si elle est nouvelle ; à appeler dans la transaction qui met l'envoi en file.
  - Constantes `AutomationSends::CONVOCATION = 'convocation'`, `DAY_BEFORE = 'day_before'`, `SIGNATURE_REMINDER = 'signature_reminder'`.
  - `AutomationSettings::convocationDays(): int`, `dayBefore(): bool`, `signatureReminderDays(): int`, `signatureReminderMax(): int`, `all(): array`, `save(array $values): void` (relit la ligne du tenant à chaque appel, comme `Sessions\Settings\TrainerFollowUp`).
  - `DailyAutomations::register(string $key, Closure $run)` ; `DailyAutomations::runAll(CarbonImmutable $today): array<string, int>` (une erreur d'un automatisme est journalisée — clé et classe d'exception seulement — et n'arrête pas les autres).
  - Commande `tms:automation:daily` : `ForEachTenant::run(fn () => DailyAutomations::runAll($today))`, affiche « Automatismes : <clé> <n>, … ».

- [ ] **Step 1: Write the failing tests** — `it('claims an automatic send once')` : deux `claim('convocation', $id, '2026-12-01')` → `true` puis `false` ; un autre `occurrence` → `true`. `it('reads the automation settings with their defaults and bounds')` : sans réglage → 7, true, 3, 2 ; `save(['convocation_days' => 45])` → erreur de validation « 30 jours au plus. » ; `save(['convocation_days' => 0])` → 0. `it('runs every daily automation and survives one failing')` : deux automatismes enregistrés dont l'un lève une exception → `runAll` rend le compte de l'autre.
- [ ] **Step 2: Run** `docker compose exec app php artisan test --filter=AutomationSocleTest` — Expected: FAIL (classes absentes).
- [ ] **Step 3: Implement** la migration (colonnes `id` uuid v7, `tenant_id`, `kind` string 40, `subject_id` uuid, `occurrence` string 20, `sent_at` timestamptz ; unique (`tenant_id`, `kind`, `subject_id`, `occurrence`) ; `Rls::enable`), le modèle et les quatre classes ; planifier `Schedule::command('tms:automation:daily')->dailyAt('07:15')->timezone('Europe/Paris')->withoutOverlapping()->onOneServer();`. Appliquer la migration : `docker compose exec app php artisan migrate --database=pgsql_owner`.
- [ ] **Step 4: Run** le même filtre et `--filter=ArchTest` — Expected: PASS.
- [ ] **Step 5: Commit** `feat(core): automation socle (unique sends, settings, daily runner)`.

### Task 2: A1 — Convocations automatiques (Documents)

**Files:**
- Modify: `tms/app/Modules/Sessions/Public/Paperwork.php` (nouvelle méthode)
- Modify: `tms/app/Modules/Documents/DocumentIssuer.php`, `DocumentSender.php` (`?User $by` pour l'émission et l'envoi automatiques ; `issued_by` / expéditeur nuls ; audit avec l'acteur « Automatisme »)
- Create: `tms/app/Modules/Documents/Automation/ConvocationAutomation.php`
- Modify: `tms/app/Modules/Documents/DocumentsServiceProvider.php` (`DailyAutomations::register('convocations', …)`)
- Test: `tms/tests/Feature/Documents/ConvocationAutomationTest.php`

**Interfaces:**
- Consumes: `AutomationSends::claim`, `AutomationSettings::convocationDays` (tâche 1).
- Produces:
  - `Paperwork::confirmedSessionsStartingBetween(string $from, string $to): list<array{session_id: string, starts_on: string}>` — sessions confirmées, non annulées, hors corbeille, dont la première séance non annulée est dans l'intervalle (inclus).
  - `ConvocationAutomation::run(CarbonImmutable $today): int` — fenêtre `[today+2, today+N]` ; pour chaque inscription attendue (`Paperwork::sessionEnrolments` filtrée par la règle de `Attendances::expected` au premier jour) sans convocation non annulée **et jamais annulée** : `claim(CONVOCATION, enrolmentId, '1')`, émission, puis envoi avec `DocumentMail::defaults` (sans e-mail : émise, non envoyée). Rend le nombre de convocations mises en file.

- [ ] **Step 1: Write the failing tests** (horloge 2026-12-01 08:00 Paris, réglage par défaut 7) :
  - `it('issues and sends the missing convocations seven days before the start')` : session confirmée, 1re séance le 08/12, 2 stagiaires avec e-mail → 2 documents `convocation` envoyés (`Mail::assertQueued`/sent), `issued_by` nul ; dossier « Convocations envoyées ».
  - `it('sends a late confirmed session the next morning, never the day before')` : 1re séance le 04/12 → envoyée ; 1re séance le 02/12 → rien.
  - `it('never sends twice, nor re-issues a cancelled convocation')` : `run` deux fois → 1 envoi par stagiaire ; une convocation annulée à la main → aucune nouvelle.
  - `it('skips planned or cancelled sessions and trainees out of the session')` : session planifiée, session annulée, inscription en liste d'attente → rien.
  - `it('issues without sending for a trainee without e-mail')`.
  - `it('does nothing when the setting is 0')`.
- [ ] **Step 2: Run** `--filter=ConvocationAutomationTest` — Expected: FAIL.
- [ ] **Step 3: Implement** la méthode `Paperwork`, le passage `?User` (tests existants de Documents inchangés), `ConvocationAutomation`, la déclaration dans le provider.
- [ ] **Step 4: Run** `--filter=ConvocationAutomationTest`, puis `php artisan test tests/Feature/Documents` et `--filter=ArchTest` — Expected: PASS.
- [ ] **Step 5: Commit** `feat(documents): automatic convocations before the start`.

### Task 3: A2 — Rappel la veille (Sessions)

**Files:**
- Create: `tms/app/Modules/Sessions/Automation/DayBeforeReminders.php`
- Create: `tms/app/Modules/Sessions/Automation/DayBeforeMailTemplates.php`
- Create: `tms/app/Modules/Sessions/Automation/SendDayBeforeReminder.php` (job)
- Modify: `tms/app/Modules/Sessions/SessionsServiceProvider.php` (modèles + `DailyAutomations::register('day_before', …)`)
- Modify: `tms/tests/Feature/Core/MailTemplatesTest.php` (catalogue)
- Test: `tms/tests/Feature/Sessions/Automation/DayBeforeRemindersTest.php`

**Interfaces:**
- Consumes: `AutomationSends::claim`, `AutomationSettings::dayBefore` (tâche 1) ; `Attendances::expected(Seance)` ; e-mails des personnes par `Crm\Public\Fiches`.
- Produces:
  - Modèles `session_day_before_trainee` (variables `stagiaire`, `session`, `date`, `seances`, `lieu`, `organisme`) et `session_day_before_trainer` (`formateur`, `date`, `seances`, `organisme`) ; `seances` = une ligne par séance « 09:00–12:30 · Module · Salle / lieu ».
  - `DayBeforeReminders::run(CarbonImmutable $today): int` — sessions **confirmées** ayant une séance non annulée demain ; un e-mail par stagiaire attendu avec e-mail (`claim(DAY_BEFORE, enrolmentId, date)`) et par formateur avec e-mail (`claim(DAY_BEFORE, trainerId, date)`), job `SendDayBeforeReminder` (`TenantAwareJob`, `ShouldBeEncrypted`, `afterCommit`).

- [ ] **Step 1: Write the failing tests** (horloge 2026-12-07 07:15) :
  - `it('reminds each expected trainee and the trainer of tomorrow')` : 2 séances le 08/12 (matin, après-midi, même formateur) → 2 e-mails stagiaires, 1 e-mail formateur listant les 2 séances ; corps sans « € » ni nom de l'autre stagiaire.
  - `it('never reminds twice the same day')`.
  - `it('skips planned sessions, cancelled séances, waitlisted, trashed or anonymised trainees')`.
  - `it('does nothing when day_before is off')`.
- [ ] **Step 2: Run** `--filter=DayBeforeRemindersTest` — Expected: FAIL.
- [ ] **Step 3: Implement** modèles, job, automatisme, déclarations.
- [ ] **Step 4: Run** le filtre, `--filter=MailTemplatesTest`, `--filter=ArchTest` — Expected: PASS.
- [ ] **Step 5: Commit** `feat(sessions): day-before reminder to trainees and trainers`.

### Task 4: A3 — Relance des signatures en attente (Core\Signatures)

**Files:**
- Create: `tms/database/migrations/2027_01_28_000002_add_signature_reminders.php` (`reminders_sent` int défaut 0, `last_sent_at` timestamptz nul)
- Modify: `tms/app/Modules/Core/Signatures/SignatureRequest.php` (fillable, casts, `$auditIgnore` + `last_sent_at`)
- Modify: `tms/app/Modules/Core/Signatures/SignatureMailTemplates.php` (modèle `signature_reminder`, `{lien}` requis, variables de `REQUEST` + `relance`)
- Create: `tms/app/Modules/Core/Signatures/SignatureReminders.php`
- Modify: provider de Core qui enregistre les signatures (`DailyAutomations::register('signature_reminders', …)`)
- Modify: `tms/tests/Feature/Core/MailTemplatesTest.php`
- Test: `tms/tests/Feature/Core/Signatures/SignatureRemindersTest.php`

**Interfaces:**
- Consumes: `AutomationSends::claim`, `AutomationSettings::signatureReminderDays|Max` (tâche 1) ; `Signatures::refusal()` (garde du module propriétaire).
- Produces: `SignatureReminders::run(CarbonImmutable $now): int` — demandes `pending`, non expirées, `signer_email` non vide, `refusal()` nul, `reminders_sent < max`, dernier envoi (`last_sent_at ?? created_at`) ≤ maintenant − N jours, et pas de `code_sent_at` dans les 24 dernières heures : `claim(SIGNATURE_REMINDER, requestId, (string) (reminders_sent + 1))`, nouveau jeton (sha256 seul gardé), `reminders_sent + 1`, `last_sent_at = now`, e-mail `signature_reminder` après commit (« ce lien remplace le précédent »). `expires_at` inchangé.

- [ ] **Step 1: Write the failing tests** :
  - `it('sends a new link after three days and kills the old one')` : demande créée le 01/12, horloge 04/12 → 1 e-mail ; ancien jeton → `Signatures::find` nul ; nouveau jeton (lu dans l'e-mail) → trouvé.
  - `it('stops after two reminders and never before the delay')`.
  - `it('leaves a signer who asked for a code in the last 24 hours')`.
  - `it('never reminds a request whose owner refuses it, expired, signed or without e-mail')`.
- [ ] **Step 2: Run** `--filter=SignatureRemindersTest` — Expected: FAIL.
- [ ] **Step 3: Implement** migration (`--database=pgsql_owner`), modèle, modèle d'e-mail, `SignatureReminders`, déclaration.
- [ ] **Step 4: Run** le filtre, `php artisan test tests/Feature/Core`, `--filter=MailTemplatesTest` — Expected: PASS.
- [ ] **Step 5: Commit** `feat(core): remind pending online signatures`.

### Task 5: Écran Administration › Automatismes

**Files:**
- Create: `tms/app/Modules/Core/Automation/AutomationSettingsController.php`, `RunAutomationsNow.php` (job `TenantAwareJob`)
- Create: `tms/routes/automation.php` (inclus comme les autres fichiers de routes) — `GET/PUT admin/automation`, `POST admin/automation/run`, middleware `auth`, `can:settings.manage`
- Create: `tms/resources/js/pages/admin/automation.tsx`
- Modify: menu d'administration (`resources/js/components/app-sidebar.tsx`, entrée « Automatismes »)
- Test: `tms/tests/Feature/Core/Automation/AutomationSettingsTest.php`

**Interfaces:**
- Consumes: `AutomationSettings::all|save`, `DailyAutomations::runAll` (tâche 1).
- Produces: page « Automatismes » : trois blocs (Convocations : délai en jours ; Rappel la veille : activé ; Relance des signatures : délai, nombre maximum), bouton « Exécuter maintenant » (met `RunAutomationsNow` en file, message « Les automatismes sont lancés : les e-mails partent dans quelques instants. »). Enregistrement journalisé (`automation.settings.updated`), message « Réglages des automatismes enregistrés. ».

- [ ] **Step 1: Write the failing tests** : `it('lets an administrator read and save the automation settings')` (valeurs relues, bornes refusées en français) ; `it('refuses the page to a user without settings.manage')` (403) ; `it('queues a run now')` (`Queue::fake`, `RunAutomationsNow` poussé avec le tenant).
- [ ] **Step 2: Run** `--filter=AutomationSettingsTest` — Expected: FAIL.
- [ ] **Step 3: Implement** contrôleur, routes, job, page, entrée de menu.
- [ ] **Step 4: Run** le filtre ; `docker compose run --rm vite sh -c "npm run lint && npm run types && npm run build"` — Expected: PASS.
- [ ] **Step 5: Commit** `feat(core): automation settings screen and run now`.

### Task 6: « À traiter »

**Files:**
- Create: `tms/app/Modules/Core/Todo/TodoSources.php`, `TodoSource.php`, `TodoController.php`
- Modify: `tms/routes/web.php` (`GET /a-traiter`, `auth`)
- Modify: `tms/app/Http/Middleware/HandleInertiaRequests.php` (prop différée `todo_total` via `Inertia::defer`)
- Modify: les providers déclarant les sources : `SessionsServiceProvider`, `DocumentsServiceProvider`, `CrmServiceProvider`, `AdmissionsServiceProvider`, `FinanceServiceProvider`
- Create: `tms/resources/js/pages/todo/index.tsx` ; Modify: `app-sidebar.tsx` (entrée « À traiter » sous Accueil, compteur)
- Test: `tms/tests/Feature/Core/Todo/TodoSourcesTest.php`

**Interfaces:**
- Produces:
  - `TodoSources::register(string $key, string $group, string $label, string $permission, Closure $count, Closure $link): void` ; `$count(User): int`, `$link(User): string`.
  - `TodoSources::for(User $user): list<array{key: string, group: string, label: string, count: int|null, url: string}>` (`count` nul = « Indisponible » si la source lève une exception) et `TodoSources::total(User): int`, en cache 60 s sous `tenant:<id>:todo:<userId>`.
  - Groupes (ordre d'affichage) : `Sessions`, `Documents`, `Commercial`, `Admissions`, `Finance`.
- Sources (chacune réutilise la requête de l'écran lié, avec le périmètre du droit) :
  - Sessions : « Sessions qui démarrent dans 7 jours avec des étapes à faire » (`StartingSoon` + `SessionChecklist::pending`, `sessions.sessions.view`) ; « Rattrapages d'émargement à confirmer » (`sessions.attendance.manage`, scope ≠ Trainer) ; « Demi-journées passées non validées » (`sessions.attendance.manage`).
  - Documents : « Convocations non envoyées » (émises, `email_status` nul ou `failed`, session démarrée dans moins de 7 jours ; `documents.view`) ; « Documents en attente de signature depuis plus de 3 jours ».
  - Commercial : « Devis envoyés sans réponse depuis plus de 15 jours » (`QuoteListQuery`) ; « Actions commerciales en retard » (lien « À faire », `TodoQuery`).
  - Admissions : « Candidatures à traiter » (étape initiale).
  - Finance : « Dossiers à facturer » ; « Factures échues non soldées » (`Balances::remainingSql`, `finance.invoices.view`).
  - Le constructeur retire une source sans requête fiable existante (spec § 6.2) et le note dans le ledger.

- [ ] **Step 1: Write the failing tests** : `it('lists the sources the user may see with their counts and links')` ; `it('counts within the user scope')` (utilisateur « Ses fiches » : seul son devis compte) ; `it('shows Indisponible for a failing source without breaking the page')` ; `it('caches the total per tenant and user')`.
- [ ] **Step 2: Run** `--filter=TodoSourcesTest` — Expected: FAIL.
- [ ] **Step 3: Implement** registre, contrôleur, prop différée, sources dans chaque provider, page et menu (lignes à zéro repliées sous « Rien à traiter », message « Rien à traiter pour le moment. » si tout est à zéro).
- [ ] **Step 4: Run** le filtre, `--filter=ArchTest`, front (lint, types, build) — Expected: PASS.
- [ ] **Step 5: Commit** `feat: « À traiter » page gathering what each module declares`.

### Task 7: Documentation et vérification d'ensemble

**Files:**
- Modify: `CLAUDE.md` (section « Déroulement automatique et À traiter » : socle, registres, règles d'unicité), `tms/docs/admin.md` (§ Automatismes), `tms/docs/sessions.md` (convocations et rappel), `tms/docs/data-dictionary.md` (`automation_sends`, colonnes de `signature_requests`), `tms/CHANGELOG.md` (« Non publié »).

- [ ] **Step 1:** Rédiger les sections (français, sans secret).
- [ ] **Step 2: Run** `docker compose exec app vendor/bin/pint --test`, `vendor/bin/phpstan analyse --memory-limit=1G`, `php artisan test` (suite complète, une seule à la fois), front (lint, types, build) — Expected: tout vert.
- [ ] **Step 3: Commit** `docs: automation and À traiter`.
