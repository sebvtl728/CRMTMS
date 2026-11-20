# Lot 1e : fidélisation, suivis et tableaux de bord - plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** une page « Tableaux de bord » (12 indicateurs du CDC, filtres, comparaisons, exports), le suivi formateur à 3 mois, la réactivation à 6 et 12 mois et le champ « Besoins futurs ».

**Architecture:** indicateurs calculés en direct par un registre (`Crm\Dashboard\Indicators`) qui réutilise les requêtes des listes ; Sessions fournit la rétention par un registre public du CRM et crée les rappels par une commande quotidienne qui appelle un service public du CRM (`Crm\Public\Activities`).

**Tech Stack:** Laravel 12 / PHP 8.4, Inertia React 19 TS, PostgreSQL 17 (FORCE RLS), Pest, Playwright, Gotenberg.

**Spec:** `docs/superpowers/specs/2026-11-20-1e-fidelisation-tableaux-de-bord-design.md`

## Global Constraints

- Français partout (interface, messages, exports) ; jours de Paris (`Europe/Paris`) pour toute période et toute échéance.
- Montants en centimes entiers (`brick/math`), jamais de flottant ; « CA signé » = règles de `DasRevenue` (jamais deux fois une opportunité).
- Toute requête sur une fiche, une opportunité, une action, un devis passe par `CrmVisibility` avec le **droit** de son domaine ; aucun test de nom de rôle dans le code métier.
- Sessions n'importe que `Crm\Public\*` et ne nomme aucune table du CRM ; Crm n'importe jamais Sessions (`ModuleBoundariesArchTest`).
- Nouvelle table métier : `tenant_id`, `BelongsToTenant`, `Rls::enable` ; listes métier = référentiels (`meta.*`), jamais un code en dur.
- Cache : clé préfixée par l'id du tenant ; TTL 60 s.
- Réglages dans `tenants.settings` : `crm.reactivation_months` (défaut `[6, 12]`), `crm.lead_stage_id` (défaut : étape de position 2, « Contacté » chez CAP FORMATION), `crm.retention_months` (défaut 24), `sessions.trainer_follow_up_months` (défaut 3, 0 = désactivé).
- Rappel : jamais deux fois la même échéance (`follow_up_reminders` unique `kind, subject_id, months`) ; jamais une échéance antérieure de plus de 30 jours au passage.
- Données personnelles : `future_needs` dans `$auditPersonal` (personne), effacé par l'anonymisation, exporté par l'export RGPD ; aucune donnée personnelle dans `follow_up_reminders`.
- Exports CSV `;` ; PDF par `PdfRenderer` (gabarit Blade, `{{ }}` seulement, CSP sans ressource externe).

## Review Focus

1. Commercial au périmètre « ses fiches » : chaque carte et chaque détail ne compte que ses opportunités, actions, devis (test par carte dans les tâches 7 et 8, via un helper commun).
2. Période sans aucune donnée et objectifs absents : cartes à 0 ou « - », jamais une division par zéro ni une erreur 500 (tâche 6).
3. Première exécution du passage quotidien sur un historique de formations anciennes : aucune rafale de rappels (fenêtre de 30 jours, tâche 3).
4. Client déjà en train de racheter (opportunité ouverte ou dossier plus récent) : pas de réactivation, et pas plus tard non plus (tâche 4).
5. Formateur sans compte, ou plusieurs formateurs à égalité d'heures : destinataire déterministe (tâche 3).

---

### Task 1: Réglages, référentiel « joint » et droits du formateur

