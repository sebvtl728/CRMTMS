# Planning individuel d'un stagiaire — plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** dispenser un stagiaire de séances ou d'un module, lui donner des horaires propres, lui ajouter des séances d'une autre session, avec ses heures prévues recalculées et tous les lecteurs (présences, émargement, heures, facturation, documents, rappels, suivi) alignés.

**Architecture :** une table d'écarts `enrolment_adjustments` lue par un seul service `Sessions\Planning\IndividualPlanning` (attendus, horaires, séances d'un stagiaire, heures prévues) et écrite par `IndividualPlanningWriter`. `Attendances::expected` délègue au service ; chaque lecteur existant passe par lui. Les heures prévues calculées sont écrites dans `enrolments.planned_hours` (lecteurs inchangés), la base dans `planned_hours_base`.

**Tech Stack :** Laravel 12, PostgreSQL 17 (RLS), Pest, Inertia React 19 / TypeScript, shadcn.

**Spec :** `docs/superpowers/specs/2026-11-24-planning-individuel-stagiaire-design.md` (§ 6 à § 8 font autorité).

## Global Constraints

- Nouvelle table : `tenant_id uuid not null`, `Rls::enable`, modèle `BelongsToTenant` + `Auditable` + `HasUuids` ; migration lancée via `pgsql_owner`.
- Minutes entières, jamais de flottant ; jours de Paris ; heures murales `H:i:s` comme `seances`.
- Interface et messages en français ; refus affichés = `UserFacingHttpException` (409 / 403) ou `ValidationException`.
- `reason` des écarts dans `$auditPersonal` ; aucune donnée personnelle dans le journal.
- Ordre des verrous : session(s) (partagé, par id croissant) → inscription (FOR UPDATE) → séance(s) (par id croissant) → présences.
- Mode « séances » / « période » lu sur le **module** (`session_modules.dating_mode`).
- Droit d'écriture : `sessions.sessions.update` sur la session du stagiaire (+ `sessions.sessions.view` sur la session d'accueil pour un ajout).
- Sessions seul ; aucune table d'un autre module nommée ; `ModuleBoundariesArchTest` vert.
- Tests depuis le worktree (une seule suite Pest à la fois sur la machine) :
  `M=/Users/sebastienveitl/Desktop/CRM/tms; docker compose -f $M/compose.yaml run --rm --no-deps -v "$PWD:/var/www/html" -v "$M/vendor:/var/www/html/vendor" app php artisan test <fichier|--filter=…>` (lancé depuis `tms/` du worktree). Migration du test : automatique (`RefreshDatabase`).

## Review Focus

1. Horaires propres qui ne touchent qu'une demi-journée (séance 9 h–17 h, lui 14 h–17 h) : il n'est attendu qu'au créneau de l'après-midi ; présent = 180 min. → test Task 5.
2. Stagiaire sorti (`left_on`) avec une séance ajoutée après sa sortie : jamais attendu, l'ajout est refusé. → test Task 8.
3. Heures prévues modifiées à la main alors qu'il a des écarts : la base change, le calcul se refait (base 30 h − 7 h = 23 h). → test Task 3.
4. Séance à horaires propres annulée puis déplacée : heures prévues recalculées, présence à 0 / à revoir à **sa** durée. → test Task 3.
5. Module dispensé puis nouvelle séance créée dans ce module : il n'y est pas attendu, aucune convocation / rappel ne la liste. → test Task 1 et Task 6.

---

## Livraison 1 — dispense et horaires individuels

### Task 1 : table, modèle et attendus (dispense)

**Files :**
- Create : `tms/database/migrations/2027_01_31_000001_create_enrolment_adjustments.php`
- Create : `tms/app/Modules/Sessions/Planning/EnrolmentAdjustment.php`
- Create : `tms/app/Modules/Sessions/Planning/IndividualPlanning.php`
- Modify : `tms/app/Modules/Sessions/Attendance/Attendances.php` (`expected`, `assertExpected`)
- Modify : `tms/app/Modules/Sessions/SessionsServiceProvider.php` (alias morph `enrolment_adjustment`)
- Test : `tms/tests/Feature/Sessions/IndividualPlanningTest.php`

