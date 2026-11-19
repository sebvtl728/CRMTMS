# Admissions (candidats, apprenants, alternants) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** New `Admissions` module: candidatures attached to CRM people (stages, positioning, interview, decision, alternance contract, custom fields, RNCP code), creation of the training dossier at admission, CRM views, 2-year purge; plus the CRM menu rename « Personnes » → « Interlocuteurs ».

**Architecture:** `tms/app/Modules/Admissions/` owns its tables and reaches other modules only through their public services (`Crm\Public\*`, `Catalogue\Trainings|TrainingData|TrainingUsage`, `Sessions\Public\*`). Nobody imports Admissions: it registers itself in the existing registries (`FicheBlocks`, `FicheLinks`, `FicheHistory`, `DossierBlocks`, `TrainingUsage`, `ReferenceUsage`, `MorphAliases`, `Permissions::registerModule`). Sessions gets one new public service, `Sessions\Public\Admission`, to create the dossier and the enrolment.

**Tech Stack:** Laravel 12 / PHP 8.4, Pest, PostgreSQL 17 (FORCE RLS), Inertia + React 19 / TypeScript, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-18-admissions-candidats-design.md` (§ 7 and § 8 hold the decisions taken after review; they win over § 5 where they are more precise).

## Global Constraints

- Every new table: `tenant_id uuid not null`, model with `BelongsToTenant`, `Rls::enable('<table>')` in its migration; UUID v7 ids; migrations run with `--database=pgsql_owner`.
- Every user-facing string in French; dates `jj/mm/aaaa`; CSV separator `;`.
- States are enums in code; business lists are referentials (`reference_items`, store the id, `ReferenceUsage::register`). Only new referential: `candidature_refusal_reason`.
- `$auditPersonal` on `Candidature`: `legal_representative`, `rqth`, `adaptation_needs`, `interview_report`, `positioning_result`, `france_travail_id`, `last_diploma`, `custom_values`. No personal value in logs.
- Files only through `TenantStorage::path('admissions', …)`, private disk; one file ≤ 20 Mo, PDF or image (`pdf,jpg,jpeg,png`).
- Numbers `C-<année Paris>-<NNNN>`; Paris days for every date comparison.
- Sensitive data (`rqth`, `adaptation_needs`, `interview_report`) are **absent from Inertia props and JSON** without `admissions.sensitive.view` — not merely hidden in React.
- No role-name test in business code: rights and scopes only (`own` = responsible or creator; `das` = `das_id`).
- User-facing errors: `UserFacingHttpException` or validation errors; 409 for forbidden state changes.
- The NIR is never stored.
- TDD: failing test first; commit only on green; commits in English, conventional style, trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Branch `lot-admissions`.
- Commands from `tms/`: `docker compose exec app php artisan test --filter=<Name>`; never two Pest runs at once.

## Review Focus

1. Two users click « Créer le dossier » at the same time → exactly one dossier; the second gets 409 « Le dossier de cette candidature existe déjà. » (Task 8 test).
2. A user without `admissions.sensitive.view` loads the candidature page or the export → the JSON contains no `rqth`, `adaptation_needs`, `interview_report` key (Task 4 and Task 10 tests).
3. The CRM person of a candidature is anonymised or put in the trash → the candidature page still opens and shows « Personne anonymisée » / the trashed label, no 500 (Task 4 test).
4. An admin removes a choice option that candidatures already use → 422 « Ce choix est utilisé par des candidatures : masquez le champ plutôt que de le retirer. » (Task 5 test).
5. Two CRM people are merged and both have candidatures → all candidatures (and alternance tutor references) point to the kept person (Task 9 test).

---

### Task 1: Menu « Interlocuteurs »

**Files:**
- Modify: `resources/js/components/app-sidebar.tsx:54`, `resources/js/pages/crm/people/index.tsx` (page title + breadcrumb), any `people/*` breadcrumb reading « Personnes »
- Modify: `docs/crm.md` (menu name), Playwright specs that look for « Personnes » (`grep -rn "Personnes" e2e resources/js`)

- [ ] **Step 1:** `grep -rn "'Personnes'\|>Personnes<\|\"Personnes\"" resources/js e2e tests` and list the hits.
- [ ] **Step 2:** Change sidebar title to `Interlocuteurs`, page `<Head title>` and breadcrumb to `Interlocuteurs`; the page keeps particuliers and their badge. Update e2e selectors.
- [ ] **Step 3:** Run `docker compose run --rm vite sh -c "npm run lint && npm run types"` → clean.
- [ ] **Step 4:** Commit `feat(crm): rename the People menu to Interlocuteurs`.

### Task 2: Code RNCP sur la formation

**Files:**
- Create: `database/migrations/2027_01_16_000001_add_rncp_code_to_trainings.php`
- Modify: `app/Modules/Catalogue/Training.php` (fillable), the training request/validator, `app/Modules/Catalogue/TrainingData.php`, training form page under `resources/js/pages/catalogue/`
- Test: `tests/Feature/Catalogue/TrainingRncpTest.php`

**Interfaces:**
- Produces: `TrainingData::$rncp_code: ?string` (read by Task 4 to prefill the candidature).

- [ ] **Step 1: Failing tests**
  - `it('stores the RNCP code normalised')`: post `rncp_code = ' rncp 36004 '` → stored `RNCP36004`; `TrainingData::fromModel($t)->rncp_code === 'RNCP36004'`.
  - `it('refuses a malformed RNCP code')`: `'36004X'` → 422 on `rncp_code`, message `Le code RNCP doit s'écrire RNCP suivi de chiffres (ex. RNCP36004).`
  - empty → `null`.
- [ ] **Step 2:** Run `--filter=TrainingRncpTest` → FAIL.
- [ ] **Step 3:** Column `rncp_code varchar(20) null`; normalisation = uppercase, spaces removed; rule `regex:/^RNCP\d{1,6}$/`. Field « Code RNCP » on the training form and sheet.
- [ ] **Step 4:** Run → PASS; run `--filter=Catalogue` → PASS.
- [ ] **Step 5:** Commit `feat(catalogue): RNCP code on trainings`.

### Task 3: Socle du module Admissions

**Files:**
- Create: `app/Modules/Admissions/AdmissionsServiceProvider.php` (register in `bootstrap/providers.php`), `app/Modules/Admissions/Permissions.php`, `routes/admissions.php` (required from `routes/web.php` after `finance.php`)
- Create: `app/Modules/Admissions/Candidatures/{Candidature.php,CandidatureStatus.php,CandidatureNumbering.php,CandidatureFile.php}`, `app/Modules/Admissions/Alternance/{AlternanceContract.php,ContractType.php}`, `app/Modules/Admissions/CustomFields/{CustomFieldDefinition.php,CustomFieldType.php}`
- Create: `database/migrations/2027_01_16_000002_create_admissions_tables.php`, `database/migrations/2027_01_16_000003_seed_candidature_refusal_reason.php`
- Modify: `app/Modules/Core/References/ReferenceList.php` (case `CandidatureRefusalReason = 'candidature_refusal_reason'`), `CapFormationReferenceSeeder.php` (values `['Niveau insuffisant', 'Projet non adapté', 'Pas d\'entreprise d\'accueil', 'Places complètes', 'Autre']`)
- Modify: `tests/Arch/ModuleBoundariesArchTest.php`
- Test: `tests/Feature/Admissions/AdmissionsFoundationTest.php`

**Interfaces:**
- Produces:
  - `Permissions::CANDIDATURES_VIEW = 'admissions.candidatures.view'`, `CANDIDATURES_UPDATE = 'admissions.candidatures.update'`, `CANDIDATURES_DELETE = 'admissions.candidatures.delete'` (scoped), `SENSITIVE_VIEW = 'admissions.sensitive.view'` (not scoped). Groups « Candidatures » and « Données sensibles des candidats ». Defaults: AdminOrganisme all; Direction view + sensitive; ResponsableDas view + update, scope `das`; Commercial, ChargeRelations, Assistant view + update; Formateur none.
  - `enum CandidatureStatus: string { Identified='identified'; PositioningSent='positioning_sent'; PositioningReceived='positioning_received'; Interview='interview'; Admitted='admitted'; Rejected='rejected'; Withdrawn='withdrawn' }` with `label(): string` (Identifié, Test envoyé, Test reçu, Entretien, Admis, Non retenu, Désisté) and `rank(): int` (0..4 for the path, null for exits).
  - `enum ContractType { Apprenticeship='apprenticeship' (Contrat d'apprentissage); Professionalisation='professionalisation' (Contrat de professionnalisation) }`.
  - `enum CustomFieldType { text, textarea, number, date, boolean, choice, file }`.
  - `CandidatureNumbering::next(CarbonImmutable $at): string` (same mechanics as `DossierNumbering`, table `candidature_sequences`).
  - Tables (all with `tenant_id`, timestamps, RLS):
    - `candidatures`: columns of spec § 5.2 (`person_id uuid not null`, `training_id`, `rncp_code`, `target_session_id`, `das_id`, `responsible_user_id`, `created_by`, `origin_id` → `contact_origin`, `prescriber_organisation_id`, `france_travail_id`, `last_diploma`, `legal_representative jsonb`, `rqth bool default false`, `adaptation_needs text`, `positioning_sent_on date`, `positioning_received_on date`, `positioning_result varchar(100)`, `interview_at timestamptz`, `interviewer_user_id`, `interview_report text`, `decision_reason_id`, `dossier_id`, `custom_values jsonb not null default '{}'`, `anonymised_at`, `deleted_at`, `number` unique per tenant, `status varchar`), index `(tenant_id, person_id)`, `(tenant_id, status)`, unique `(tenant_id, dossier_id)` where not null.
    - `candidature_sequences (tenant_id, year, last_number)`.
    - `candidature_files (candidature_id, kind varchar(60), name, size, path, uploaded_by)`.
    - `alternance_contracts (candidature_id unique, contract_type, employer_organisation_id, tutor_person_id, starts_on, ends_on, opco_organisation_id)`, CHECK `ends_on >= starts_on`.
    - `custom_field_definitions (entity varchar(40) default 'candidature', label, type, options jsonb, required_for_admission bool, position int, hidden_at)`.
  - `ReferenceUsage::register` for `candidatures.origin_id`, `candidatures.decision_reason_id`, `candidatures.das_id`; `TrainingUsage::register('candidatures', fn ($id) => …)`; morph aliases `candidature`, `candidature_file`, `alternance_contract`, `custom_field_definition`.

- [ ] **Step 1: Failing tests** in `AdmissionsFoundationTest`:
  - `it('numbers candidatures per Paris year')`: two in 2026 → `C-2026-0001`, `C-2026-0002`; one on 2026-12-31 23:30 UTC (= 2027 Paris) → `C-2027-0001`.
  - `it('isolates candidatures by tenant')`: candidature of tenant A invisible under tenant B (`Candidature::count() === 0`).
  - `it('registers the admissions rights with their defaults')`: after `tms:permissions:sync`, `direction` has `admissions.sensitive.view`, `commercial` has `admissions.candidatures.update` and not `admissions.sensitive.view`, `formateur` none.
  - `it('seeds the refusal reasons for every tenant')`.
- [ ] **Step 2:** Arch rules in `ModuleBoundariesArchTest`:
  - `ADMISSIONS_TABLES_LIST = 'candidatures|candidature_sequences|candidature_files|alternance_contracts|custom_field_definitions'`; no other module names them.
  - Admissions references only `Crm\Public`, `Catalogue\(Trainings|TrainingData|TrainingUsage)`, `Sessions\Public`, `Core`; never the CRM, catalogue or Sessions tables.
  - Crm, Catalogue, Sessions, Finance, Core, Connectors never reference `App\Modules\Admissions`.
  - A detection test per rule (same style as the existing « detects … » tests).
- [ ] **Step 3:** Run `--filter=AdmissionsFoundationTest` and `--filter=ModuleBoundariesArchTest` → FAIL.
- [ ] **Step 4:** Implement; migrate with `--database=pgsql_owner`.
- [ ] **Step 5:** Run both filters plus `--filter=TenancyArchTest` and `--filter=ModelsArchTest` → PASS.
- [ ] **Step 6:** Commit `feat(admissions): module foundation, tables and rights`.

### Task 4: Candidature : écriture, étapes, visibilité, page de données

**Files:**
- Create: `app/Modules/Admissions/AdmissionsVisibility.php`, `Candidatures/CandidatureWriter.php`, `Candidatures/CandidatureTransitions.php`, `Candidatures/CandidaturePresenter.php`, `Candidatures/CandidatureController.php`, `Candidatures/CandidatureStatusController.php`
- Modify: `AdmissionsServiceProvider` (`Route::bind('candidature', …)` → 404 outside view scope), `routes/admissions.php`
- Test: `tests/Feature/Admissions/CandidatureWriterTest.php`, `CandidatureTransitionsTest.php`, `CandidatureVisibilityTest.php`

**Interfaces:**
- Consumes: `Crm\Public\Fiches::person()`, `Fiches::openable()`, `Crm\Public\Trainees::search/create/updateProfile`, `Catalogue\Trainings::find`, `TrainingData::$rncp_code`.
- Produces:
  - `AdmissionsVisibility::candidatures(User $user, string $permission): Builder` and `::allows(User $user, string $permission, Candidature $c): bool`.
  - `CandidatureWriter::create(array $data, User $by): Candidature`, `::update(Candidature $c, array $data, User $by): Candidature` (transaction; `person_id` required on create, immutable after; when `training_id` changes and `rncp_code` not sent, `rncp_code` = training's; `das_id` defaults to training's DAS; `education_level_id` / `professional_situation_id` are forwarded to `Trainees::updateProfile`, never stored on the candidature; sensitive keys ignored without `SENSITIVE_VIEW`).
  - `CandidatureTransitions::move(Candidature $c, CandidatureStatus $to, User $by, ?string $reasonId = null): void` (`lockForUpdate`).
  - `CandidaturePresenter::show(Candidature $c, User $viewer): array` — the page props; identity from `Fiches::person` (or `['label' => 'Personne anonymisée']`), `crm_href` only if `openable`, `is_minor` (age < 18 at: contract start, else target session start, else today, Paris), sensitive keys only with `SENSITIVE_VIEW`.
- Routes: `GET /admissions/candidatures/create`, `POST /admissions/candidatures`, `GET /admissions/candidatures/{candidature}`, `PUT …/{candidature}`, `POST …/{candidature}/status`, `DELETE …/{candidature}`, `POST …/{candidature}/restore`.

- [ ] **Step 1: Failing tests**
  - Writer: creates with `C-…` number, `status = identified`, `created_by`; prefills `rncp_code` from the training; user override kept; changing training re-proposes the new code; education level written on the person via `Trainees::updateProfile` (assert on `Fiches::person`); `rqth` ignored for a user without sensitive right; audit row for a sensitive change shows `[donnée personnelle]`.
  - Transitions:
    - forward jump `identified → admitted` allowed when `training_id` set;
    - `admitted` without training → 422 `training_id` « Choisissez la formation visée avant d'admettre le candidat. »;
    - `rejected` without reason → 422 `decision_reason_id` « Indiquez le motif. »;
    - backward allowed before admission; from `admitted` back to `interview` with a live `dossier_id` → 409 « Le dossier de formation est créé : la candidature ne peut plus revenir en arrière. »; allowed when that dossier is in the trash — that case needs `Admission::dossierState()` (Task 8): here, test only the no-dossier case; Task 8 adds the live/trashed cases;
    - `rejected`/`withdrawn` → only back to a path stage.
  - Visibility:
    - `own` sees candidatures they are responsible for or created;
    - `das` sees by `das_id`; outside scope → 404 on show;
    - user without sensitive right: `->assertInertia(fn ($p) => $p->missing('candidature.rqth')->missing('candidature.adaptation_needs')->missing('candidature.interview_report'))`;
    - person anonymised → page 200, identity label « Personne anonymisée »;
    - `is_minor` true for a person born 2010-01-01 with target session starting 2027-01-05.
- [ ] **Step 2:** Run the three files → FAIL.
- [ ] **Step 3:** Implement. Validation messages in French; `legal_representative` keys `last_name, first_name, relationship, phone, email`.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `feat(admissions): candidature writer, stages and visibility`.

### Task 5: Champs personnalisés

**Files:**
- Create: `app/Modules/Admissions/CustomFields/{CustomFieldDefinitions.php,CustomFieldValues.php,CustomFieldController.php}`
- Modify: `CandidatureWriter` (accept `custom_values`), `CandidatureTransitions` (admission check), `CandidaturePresenter`
- Test: `tests/Feature/Admissions/CustomFieldsTest.php`

**Interfaces:**
- Produces:
  - `CustomFieldDefinitions::create(array $d)`, `::update(CustomFieldDefinition $f, array $d)`, `::reorder(list<string> $ids)`, `::hide(CustomFieldDefinition $f)`, `::unhide(...)`, `::delete(...)`; `::active(string $entity = 'candidature'): Collection` ordered by `position`.
  - `CustomFieldValues::validate(string $entity, array $values): array` (normalised: number as string decimal, date `Y-m-d`, boolean bool, choice among options; unknown or hidden ids dropped).
  - `CustomFieldValues::missingForAdmission(Candidature $c): list<string>` (labels; a `file` field counts as filled when a `candidature_files` row has kind `custom:<id>`; hidden fields not required).
- Routes (`settings.manage`): `GET /admin/custom-fields`, `POST`, `PUT /{field}`, `POST /reorder`, `POST /{field}/hide`, `POST /{field}/unhide`, `DELETE /{field}`.

- [ ] **Step 1: Failing tests**
  - creates each type; `choice` needs ≥ 1 option;
  - delete a used field → 409 « Ce champ est rempli sur des candidatures : masquez-le plutôt que de le supprimer. »; unused → deleted;
  - type change with values → 422 `type` « Le type ne peut plus changer : des candidatures ont une valeur. »;
  - removing a used choice option → 422 (Review Focus 4 message);
  - candidature save validates values (« Le champ « X » attend un nombre. »);
  - admission refused while a `required_for_admission` field is empty → 422 `custom_values` « À remplir avant l'admission : X, Y. »; hidden required field not enforced;
  - non-admin → 403 on admin routes;
  - audit of `custom_values` shows `[donnée personnelle]`.
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement (« used » = `candidatures.custom_values ? <id>` — a jsonb test on the RLS table is fine here: admin action, not a hot path). **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `feat(admissions): custom fields on candidatures`.

### Task 6: Fichiers de la candidature

**Files:**
- Create: `app/Modules/Admissions/Candidatures/{CandidatureFiles.php,CandidatureFileController.php}`
- Test: `tests/Feature/Admissions/CandidatureFilesTest.php`

**Interfaces:**
- Produces: `CandidatureFiles::store(Candidature $c, string $kind, UploadedFile $f, User $by): CandidatureFile` (kinds `positioning`, `contract`, `custom:<uuid of an active file-type field>`; one file per `contract` and `custom:*` kind, replaces the previous one; several for `positioning`); `::delete(CandidatureFile $f)`; `::purge(Candidature $c)` (Task 9, 12). Each store/delete calls `$c->touch()`.
- Routes: `POST /admissions/candidatures/{candidature}/files`, `GET …/files/{file}` (download, view right), `DELETE …/files/{file}` (update right).

- [ ] **Step 1: Failing tests:** stored under `tenants/<tenant>/admissions/`; 21 Mo → 422 « Le fichier dépasse 20 Mo. »; `.docx` → 422 « Formats acceptés : PDF, JPG, PNG. »; unknown kind → 422; second contract replaces the first (old file deleted from disk); file of another tenant's candidature → 404; download outside scope → 404.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5:** Commit `feat(admissions): candidature files`.

### Task 7: Contrat d'alternance

**Files:**
- Create: `app/Modules/Admissions/Alternance/{AlternanceWriter.php,AlternanceController.php}`
- Test: `tests/Feature/Admissions/AlternanceTest.php`

**Interfaces:**
- Consumes: `Fiches::organisation()`, `Fiches::person()`.
- Produces: `AlternanceWriter::save(Candidature $c, array $data, User $by): AlternanceContract` (upsert); `::delete(Candidature $c)`. Routes `PUT /admissions/candidatures/{candidature}/alternance`, `DELETE` same.

- [ ] **Step 1: Failing tests:** saving before stage `interview` → 409 « Le contrat se saisit à partir de l'entretien. »; `ends_on < starts_on` → 422 `ends_on` « La fin du contrat doit suivre son début. »; unknown or trashed employer / OPCO / tutor → 422; delete after dossier created → 409 « Le dossier est créé : le contrat ne peut plus être retiré. » (dossier case completed in Task 8); presenter exposes `alternance` with labels.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5:** Commit `feat(admissions): alternance contract`.

### Task 8: Admission → dossier de formation

**Files:**
- Create: `app/Modules/Sessions/Public/Admission.php`
- Create: `app/Modules/Admissions/Candidatures/{DossierCreation.php,DossierCreationController.php}`
- Modify: `AdmissionsServiceProvider` (`DossierBlocks::register('admissions.candidature', …)`)
- Test: `tests/Feature/Sessions/AdmissionPublicTest.php`, `tests/Feature/Admissions/DossierCreationTest.php`

**Interfaces:**
- Produces (Sessions, public):
  - `Admission::sessionOptions(string $trainingId): list<array{id: string, label: string, starts_on: string}>` (planned/confirmed, not finished, Paris days).
  - `Admission::createDossier(array $data): string` — keys: `account` (`{type: person|organisation, id}`), `company_id` (default `DossierWriter::defaultCompanyId()`), `das_id`, `responsible_user_id`, `training_id`, `label`, `quantity`, `unit`, `unit_price`, `session_id` (nullable), `person_id`, `funding` (nullable: `{funder_organisation_id, funding_type_id, amount, pays_directly: true}`). One transaction: `DossierWriter::create`, then `Enrolments::enrol` when `session_id`, then the funding through `FundingWriter`. Validation errors bubble unchanged.
  - `Admission::dossierState(string $dossierId): ?string` → `live` | `trashed` | null (gone).
- Produces (Admissions): `DossierCreation::defaults(Candidature $c): array` (dialog prefill: account = employer if a contract, else the person; line price = `price_company_cents` for an organisation account, `price_individual_cents` for a person; hours = `duration_hours`; session = `target_session_id`; funding prefilled with the OPCO when the contract has one) and `DossierCreation::create(Candidature $c, array $data, User $by): string` (locks the candidature; status must be `admitted`; refuses when `dossierState(dossier_id) === 'live'`; writes `dossier_id`).
- Route: `GET …/{candidature}/dossier/defaults`, `POST …/{candidature}/dossier` — rights `admissions.candidatures.update` **and** `sessions.dossiers.update` (`Billing::allows`-style check through `Sessions\Public`).

- [ ] **Step 1: Failing tests**
  - particulier: account = person, enrolled on the session, dossier `D-…`, `candidature.dossier_id` set, block « Candidature C-… » on the dossier page;
  - alternant: account = employer, OPCO funding `pays_directly = true` with the typed amount;
  - no session → dossier with line, no enrolment;
  - status not admitted → 409 « Seul un candidat admis devient apprenant. »;
  - second call → 409 (Review Focus 1); concurrency: two `DossierCreation::create` calls in two DB connections → one dossier (pattern of the existing `DossierFromQuote` concurrency test);
  - dossier trashed → creation allowed again, `dossier_id` replaced; and the transition/alternance-delete cases left from Tasks 4 and 7;
  - user without `sessions.dossiers.update` → 403;
  - `ModuleBoundariesArchTest` still green (Sessions never imports Admissions).
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5:** Commit `feat(admissions): create the training dossier of an admitted candidate`.

### Task 9: Intégration CRM (bloc, historique, RGPD, fusion)

**Files:**
- Modify: `app/Modules/Crm/Public/FicheBlocks.php` (block may carry `action: array{label: string, href: string}|null`), the CRM fiche block renderer (`grep -rn "blocks" resources/js/pages/crm/people/show.tsx`)
- Create: `app/Modules/Admissions/Crm/{CandidaturesBlock.php,CandidatureLinks.php}`, `app/Modules/Admissions/History/CandidatureHistory.php`, `app/Modules/Admissions/Gdpr/{CandidatureAnonymisation.php,PersonCandidaturesExport.php}`
- Test: `tests/Feature/Admissions/CrmIntegrationTest.php`, `tests/Feature/Crm/FicheBlocksTest.php` (action)

**Interfaces:**
- Produces:
  - `CandidaturesBlock::for(string $personId, User $viewer): ?array` — rows `C-… · formation · étape`, badges « Apprenant » / « Alternant », action « Ouvrir une candidature » → `/admissions/candidatures/create?person=<id>` when the viewer has `CANDIDATURES_UPDATE`; null without `CANDIDATURES_VIEW`.
  - `CandidatureHistory::for(Candidature $c, ?string $before): array` from `audit_logs` (pattern `Sessions\History\SessionsHistory`); `FicheHistory::register('candidature', …)`.
  - `CandidatureAnonymisation::anonymise(Candidature $c): void` — nulls `legal_representative, rqth(false), adaptation_needs, interview_report, positioning_result, france_travail_id, last_diploma`, `custom_values = {}`, `CandidatureFiles::purge`, sets `anonymised_at`; keeps number, status, training, dates.
  - `FicheLinks` registrations: `registerLinked('admissions.candidatures', …)` (person or tutor/employer/OPCO/prescriber reference); `registerMerge` (repoint `person_id`, `tutor_person_id`, `employer_organisation_id`, `opco_organisation_id`, `prescriber_organisation_id`); `registerAnonymisation` (all candidatures of the person); `registerExport` (section « Candidatures », view permissions `[CANDIDATURES_VIEW, SENSITIVE_VIEW]`, custom values with their labels, hidden fields included).
- [ ] **Step 1: Failing tests:** block rows and action visibility per right; action rendered as a button by the CRM page (FicheBlocks unit test on the shape); merge of two people with candidatures (Review Focus 5); anonymising the person anonymises its candidatures and deletes files; RGPD export contains the section; import undo keeps a person who has a candidature; history lists stage changes with French labels.
- [ ] **Step 2–4:** FAIL → implement → PASS (`--filter=Crm` too).
- [ ] **Step 5:** Commit `feat(admissions): CRM block, history and GDPR hooks`.

### Task 10: Liste et export

**Files:**
- Create: `app/Modules/Admissions/Candidatures/{CandidatureListQuery.php,CandidatureListController.php,CandidatureExport.php}`
- Test: `tests/Feature/Admissions/CandidatureListTest.php`

**Interfaces:**
- Produces: `CandidatureListQuery::build(User $u, array $filters): Builder` — the single query for list and export. Tabs (`onglet`): `candidats` = status ≠ admitted, or admitted without live dossier (default; excludes rejected/withdrawn unless `etape` filter asks for them); `apprenants` = live dossier, no contract; `alternants` = live dossier + contract; `tous`. Filters `etape`, `formation`, `das`, `responsable`, `contrat` (`apprenticeship|professionalisation`), `q` (name via `Trainees::search` ids, or number). Trash excluded. 25 per page.
- Routes: `GET /admissions/candidatures`, `GET /admissions/candidatures/export` (CSV `;`, UTF-8 BOM, columns Numéro;Nom;Prénom;Étape;Formation;Code RNCP;DAS;Responsable;Type de contrat;Employeur;Début contrat;Fin contrat;Dossier). No sensitive column ever.
- [ ] **Step 1: Failing tests:** each tab; scope applied; a candidate with contract but no dossier is in `candidats` with `has_contract = true`; export columns exact and no `rqth` (Review Focus 2); search by number.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5:** Commit `feat(admissions): candidature list and export`.

### Task 11: Écrans

**Files:**
- Create: `resources/js/pages/admissions/candidatures/{index.tsx,create.tsx,show.tsx}`, `resources/js/components/admissions/{candidature-steps.tsx,positioning-card.tsx,interview-card.tsx,alternance-card.tsx,custom-fields-card.tsx,dossier-dialog.tsx}`, `resources/js/pages/admin/custom-fields.tsx`, `resources/js/types/admissions.ts`
- Modify: `resources/js/components/app-sidebar.tsx` (CRM: `{ title: 'Candidats', url: '/admissions/candidatures', icon: UserRoundCheck, permission: 'admissions.candidatures.view' }` after « Interlocuteurs »; admin: `{ title: 'Champs personnalisés', url: '/admin/custom-fields', icon: SlidersHorizontal, permission: 'settings.manage' }`), CRM fiche block renderer (action button), dossier page block (already generic)
- Test: `e2e/admissions.spec.ts`

- [ ] **Step 1:** Write the Playwright scenario (fails): from a CRM person « Ouvrir une candidature » → choose training (RNCP prefilled) → « Test envoyé » → « Test reçu » with result → « Entretien » → fill contract (employer, tutor, dates) → « Admis » → « Créer le dossier » (employer prefilled) → candidature listed in tab « Alternants ».
- [ ] **Step 2:** Build the pages: list with tabs + filters + export button; show page = header (name, number, stage, step buttons with reason dialog for « Non retenu »), cards Identité (read, « Modifier dans le CRM » if `crm_href`, minor warning « Candidat mineur : renseignez le représentant légal. »), Orientation, Positionnement, Entretien (sensitive parts only when sent), Alternance, Informations complémentaires, Décision, Historique; create page = person search (`Trainees` search endpoint already used by enrolment dialog) or creation; dossier dialog prefilled from `/dossier/defaults`; admin page list/reorder/hide/edit. Entering a positioning or interview date offers (toast with button, never automatic) to move to the matching stage.
- [ ] **Step 3:** Run `docker compose run --rm vite sh -c "npm run lint && npm run types && npm run build"` → clean; `npm run e2e -- admissions` (take the lock dir `../.superpowers/e2e.lock`) → PASS.
- [ ] **Step 4:** Commit `feat(admissions): candidature screens and custom fields admin`.

### Task 12: Anonymisation à 2 ans

**Files:**
- Create: `app/Modules/Admissions/Gdpr/PurgeStaleCandidatures.php` (command `tms:admissions:purge`)
- Modify: `routes/console.php` (`Schedule::command('tms:admissions:purge')->dailyAt('03:50')->timezone('Europe/Paris')->withoutOverlapping()->onOneServer();`)
- Test: `tests/Feature/Admissions/PurgeStaleCandidaturesTest.php`

- [ ] **Step 1: Failing tests:** status `rejected` / `withdrawn` with `updated_at` 2 years + 1 day ago (Paris) → anonymised (`CandidatureAnonymisation`), files gone; 2 years − 1 day → untouched; `admitted` 3 years old → untouched; already anonymised → skipped; an error on one tenant does not stop the others (`ForEachTenant`); the CRM person untouched.
- [ ] **Step 2–4:** FAIL → implement → PASS.
- [ ] **Step 5:** Commit `feat(admissions): anonymise stale candidatures after two years`.

### Task 13: Documentation et vérification finale

**Files:**
- Create: `tms/docs/admissions.md` (parcours, étapes, champs, alternance, création du dossier, droits, conservation 2 ans)
- Modify: `tms/docs/admin.md` (champs personnalisés, droits Admissions), `tms/docs/crm.md` (menu Interlocuteurs, bloc Candidatures, entrée Candidats), `tms/docs/data-dictionary.md` (5 tables + `trainings.rncp_code`), `CLAUDE.md` (section « Module Admissions : points d'architecture à connaître », 6–8 bullets: frontières, `Sessions\Public\Admission`, étapes calculées, champs personnalisés, sensibles, purge)
- [ ] **Step 1:** Write the docs.
- [ ] **Step 2:** Run `vendor/bin/pint`, `vendor/bin/phpstan analyse --memory-limit=1G`, full `php artisan test` → all green.
- [ ] **Step 3:** Commit `docs: admissions guide and data dictionary`.