**Files:**
- Create: `tms/app/Modules/Crm/Settings/CrmFollowUpSettings.php`, `tms/app/Modules/Crm/Settings/CrmSettingsController.php`, `tms/resources/js/pages/admin/crm-settings.tsx`
- Modify: `tms/app/Modules/Sessions/Settings/` (nouveau réglage dans l'écran `admin/sessions-settings.tsx` existant), `tms/app/Modules/Core/References/CapFormationReferenceSeeder.php` (`action_result` « Réussi » : `meta.reached = true`), écran des référentiels (case « Compte comme joint » pour la liste `action_result`), `tms/app/Modules/Crm/Permissions.php` (défauts Formateur), routes `routes/crm*.php`
- Test: `tms/tests/Feature/Crm/CrmSettingsTest.php`, `tms/tests/Feature/Sessions/TrainerFollowUpSettingTest.php`

**Interfaces:**
- Produces: `CrmFollowUpSettings::reactivationMonths(): list<int>`, `::leadStageId(): ?string`, `::retentionMonths(): int`, `::save(array $data): void` ; `Sessions\Settings\TrainerFollowUp::months(): int` ; `Crm\Public\FollowUpSettings::reactivationMonths(): list<int>` (lecture publique pour Sessions).

- [ ] **Step 1: tests** - `it('defaults to 6 and 12 months, 24 months of retention and the second stage as lead threshold')` ; `it('saves the delays, refuses a delay outside 1..36 months or a stage of another list')` (erreurs `reactivation_months.0`, `lead_stage_id` en français) ; `it('lets only settings.manage change them')` (403) ; `it('defaults the trainer follow-up to 3 months, 0 disables it')` ; `it('grants the Formateur role crm.activities.view and crm.activities.update on its own records once')` (après `tms:permissions:sync`, scope `own`) ; `it('marks « Réussi » as reached in the CAP FORMATION references')`.
- [ ] **Step 2:** lancer, constater l'échec. `docker compose exec app php artisan test --filter=CrmSettingsTest` (FAIL : classe absente).
- [ ] **Step 3:** implémenter ; écriture sous verrou de la ligne du tenant (comme `AbandonRuleController`), journal d'audit `crm.settings.updated` ; page Administration › CRM (délais séparés par des virgules, étape seuil, fenêtre de rétention).
- [ ] **Step 4:** tests verts, `pint`, `phpstan`, `npm run types`.
- [ ] **Step 5:** commit `feat(crm): follow-up and dashboard settings, reached results, trainer action rights`.

### Task 2: Service public de création d'action et « Besoins futurs »

**Files:**
- Create: `tms/app/Modules/Crm/Public/Activities.php`, migration `add_future_needs_to_fiches` (organisations et people : `future_needs text null`, `future_needs_updated_at timestamptz null`, `future_needs_updated_by uuid null`)
- Modify: modèles `Organisation`, `Person` (`$auditPersonal` pour Person), requêtes de mise à jour des fiches, `Gdpr/Anonymiser`, export RGPD, pages `crm/organisations/show.tsx` et `crm/people/show.tsx` (carte « Besoins futurs », modifiable avec `crm.fiches.update`)
- Test: `tms/tests/Feature/Crm/PublicActivitiesTest.php`, `tms/tests/Feature/Crm/FutureNeedsTest.php`

**Interfaces:**
- Produces: `Crm\Public\Activities::planCall(string $ficheType /* organisation|person */, string $ficheId, string $userId, string $subject, CarbonImmutable $dueAt): ?string` - id de l'action créée, `null` si la fiche est à la corbeille ou anonymisée ; type = premier type d'action `meta.planning = call` ; passe par `ActivityRecorder::create` (journal, `ActivityChanged`).

- [ ] **Step 1: tests** - `it('plans a call on a live fiche for the given user, due at the given time, visible in his « À faire »')` ; `it('plans nothing on a trashed or anonymised fiche')` ; `it('saves future needs with their date and author, 2000 characters at most')` ; `it('erases future needs of an anonymised person and exports them')` ; `it('never writes future needs of a person in clear in the audit log')`.
- [ ] **Step 2:** échec constaté.
- [ ] **Step 3:** implémenter ; aucune donnée lue hors périmètre (le service ne vérifie pas les droits de l'appelant : il est appelé par une tâche système).
- [ ] **Step 4:** verts. **Step 5:** commit `feat(crm): public call planning and future needs on fiches`.

### Task 3: Suivi formateur à 3 mois

**Files:**
- Create: migration `create_follow_up_reminders_table` (`id`, `tenant_id`, `kind` `trainer|reactivation` (CHECK), `subject_id uuid`, `months smallint`, `activity_id uuid null`, `outcome` `created|skipped` (CHECK), `reason varchar(60) null`, `processed_at`, unique `(tenant_id, kind, subject_id, months)`, `Rls::enable`) ; `tms/app/Modules/Sessions/FollowUps/FollowUpReminder.php`, `TrainerFollowUps.php`, `FollowUpsCommand.php` (`tms:sessions:follow-ups`, planifiée 6 h 30 Paris, `ForEachTenant`)
- Test: `tms/tests/Feature/Sessions/TrainerFollowUpsTest.php`

**Interfaces:**
- Consumes: `Crm\Public\Activities::planCall`, `TrainerFollowUp::months()`.
- Produces: `TrainerFollowUps::run(CarbonImmutable $today): int` (actions créées) ; `FollowUpReminder` (modèle, `BelongsToTenant`).

- [ ] **Step 1: tests** (helpers `l2Dossier`, `l2Session`, `l2Enrol`, `plModule`, `plSeance`) :
  - `it('plans one call per trainee three months after the end, to the trainer with an account who taught the most hours')` - fin 2026-08-20 ; passage le 2026-11-20 : une action « Suivi à 3 mois : Léa Durand, HACCP (S-…) », due le 2026-11-20 9 h Paris, responsable = formateur à 14 h (l'autre à 7 h) ; second passage : rien de plus.
  - `it('uses the trainee leave date when she left early')` (`left_on`).
  - `it('falls back on the session responsible, then the dossier responsible, when no trainer has an account')`.
  - `it('breaks a tie of hours by the trainer name')`.
  - `it('skips cancelled sessions, abandoned or cancelled enrolments and anonymised people')` (ligne `skipped`).
  - `it('never plans a due date older than 30 days on its first run')` - fin 2025-01-10 : aucune action, aucune ligne.
  - `it('catches up a due date missed for a few days')` - échéance il y a 5 jours : action due ce jour-là.
  - `it('is disabled at 0 months')`.
- [ ] **Step 2:** échec. **Step 3:** implémenter (heures = somme `duration_minutes` des séances non annulées par `trainer_id` ; égalité : nom du formateur via `Crm\Public\Fiches::personLabels`). **Step 4:** verts.
- [ ] **Step 5:** commit `feat(sessions): trainer follow-up call three months after the training`.

### Task 4: Réactivation à 6 et 12 mois

**Files:**
- Create: `tms/app/Modules/Sessions/FollowUps/Reactivations.php` (appelée par la même commande)
- Modify: `Crm\Public\Fiches` (`responsibleUser(string $type, string $id): ?string`, `hasOpenOpportunity(string $type, string $id): bool`)
- Test: `tms/tests/Feature/Sessions/ReactivationsTest.php`

**Interfaces:**
- Consumes: `planCall`, `Crm\Public\FollowUpSettings::reactivationMonths()`, `FollowUpReminder`.
- Produces: `Reactivations::run(CarbonImmutable $today): int`.

- [ ] **Step 1: tests** :
  - `it('plans a reactivation call on the dossier account at 6 and 12 months, to the fiche responsible')` - sujet « Réactivation : HACCP terminée il y a 6 mois (D-…) ».
  - `it('targets the particulier of an individual dossier, never the employed trainees')`.
  - `it('falls back on the dossier responsible, else plans nothing')`.
  - `it('skips a client with an open opportunity or a newer live dossier, and never plans it later')` (ligne `skipped`, raison `rachat_en_cours`).
  - `it('skips cancelled dossiers and trashed or anonymised fiches')`.
  - `it('uses the last session of the dossier as its end')`.
- [ ] **Steps 2-4:** échec, implémentation, verts. **Step 5:** commit `feat(sessions): reactivation calls 6 and 12 months after a dossier`.

### Task 5: Fiche client « Formations suivies »

**Files:**
- Modify: `Sessions\Enrolments\EnrolmentRows::personBlock`, `Sessions\Dossiers\DossierRows::personBlock` et bloc entreprise (date de fin, colonne « Satisfaction » à « - »)
- Test: `tms/tests/Feature/Sessions/FicheTrainingsBlockTest.php`

- [ ] **Step 1:** `it('shows the end date and an empty satisfaction column on the trainings followed')`. **Steps 2-4.** **Step 5:** commit `feat(sessions): trainings followed show their end and satisfaction`.

### Task 6: Socle des tableaux de bord

**Files:**
- Create: `tms/app/Modules/Crm/Dashboard/DashboardFilters.php`, `Indicator.php` (interface), `IndicatorValue.php`, `Indicators.php` (registre + cache), `DashboardController.php` (`GET /crm/dashboards`, `?tab=week|month`), `tms/app/Modules/Crm/Public/DashboardIndicators.php` ; `tms/resources/js/pages/crm/dashboards.tsx`, `tms/resources/js/components/crm/indicator-card.tsx` ; entrée de menu « Tableaux de bord » (droit `crm.any-view`)
- Test: `tms/tests/Feature/Crm/Dashboard/DashboardFiltersTest.php`, `DashboardPageTest.php`

**Interfaces:**
- Produces:
  - `DashboardFilters::from(array $input, CarbonImmutable $now, string $tab): self` - `preset` parmi `this_week, last_week, this_month, last_month, this_quarter, this_year, custom` (défaut `this_week` pour `week`, `this_month` pour `month`), `from`, `to` (`Y-m-d`), `das`, `target` (`organisation|person`), `responsible` ; `previous(): self` (même longueur, juste avant) ; `toListQuery(): array` (filtres de `OpportunityListQuery::filters`).
  - `interface Indicator { key(): string; tab(): string; label(): string; formula(): string; permission(): string; value(DashboardFilters $f, User $u): IndicatorValue; detailUrl(DashboardFilters $f): ?string; }`
  - `IndicatorValue(int|float|null $value, string $unit /* count|cents|percent|days */, ?array $breakdown = null, ?int $targetCents = null)`.
  - `Indicators::register(Indicator $i)`, `Indicators::for(string $tab, DashboardFilters $f, User $u): list<array>` (valeur, précédente, écart, formule, lien ; carte absente sans le droit ; cache 60 s `tenant:<id>:dashboard:<user>:<hash filtres>:<clé>`).
  - `Crm\Public\DashboardIndicators::register(Indicator $i)` (alias public pour Sessions).
- [ ] **Step 1: tests** - presets (semaine du lundi au dimanche de Paris ; trimestre ; dates libres `from > to` refusées) ; `previous()` ; page : onglets, filtres renvoyés, carte absente sans droit, **aucune donnée → valeurs 0 / null sans erreur** ; cache préfixé par le tenant.
- [ ] **Steps 2-4.** **Step 5:** commit `feat(crm): dashboards page, filters and indicator registry`.

### Task 7: Indicateurs de la semaine

**Files:**
- Create: `tms/app/Modules/Crm/Dashboard/Indicators/{CallsAndMeetings,Leads,WeightedPipeline,ClosingOpportunities,QuotesSentAccepted}.php` ; enregistrés dans `CrmServiceProvider::boot`
- Test: `tms/tests/Feature/Crm/Dashboard/WeekIndicatorsTest.php` (+ helper `dashValue($key, $filters, $user)`)

- [ ] **Step 1: tests** (un par indicateur, valeurs exactes) : appels faits, RDV fixés (`meta.planning` meeting par `created_at`), taux de réponse (`meta.reached`, « - » sans résultat joint) ; leads = fiches distinctes dont une opportunité atteint pour la première fois l'étape seuil dans la période (`opportunity_stage_changes`, position ≥ seuil) ; pipeline pondéré et opportunités à clôturer (ouvertes, `expected_close_on` dans la période) ; devis envoyés et acceptés (`QuoteListQuery`) ; **chaque carte limitée au périmètre « ses fiches » d'un commercial** ; le lien de détail ouvre la liste filtrée qui redonne le même nombre.
- [ ] **Steps 2-4.** **Step 5:** commit `feat(crm): weekly dashboard indicators`.

### Task 8: Indicateurs du mois / direction

**Files:**
- Create: `tms/app/Modules/Crm/Dashboard/Indicators/{Conversion,Forecast,SalesCycle,TrainingsSold,TargetSplit,LeadOrigins,LossReasons,DasRevenueCard}.php`
- Test: `tms/tests/Feature/Crm/Dashboard/MonthIndicatorsTest.php`

- [ ] **Step 1: tests** : conversion = gagnées / créées (créées dans la période ; 0 créée → « - ») ; prévisionnel ; cycle (moyenne et médiane en jours entiers) ; formations vendues (ligne de devis accepté, sinon formation de l'opportunité ; répartition par formation, titres par `Trainings`) ; cible (CA et nombre par entreprise / particulier) ; origines (« Non renseignée » à part) ; motifs de perte (nombre et montant) ; CA par DAS = `DasRevenue::for` sur la période (mois, trimestre, année ; période libre : somme par jours avec la même règle) et objectifs `RevenueTargets` ; périmètre « ses fiches » ; la carte CA n'apparaît pas sans `crm.revenue.view`.
- [ ] **Steps 2-4.** **Step 5:** commit `feat(crm): monthly dashboard indicators`.

### Task 9: Rétention et réachat (Sessions)

**Files:**
- Create: `tms/app/Modules/Sessions/Dashboard/Retention.php` (implémente `Indicator`, enregistré par `SessionsServiceProvider::boot` via `Crm\Public\DashboardIndicators`)
- Test: `tms/tests/Feature/Sessions/RetentionIndicatorTest.php`

- [ ] **Step 1: tests** : clients ayant ≥ 2 dossiers non annulés dont la formation a commencé dans la fenêtre glissante (24 mois) / clients ayant ≥ 1 ; réachat = deuxième dossier commencé dans la période ; filtre commercial = responsable de la fiche client (`Crm\Public\Fiches`) ; périmètre `sessions.dossiers.view` ; aucun client → « - ».
- [ ] **Steps 2-4.** **Step 5:** commit `feat(sessions): retention and repurchase dashboard indicator`.

### Task 10: Exports, accueil, documentation, e2e

**Files:**
- Create: `tms/app/Modules/Crm/Dashboard/DashboardExportController.php` (`GET /crm/dashboards/export.csv`, `GET /crm/dashboards/export.pdf`, droit `crm.export`, journal `crm.dashboard.exported`), `tms/resources/views/documents/crm-dashboard.blade.php`, `tms/tests/e2e/dashboards.spec.ts`
- Modify: `Dashboard\CommercialSummary` + page d'accueil (lien et carte « Objectifs » si `crm.revenue.view`), `tms/docs/crm.md` (§ tableaux de bord, rappels, besoins futurs), `tms/docs/sessions.md` (suivi formateur), `tms/docs/admin.md` (réglages), `tms/docs/data-dictionary.md`, `tms/CHANGELOG.md`, `CLAUDE.md` (section « Lot 1e »)
- Test: `tms/tests/Feature/Crm/Dashboard/DashboardExportTest.php`

- [ ] **Step 1: tests** : CSV `;` (libellé, formule, période, valeur, précédente, objectif ; montants « 1 470,00 € ») ; PDF rendu par Gotenberg (HTML envoyé contient les filtres) ; 403 sans `crm.export` ; journalisé ; accueil : carte Objectifs présente avec le droit, absente sans.
- [ ] **Step 2-4** ; e2e : ouvrir Tableaux de bord, changer d'onglet et de période, cliquer un détail.
- [ ] **Step 5:** commit `feat(crm): dashboard exports, home objectives card, docs`.