**Interfaces :**
- Produces : `EnrolmentAdjustment` (consts `EXEMPT_SEANCE='exempt_seance'`, `EXEMPT_MODULE='exempt_module'`, `ADDED_SEANCE='added_seance'`, `OWN_HOURS='own_hours'` ; colonnes spec § 5 ; `duration_minutes` générée, nulle sans horaires) ; colonne `enrolments.planned_hours_base numeric(6,2) null` (même migration).
- Produces : `IndividualPlanning::expectedAt(Seance $s): Builder<Enrolment>` ; `IndividualPlanning::exempted(string $enrolmentId, Seance $s): bool`.
- Attendances : `expected()` = `IndividualPlanning::expectedAt()` ; `assertExpected(Enrolment, CarbonImmutable, ?Seance $seance = null)` lève `['enrolment_id' => 'Ce stagiaire est dispensé de cette séance.']` quand `$seance` est donné et dispensé (appelants `record`/`fromSigning` passent la séance).

Migration : CHECK par kind (`exempt_module` ⇔ `session_module_id` non nul et `seance_id` nul ; autres ⇔ `seance_id` non nul et `session_module_id` nul ; `own_hours` ⇔ horaires non nuls ; `exempt_*` ⇔ horaires nuls) ; `CHECK (ends_at > starts_at)` ; index uniques partiels (`enrolment_id, seance_id`) where `seance_id is not null` et (`enrolment_id, session_module_id`) where `kind='exempt_module'` ; FK `enrolment_id` cascade, `seance_id` → `seances`, `session_module_id` → `session_modules` ; index `seance_id`.

- [ ] **Step 1 : tests en échec** (helpers `plSetUp`, `plSession`, `plModule`, `plSeance`, `atEnrol`) :
  - `it('leaves a trainee exempted from a séance out of its expected trainees')` : 2 inscrits, écart `exempt_seance` créé directement par le modèle sur Léa ⇒ `Attendances::expected($s)->pluck('id')` = [Marc] ; une autre séance l'attend toujours.
  - `it('leaves a trainee exempted from a module out of every séance of it, later ones included')` : `exempt_module` puis `plSeance` d'une nouvelle date ⇒ non attendue.
  - `it('refuses the attendance of an exempted trainee')` : `record()` ⇒ `ValidationException` avec le message exact.
  - `it('isolates the adjustments per tenant')` : écart créé dans un autre organisme invisible (`inTenant`).
- [ ] **Step 2 : lancer** `…artisan test tests/Feature/Sessions/IndividualPlanningTest.php` → échec (table absente).
- [ ] **Step 3 : implémenter** migration, modèle, `expectedAt` (requête actuelle d'`expected` + `whereNotExists` sur les écarts `exempt_seance` de la séance et `exempt_module` de son module), `exempted`, délégation et message.
- [ ] **Step 4 : relancer** le fichier + `tests/Feature/Sessions/AttendanceTest.php` + `tests/Arch` → verts.
- [ ] **Step 5 : commit** `feat(sessions): exempt a trainee from séances or a module`.

### Task 2 : horaires propres dans les présences

**Files :**
- Modify : `IndividualPlanning.php`, `Attendances.php`
- Test : `IndividualPlanningTest.php`

**Interfaces :**
- Produces : `IndividualPlanning::times(Seance $s, list<string> $enrolmentIds): array<string, array{start: string, end: string, break: int, minutes: int}>` (une requête ; sans écart : horaires de la séance) ; `IndividualPlanning::minutesAt(string $enrolmentId, Seance $s): int`.
- `Attendances::record` : présent = `minutesAt` ; partiel `0 < m < minutesAt` (message existant avec sa durée) ; `allPresent` et `fromSigning` idem ; `review()` recalcule présent / plafonne partiel à la durée de chaque stagiaire.

- [ ] **Step 1 : tests en échec** :
  - `it('gives a present trainee with own hours his own duration')` : séance 09:00–17:00 pause 60, écart `own_hours` 14:00–17:00 pause 0 ⇒ `record(present)->minutes` = 180 ; Marc 420.
  - `it('bounds a partial attendance by his own duration')` : partiel 180 ⇒ message « … (3 h) exclue. ».
  - `it('marks present at his own duration with « Tous présents »')`.
  - `it('reviews a moved séance at his own duration')` : séance déplacée 08:00–16:00 ⇒ présence de Léa reste 180 (horaires propres inchangés, bornés au besoin — voir Task 4), `needs_review` vrai.
- [ ] **Step 2 : lancer** → échec.
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : relancer** fichier + `AttendanceTest.php` + `Signing/` → verts.
- [ ] **Step 5 : commit** `feat(sessions): own hours of a trainee at a séance`.

### Task 3 : heures prévues calculées

**Files :**
- Modify : `IndividualPlanning.php`, `Enrolments/Enrolments.php` (`update`), `SessionsServiceProvider.php` (crochet `Seances::onScheduleChange('sessions.individual_planning', …)`), `Planning/Seances.php` (suppression : appelle les crochets avec `SeanceChanged::DELETED = 'deleted'`, `Attendances::review` l'ignore)
- Test : `IndividualPlanningTest.php`

**Interfaces :**
- Produces : `IndividualPlanning::plannedMinutes(Enrolment $e): int` (règle spec § 6 : base, plafond par module, ajouts) ; `IndividualPlanning::refresh(Enrolment $e): void` (sans écart vivant : `planned_hours` ← base, base ← null ; sinon base ← `planned_hours ?? session.planned_hours` si nulle, puis `planned_hours` ← `plannedMinutes` en heures `numeric(6,2)`) ; `IndividualPlanning::refreshForSeance(Seance $s): void` (toutes les inscriptions ayant un écart sur la séance ou son module).
- `Enrolments::update` : si `planned_hours_base` non nulle, une valeur saisie écrit la base puis `refresh`.

- [ ] **Step 1 : tests en échec** (session 21 h, module unique `planned_minutes` 1260) :
  - `it('subtracts an exempted séance from the planned hours')` : séance 3 h 30 dispensée ⇒ `planned_hours` = '17.50', `planned_hours_base` = '21.00'.
  - `it('subtracts the planned hours of an exempted module')` : inscrit avec `planned_hours` '31' et un 2e module `atIntervalModule(600)` dispensé ⇒ '21.00'.
  - `it('never subtracts more than the planned hours of a module')` : module 1 (1260 min) avec 4 séances de 7 h (28 h) toutes dispensées une à une ⇒ − 21 h seulement : '0.00'.
  - `it('starts from the hours typed by hand')` : `planned_hours` '30' ⇒ dispense 3 h 30 ⇒ '26.50' ; retrait ⇒ '30.00', base nulle.
  - `it('writes the base when the planned hours are typed with adjustments')` (Review Focus 3).
  - `it('recomputes when a séance with own hours is cancelled or moved')` (Review Focus 4) : annulée ⇒ la séance ne retire plus rien, présence 0 `needs_review`.
  - `it('keeps the base when the session hours change')` : `SessionModules` change le total ⇒ base et `planned_hours` inchangés.
- [ ] **Step 2 : lancer** → échec.
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : relancer** fichier + `EnrolmentsTest.php` + `SeancesTest.php` + `SessionModulesTest.php` → verts.
- [ ] **Step 5 : commit** `feat(sessions): planned hours computed from the individual planning`.

### Task 4 : écritures et règles

**Files :**
- Create : `tms/app/Modules/Sessions/Planning/IndividualPlanningWriter.php`
- Test : `tms/tests/Feature/Sessions/IndividualPlanningWriterTest.php`

**Interfaces :**
- Produces (toutes `void`, transaction, verrous Global Constraints, `refresh` à la fin) : `exemptSeances(Enrolment $e, list<string> $seanceIds, ?string $reason)`, `exemptModule(Enrolment $e, string $moduleId, ?string $reason)`, `setOwnHours(Enrolment $e, string $seanceId, string $start, string $end, int $break)` (remplace un `own_hours` existant ; sur une séance ajoutée, écrit les horaires sur la ligne `added_seance`), `clearOwnHours(Enrolment $e, string $seanceId)`, `remove(EnrolmentAdjustment $a)`.
- Messages (constantes publiques de la classe) : `HAS_ATTENDANCE` = « Une présence est saisie sur cette séance pour ce stagiaire : effacez-la d'abord. » (409) ; `HAS_MONTHLY_HOURS` = « Des heures sont saisies sur ce module pour ce stagiaire : effacez-les d'abord. » (409) ; `NOT_HIS` = « Cette séance n'est pas dans le planning de ce stagiaire. » (422) ; `ALREADY_MODULE` = « Ce stagiaire est déjà dispensé de tout le module. » (422) ; `OUT_OF_SEANCE` = « Ses horaires doivent rester dans ceux de la séance. » (422) ; `NOT_LIVE` = « Seul un stagiaire inscrit ou confirmé a un planning individuel. » (422) ; séance annulée = `Attendances::CANCELLED_SEANCE` (409) ; mois clôturé = `Closures::assertOpen` (409).
- Audit : une entrée `sessions.enrolment.planning_changed` par écriture (journal par `Auditable` du modèle suffit si c'est la convention ; motif en `[donnée personnelle]`).

- [ ] **Step 1 : tests en échec** : un test par message ci-dessus (valeurs exactes) ; dispense d'une séance avec présence ⇒ 409 et rien écrit ; dispense de module avec heures mensuelles ⇒ 409 ; horaires propres sur séance saisie ⇒ présence `needs_review` à sa durée ; mois clôturé ⇒ 409 ; `remove` du dernier écart ⇒ heures revenues à la base ; inscrit sorti : dispense d'une séance avant `left_on` acceptée, après refusée (`NOT_HIS`) ; journal sans motif en clair.
- [ ] **Step 2 : lancer** → échec.
- [ ] **Step 3 : implémenter.**
- [ ] **Step 4 : relancer** → vert.
- [ ] **Step 5 : commit** `feat(sessions): write the individual planning of a trainee`.

### Task 5 : émargement

**Files :**
- Modify : `Attendance/Signing/SlotSeances.php` (`expected`), `Attendance/Signing/SlotValidation.php` (`writeAttendance`), `Attendance/Signing/SlotRows.php` et noms publics si ils relisent les attendus
- Test : `tms/tests/Feature/Sessions/Signing/IndividualSigningTest.php` (helpers `Support/signing.php`)

**Interfaces :**
- `SlotSeances::expected(SignSlot): Collection<Enrolment>` = union, sur les séances du créneau, des `expectedAt` dont **ses** horaires (`IndividualPlanning::times`) touchent la demi-journée (`Halves::of(start, end)`).
- `writeAttendance` : par séance, pour chaque stagiaire de `expectedAt` : demi-journées = `Halves::of` de ses horaires, minutes = `Halves::minutes(sa durée, son début, sa fin, …)`.

- [ ] **Step 1 : tests en échec** : Review Focus 1 (attendu seulement l'après-midi, présent 180 après validation des deux créneaux) ; dispensé absent des attendus et des noms publics ; signature d'un dispensé refusée (404/422 existant de la page publique).
- [ ] **Step 2 : lancer** → échec. **Step 3 : implémenter.** **Step 4 : relancer** `tests/Feature/Sessions/Signing` → vert.
- [ ] **Step 5 : commit** `feat(sessions): online signing follows the individual planning`.

### Task 6 : lecteurs (grille, heures, facturation, documents, rappels, suivi)

**Files :**
- Modify : `IndividualPlanning.php` (+ `seancesOf`), `Attendance/AttendanceRows.php`, `Public/RealisedHours.php`, `Public/Billing.php` (`hours`), `Attendance/MonthlyHours.php` (`assertExpected`), `Planning/TraineePlanning.php`, `Attendance/TraineeFollowUp.php`, `Public/Paperwork.php` (`enrolment`), `Automation/DayBeforeReminders.php`
- Test : `tms/tests/Feature/Sessions/IndividualPlanningReadersTest.php`

**Interfaces :**
- Produces : `IndividualPlanning::seancesOf(Collection<Enrolment> $es, ?string $from = null, ?string $to = null): array<string, list<PlannedSeance>>` ; `final readonly class PlannedSeance { Seance $seance; string $start; string $end; int $minutes; bool $added; }` (`Planning/PlannedSeance.php`) ; séances vivantes non annulées, entre entrée et sortie, non dispensées, ses horaires ; nombre fixe de requêtes.
- `MonthlyHours::assertExpected` : module dispensé ⇒ `['enrolment_id' => 'Ce stagiaire est dispensé de ce module.']`.
- `RealisedHours` : `by_module.planned` = sa part (0 si dispensé, réduite selon § 6) ; `Billing::hours` : `absent_minutes` sur sa durée, séances dispensées ignorées.

- [ ] **Step 1 : tests en échec** : grille et compteur « attendus » du mois sans le dispensé ; `RealisedHours::forEnrolment` module dispensé `planned` 0 ; `Billing::hours` absence d'un stagiaire 14 h–17 h ⇒ `absent_minutes` 180 ; heures mensuelles refusées sur module dispensé ; `TraineePlanning::details` et `Paperwork::enrolment()['schedule']` sans séance dispensée, avec ses horaires ; rappel de la veille avec « 14:00–17:00 » pour lui ; Review Focus 5 (nouvelle séance d'un module dispensé absente du planning, de `schedule`, du rappel).
- [ ] **Step 2 : lancer** → échec. **Step 3 : implémenter.**
- [ ] **Step 4 : relancer** fichier + `AttendanceTest`, `RealisedHoursTest`, `BillingSurfaceTest`, `MonthlyHoursTest`, `PaperworkTest`, `Automation/`, `AdmissionFollowUpTest`, `tests/Feature/Finance` → verts.
- [ ] **Step 5 : commit** `feat(sessions): every reader follows the individual planning`.

### Task 7 : écran, routes, RGPD, documentation (L1)

**Files :**
- Create : `tms/app/Modules/Sessions/Planning/IndividualPlanningController.php`, `tms/resources/js/components/sessions/individual-planning.tsx`
- Modify : `tms/routes/sessions.php` (groupe `enrolments`), `resources/js/components/sessions/enrolment-list.tsx`, `resources/js/components/admissions/training-follow-up.tsx`, `Public/Admission.php` (`followUp` : `planning_url`, droit d'écriture), `Gdpr/EnrolmentAnonymisation.php`, `Gdpr/PersonSessionsExport.php`, `tms/docs/sessions.md`, `tms/docs/data-dictionary.md`, `tms/CHANGELOG.md`
- Test : `tms/tests/Feature/Sessions/IndividualPlanningHttpTest.php`

**Interfaces :**
- Routes (`can:sessions.sessions.update`, binding `{enrolment}` existant, 404 hors périmètre) :
  `GET enrolments/{enrolment}/planning` → JSON `{base, planned, exempted, added, modules: [{id, title, mode, exempted, adjustment_id}], seances: [{id, date, start, end, own_start, own_end, module, session_label, kind: 'normal'|'exempted'|'added', adjustment_id, has_attendance, closed}]}` ;
  `POST …/planning/exemptions` `{seance_ids?: string[], module_id?: string, reason?: string}` ;
  `PUT …/planning/seances/{seanceId}/hours` `{starts_at, ends_at, break_minutes}` ; `DELETE …/planning/seances/{seanceId}/hours` ;
  `DELETE …/planning/adjustments/{adjustmentId}`.
  Réponses : `back()` Inertia sauf GET (JSON).
- Panneau : spec § 9 ; ouvert par « Planning individuel » (liste des inscrits, menu de la ligne) et dans le suivi de formation ; `router.reload` après écriture.
- RGPD : `reason` effacé par l'anonymisation ; section `planning_individuel` dans l'export (`date`, `type`, horaires, sans motif).

- [ ] **Step 1 : tests HTTP en échec** : GET payload exact pour un cas (dispense + horaires) ; POST sans droit ⇒ 403 ; inscription hors périmètre ⇒ 404 ; anonymisation efface `reason` ; export contient la section.
- [ ] **Step 2 : lancer** → échec. **Step 3 : implémenter** contrôleur, routes, panneau, RGPD.
- [ ] **Step 4 : vérifier** fichier Pest vert ; `docker compose run --rm vite sh -c "npm run lint && npm run types && npm run build"` vert ; contrôle visuel dans le navigateur (C-2026-0003, session S-2026-0004 : dispense d'une séance puis horaires propres).
- [ ] **Step 5 : docs** (`sessions.md` § « Planning individuel », `data-dictionary.md` table + colonne, `CHANGELOG.md`) et **commit** `feat(sessions): individual planning panel`.

## Livraison 2 — séances ajoutées d'une autre session

### Task 8 : ajout et attendus croisés

**Files :**
- Modify : `IndividualPlanning.php` (`expectedAt` : + inscriptions d'autres sessions ayant la séance en `added_seance`, mêmes règles d'état / entrée / sortie), `IndividualPlanningWriter.php` (+ `addSeances`), `Attendances.php` (`enrolmentOf` accepte une inscription accueillie)
- Test : `tms/tests/Feature/Sessions/IndividualPlanningAddedTest.php`

**Interfaces :**
- Produces : `IndividualPlanningWriter::addSeances(Enrolment $e, list<string> $seanceIds, ?string $reason): void` ; `IndividualPlanning::guestsAt(Seance $s): Collection<Enrolment>` (accueillis, avec `dossier_id`).
- Messages : `OWN_SESSION` = « Cette séance est déjà dans sa session : rien à ajouter. » ; `ALREADY_PLANNED` = « Cette séance est déjà dans son planning. » ; `OUT_OF_DATES` = « Cette séance tombe hors de ses dates d'entrée et de sortie. » ; `HOST_UNAVAILABLE` = « Cette session est annulée ou à la corbeille. » ; `INTERVAL` = `Seances::INTERVAL` (module « période ») ; séance annulée / mois clôturé de la session d'accueil (409).

- [ ] **Step 1 : tests en échec** : ajout ⇒ attendu à la séance d'accueil, présence enregistrée sur son inscription ; chaque message ; Review Focus 2 ; heures prévues + durée ajoutée ; conflit horaire avec une de ses séances ⇒ accepté (`Conflicts` n'empêche rien) ; il n'apparaît pas dans `Enrolments::placesTaken` ni `Paperwork::sessionEnrolments` de la session d'accueil.
- [ ] **Step 2 : lancer** → échec. **Step 3 : implémenter.** **Step 4 : relancer** fichier + `AttendanceTest`, `Signing/` → verts.
- [ ] **Step 5 : commit** `feat(sessions): add séances of another session to a trainee`.

### Task 9 : heures, clôtures et lecteurs pour les séances ajoutées

**Files :**
- Modify : `Public/RealisedHours.php` (entrée `by_module` `module_id: null`, libellé « Rattrapage », incluse dans `realised_minutes`), `Public/Billing.php` (`hours` : `all_months_closed` / `closed_months` avec les mois des séances ajoutées clôturés par la session d'accueil), `Public/Paperwork.php` (`sessionsFinallyClosedSince` saute l'inscription tant qu'un mois d'accueil est ouvert ; `enrolment()['schedule']` inclut l'ajout), `AttendanceRows.php` (accueilli dans la grille avec `guest_of` = numéro du dossier, libellé « Rattrapage (D-…) »), `DayBeforeReminders.php` (accueillis reçoivent le rappel, libellé de **sa** session), `TraineePlanning.php` / `TraineeFollowUp.php` (session d'origine affichée)
- Test : `tms/tests/Feature/Sessions/IndividualPlanningAddedReadersTest.php`

- [ ] **Step 1 : tests en échec** : `RealisedHours::forEnrolment` ⇒ entrée rattrapage et total ; `RealisedHours` et `Billing::hours` donnent le même `realised_minutes` ; mois d'accueil ouvert ⇒ `all_months_closed` faux, clôturé ⇒ vrai ; attestation automatique attend la clôture d'accueil ; grille d'accueil affiche « Rattrapage (D-2026-…) » ; rappel la veille ; `RealisedHours::forSession` et `Retention` de la session d'accueil ne le comptent pas.
- [ ] **Step 2 : lancer** → échec. **Step 3 : implémenter.**
- [ ] **Step 4 : relancer** fichier + `RealisedHoursTest`, `BillingSurfaceTest`, `PaperworkTest`, `Automation/`, `tests/Feature/Finance`, `tests/Feature/Documents` → verts.
- [ ] **Step 5 : commit** `feat(sessions): hours and closures of added séances`.

### Task 10 : écran « Ajouter des séances », documentation (L2)

**Files :**
- Modify : `IndividualPlanningController.php`, `routes/sessions.php`, `individual-planning.tsx`, `tms/docs/sessions.md`, `tms/CHANGELOG.md`
- Test : `IndividualPlanningHttpTest.php`

**Interfaces :**
- `GET enrolments/{enrolment}/planning/available?q=…&session=…` → JSON `{sessions: [{id, label}], seances: [{id, date, start, end, module, conflict: bool}]}` : sessions visibles par `SessionsVisibility::sessions(sessions.sessions.view)`, non annulées, hors corbeille, autres que la sienne ; séances vivantes de modules en séances, dans ses dates, mois ouvert, pas déjà prévues ; 20 sessions max.
- `POST …/planning/additions` `{seance_ids: string[], reason?: string}`.

- [ ] **Step 1 : tests HTTP en échec** : session hors périmètre absente de `available` ; POST ajoute ; payload GET `planning` montre `kind: 'added'` avec `session_label`.
- [ ] **Step 2 : lancer** → échec. **Step 3 : implémenter** (recherche + cases à cocher + avertissement conflit).
- [ ] **Step 4 : vérifier** Pest + lint/types/build + contrôle navigateur (ajout d'une séance d'une autre session à C-2026-0003, puis présence côté session d'accueil).
- [ ] **Step 5 : docs + commit** `feat(sessions): add séances of another session from the individual planning`.

### Task 11 : vérification finale

- [ ] Pint, PHPStan (`--memory-limit=1G`), suite Pest complète (seule sur la machine), lint/types/build : tous verts.
- [ ] Mise à jour du `CLAUDE.md` racine (section Sessions : `IndividualPlanning` seule source du planning d'un stagiaire).
- [ ] Commit `docs: individual planning architecture notes`.
