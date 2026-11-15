# Lot 2a — Dossiers, sessions, inscriptions : plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** un devis accepté devient un dossier qui inscrit des stagiaires (personnes du CRM) sur des sessions intra ou inter et répartit son prix entre plusieurs financeurs.

**Architecture:** nouveau module `tms/app/Modules/Sessions/` (dossiers, lignes, financements, sessions, inscriptions), isolé : il ne nomme aucune table du Crm ni du Catalogue et passe par des services publics (`Crm\Public\*` créés ici, `Catalogue\Trainings`). Droits par domaine et périmètre de la 0.5.0 (`registerModule(..., scoped, defaultScopesFor)`), visibilité propre (`SessionsVisibility`) sur le modèle de `CrmVisibility`. Montants stockés en `numeric(12,2)`, calculés en centimes (`brick/math`).

**Tech Stack:** Laravel 12 / PHP 8.4, PostgreSQL 17 (FORCE RLS), Inertia + React 19 / TS, shadcn, Pest, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-15-lot2a-dossiers-sessions-design.md` (validée le 2026-11-15 ; §§ 9 et 10 précisent §§ 5.1 et 5.4) ; autorité générale `docs/superpowers/specs/2026-11-11-tms-design.md`.

Branche : `lot2a-dossiers-sessions` depuis `main` **après publication de la 0.5.1 (contenu 0.5.0)**. Le document de conception y est commité en premier. VERSION → `0.6.0` à la dernière tâche.

## Global Constraints

- Toutes les règles du `CLAUDE.md` : `tenant_id uuid not null` + `BelongsToTenant` + `Rls::enable` ; migrations `--database=pgsql_owner` ; UUID v7 ; suppression logique sur dossiers et sessions ; français partout, formats français (dates `jj/mm/aaaa`, montants `1 234,56 €`, CSV `;`) ; TDD ; commits conventionnels en anglais.
- Listes métier = référentiels paramétrables (`reference_items`, id stocké, `ReferenceUsage::register`) : **types de financement**, **motifs d'abandon** (drapeau `meta.force_majeure`), **motifs d'annulation**, **catégories BPF**, **niveaux de diplôme**, **situations professionnelles**. États de dossier, session, inscription, financement : **enums dans le code**.
- Le module Sessions ne nomme aucune table de Crm ni de Catalogue (test d'architecture) ; Crm ne dépend pas de Sessions sauf par tags/registres (`Timeline::TAG`, `LinkedData`, `TrainingUsage`, anonymisation).
- Messages utilisateur d'erreur : `UserFacingHttpException` ou erreurs de validation (un `abort()` avec message n'affiche pas le message sur une page HTML).
- Montants : calcul en centimes entiers, jamais de float ; répartition « prix par stagiaire » au centime, reste aux premières inscriptions par ordre d'inscription (somme exacte).
- Numérotation : `D-<année Paris>-<0001>` (dossiers), `S-<année Paris>-<0001>` (sessions), séquence par organisme et année, sous transaction (mécanique de `QuoteNumbering`).
- Dates : jours de Paris ; une session a `starts_on ≤ ends_on`.
- Données personnelles : besoins d'adaptation / handicap d'une inscription dans `$auditPersonal`, effacés par l'anonymisation ; aucune valeur personnelle en clair dans les journaux.
- Pas de numéro de sécurité sociale.

## Review Focus

1. **Somme des financements ≠ prix** (dossier, ligne ou inscription visée) : alerte affichée avec le montant exact ; un financement refusé ne compte plus dans le financé → tests tâche 6.
2. **Session inter complète** : l'inscription suivante passe « en attente » ; une désinscription ne promeut personne automatiquement ; une session intra refuse un dossier d'un autre contractant → tests tâche 5.
3. **Répartition « prix par stagiaire »** : 1 000 € pour 3 stagiaires = 333,34 + 333,33 + 333,33 ; recalcul à chaque inscription/désinscription ; une inscription abandonnée garde sa part → tests tâche 5.
4. **Devis accepté transformé deux fois** (double clic, deux onglets) : un seul dossier (contrainte unique sur `quote_id` + verrou) → test tâche 7.
5. **Utilisateur limité à ses DAS qui inscrit un stagiaire** : la recherche trouve la personne existante d'un autre DAS (identité minimale seulement) au lieu d'en créer un doublon → test tâche 1/5.

---

### Task 1: Module Sessions, droits et services publics du Crm

**Files:**
- Create: `tms/app/Modules/Sessions/SessionsServiceProvider.php`, `Sessions/Permissions.php`, `tms/app/Modules/Crm/Public/AcceptedQuotes.php`, `Crm/Public/Fiches.php`, `Crm/Public/Trainees.php`
- Modify: `bootstrap/providers.php`, `tests/Arch/ModuleBoundariesArchTest.php`
- Test: `tests/Feature/Sessions/SessionsPermissionsTest.php`, `tests/Feature/Crm/PublicServicesTest.php`, arch test

**Interfaces:**
- Produces:
  - `App\Modules\Sessions\Permissions` : `DOSSIERS_VIEW = 'sessions.dossiers.view'`, `DOSSIERS_UPDATE`, `DOSSIERS_DELETE`, `SESSIONS_VIEW = 'sessions.sessions.view'`, `SESSIONS_UPDATE`, `SESSIONS_DELETE` (les six à périmètre), groupes « Dossiers » et « Sessions et inscriptions ». Défauts : admin, direction (view only), commercial, charge_relations, assistant (view + update) = `all` ; responsable_das view = `das`. Libellés français.
  - `Crm\Public\AcceptedQuotes::find(string $quoteId, User $viewer): ?AcceptedQuoteData` — devis `accepted`, visible pour `crm.quotes.view` ; `AcceptedQuoteData { id, number, company_id, account: {type: 'organisation'|'person', id, label}, contact_person_id, opportunity_id, das_id, lines: list<{training_id: ?string, das_id: ?string, label, quantity, unit, unit_price_cents, discount_percent, total_ht_cents}> }`.
  - `Crm\Public\Fiches::organisation(string $id): ?array{id,label}`, `person(string $id): ?array{id,label,birth_date}`, `searchOrganisations(string $q, int $limit = 10): list<array{id,label,siret}>` (tout l'organisme, identité minimale).
  - `Crm\Public\Trainees::search(string $q, ?string $organisationId = null, int $limit = 15): list<array{id, last_name, first_name, birth_date, organisation_label}>` — **toutes** les personnes de l'organisme non anonymisées ni en corbeille, salariés de `$organisationId` en premier (Review Focus 5) ; `create(array $data): string` (personne ordinaire du CRM, journalisée, contrôle de doublon existant) ; `updateProfile(string $personId, ?string $educationLevelId, ?string $employmentStatusId, ?string $birthDate): void`.
  - Arch : `Sessions` n'importe que `Crm\Public\*`, `Catalogue\Trainings`, `Catalogue\TrainingData`, `Catalogue\TrainingUsage` ; motif `CRM_TABLES` (organisations, people, affiliations, opportunities, quotes, quote_lines, activities…) et `CATALOGUE_TABLES` interdits dans Sessions ; `Crm` et `Catalogue` n'importent pas `Sessions`.

- [ ] **Step 1: Write the failing tests** : permissions enregistrées avec libellés/groupes et périmètres par défaut ; `AcceptedQuotes::find` renvoie null pour un devis envoyé ou invisible, les lignes en centimes pour un accepté ; `Trainees::search` trouve, pour un responsable DAS, une personne hors de ses DAS (identité minimale uniquement) ; arch test des frontières.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** (le niveau de diplôme et la situation professionnelle des personnes arrivent à la tâche 2 : `updateProfile` est livré avec elle si plus simple — le noter).
- [ ] **Step 4: Run** suite, pint, phpstan → PASS.
- [ ] **Step 5: Commit** `feat(sessions): module, rights and CRM public services`

### Task 2: Référentiels, profil du stagiaire, règle d'abandon

**Files:**
- Create: migrations (référentiels par défaut pour les organismes existants et nouveaux ; colonnes `people.education_level_id`, `people.employment_status_id`), `Sessions/AbandonRule.php`
- Modify: `Core\References\ReferenceList` (nouvelles listes), seeder des référentiels, fiche personne (deux champs), page Administration → paramètres (règle d'abandon)
- Test: `tests/Feature/Sessions/ReferencesTest.php`, `AbandonRuleTest.php`, test de la fiche personne

**Interfaces:**
- Produces : listes `funding_type` (OPCO, Entreprise, CPF, France Travail, Région, Personnel), `abandon_reason` (Démission, Maladie *(force majeure)*, Accident *(force majeure)*, Licenciement, Convenance personnelle, Autre), `cancel_reason`, `bpf_category` (valeurs par défaut à reprendre du BPF : salariés d'employeurs privés, demandeurs d'emploi, particuliers, autres), `education_level` (niveaux 3 à 8 + « sans diplôme »), `employment_status` (Salarié, Demandeur d'emploi, Indépendant, Étudiant, Autre).
- `AbandonRule::forTenant(): array{mode: 'prorata'|'prorata_dedit', dedit_percent: ?int}` (réglage `sessions.abandon_rule`, défaut prorata) ; `AbandonRule::forDossier(Dossier)` (surcharge du dossier sinon organisme).

- [ ] **Step 1: Failing tests** : valeurs par défaut créées pour un organisme existant (migration) et nouveau (seeder) ; une valeur utilisée ne se supprime pas ; `dedit_percent` entre 1 et 100 exigé en mode dédit ; la fiche personne enregistre et affiche diplôme et situation (journalisés).
- [ ] **Step 2–4:** RED → implement → suite + front verts.
- [ ] **Step 5: Commit** `feat(sessions): reference lists, trainee profile, abandon rule`

### Task 3: Sessions (intra / inter)

**Files:**
- Create: migration `training_sessions` + `session_sequences`, `Sessions/Sessions/TrainingSession.php`, `SessionStatus.php`, `SessionNumbering.php`, `SessionWriter.php`, `SessionController.php`, `SessionPolicy.php`, `SessionListQuery.php`, `Sessions/SessionsVisibility.php`, routes `routes/sessions.php`, pages `resources/js/pages/sessions/sessions/{index,show,form,calendar}.tsx`, entrée de menu « Sessions »
- Test: `tests/Feature/Sessions/SessionsTest.php`, `SessionsVisibilityTest.php`

**Interfaces:**
- Table `training_sessions` (nom choisi pour éviter la table `sessions` de Laravel) : `id, tenant_id, number, training_id (uuid, sans FK nommée côté code Crm — FK SQL autorisée), das_id, kind ('intra'|'inter'), organisation_id (intra, obligatoire si intra), starts_on, ends_on, planned_hours numeric(6,2), location_kind ('address'|'client'|'remote'), location_text, capacity (inter), responsible_user_id, status, notes, created_by, updated_by, timestamps, soft deletes`. `TrainingUsage::register('sessions', …)`.
- `SessionStatus`: `planned` « Planifiée », `confirmed` « Confirmée », `running` « En cours », `done` « Terminée », `cancelled` « Annulée » ; `running`/`done` calculés à la lecture d'après les dates tant que l'état posé est `planned`/`confirmed` (`TrainingSession::effectiveStatus(CarbonImmutable $today)`).
- `SessionsVisibility::sessions(Builder, User, string $permission = SESSIONS_VIEW)` : own = responsable ou créateur ; das = `das_id` ∈ DAS suivis ; all. `SessionsVisibility::dossiers(...)` (tâche 4) même modèle.
- `SessionWriter::create(array): TrainingSession` (`das_id` et `planned_hours` repris de la formation via `Trainings::find`), `update`, `cancel(TrainingSession, string $reasonId)`.

- [ ] **Step 1: Failing tests** : numéro `S-2026-0001` puis `0002`, remis à 1 l'année suivante (Paris) ; intra sans entreprise refusé ; `starts_on > ends_on` refusé (message français) ; état effectif « En cours » entre les dates ; liste et calendrier mensuel filtrés par périmètre (own / das / all) ; formation utilisée par une session non supprimable.
- [ ] **Step 2–4:** RED → implement → verts (suite, front).
- [ ] **Step 5: Commit** `feat(sessions): intra and inter sessions`

### Task 4: Dossiers et lignes

**Files:**
- Create: migrations `dossiers`, `dossier_lines`, `dossier_sequences` ; `Sessions/Dossiers/Dossier.php`, `DossierLine.php`, `DossierStatus.php`, `DossierNumbering.php`, `DossierWriter.php`, `DossierTransitions.php`, `DossierController.php`, `DossierPolicy.php`, `DossierListQuery.php`, `DossierExport.php` ; pages `resources/js/pages/sessions/dossiers/{index,show,form}.tsx` ; menu « Dossiers »
- Test: `tests/Feature/Sessions/DossiersTest.php`, `DossierTransitionsTest.php`

**Interfaces:**
- `dossiers` : `id, tenant_id, number, company_id, account_organisation_id | account_person_id (CHECK un seul), contact_person_id, responsible_user_id, quote_id (unique, nullable), opportunity_id (nullable), das_id (obligatoire), status, convention_sent_on, convention_signed_on, convocations_sent_on, cancelled_reason_id, cancelled_on, abandon_mode, abandon_dedit_percent (surcharge, nullables), notes, created_by, updated_by, timestamps, soft deletes`.
- `dossier_lines` : `id, tenant_id, dossier_id, position, kind ('training'|'fee'), training_id (nullable, obligatoire si training), das_id, label, quantity, unit, unit_price, discount_percent, total_ht, pricing_mode ('per_trainee'|'group', null pour fee)`.
- `DossierStatus` : `created` « Créé », `convention_sent` « Convention envoyée », `convention_signed` « Convention signée », `convocations_sent` « Convocations envoyées », `running` « En cours », `done` « Réalisé », `cancelled` « Annulé » (`invoiced`, `settled` réservés au lot 3, non proposés). `running`/`done` calculés à la lecture d'après les sessions de ses inscriptions tant qu'aucun de ces deux états n'a été posé à la main.
- `DossierTransitions::sendConvention|signConvention|sendConvocations(Dossier, CarbonImmutable $on)`, `markRunning`, `markDone`, `cancel(Dossier, string $reasonId)` (refus après `done` : 409 message français), chacune sous `lockForUpdate`, journalisée.
- `DossierWriter::create(array): Dossier` / `update` ; prix = Σ `total_ht` des lignes (`Dossier::priceCents(): int`) ; mode de prix proposé selon l'unité (`stagiaire` → `per_trainee`, sinon `group`).

- [ ] **Step 1: Failing tests** : numéro `D-…` ; compte entreprise **ou** particulier ; ligne formation sans `training_id` refusée ; prix exact en centimes (3 lignes) ; transitions dans l'ordre et dates enregistrées ; annulation exige un motif, refusée après « Réalisé » ; liste/export filtrés par périmètre ; export CSV `;` montants français.
- [ ] **Step 2–4:** RED → implement → verts.
- [ ] **Step 5: Commit** `feat(sessions): dossiers and lines`

### Task 5: Inscriptions, liste d'attente, abandon, répartition du prix

**Files:**
- Create: migration `enrolments` ; `Sessions/Enrolments/Enrolment.php`, `EnrolmentStatus.php`, `Enrolments.php` (service), `PriceShares.php`, `EnrolmentController.php`, `EnrolmentPolicy.php` ; composants `resources/js/components/sessions/enrolment-*.tsx` ; blocs « Formations suivies » (fiche personne) et « Dossiers » (fiche entreprise) via `Crm` timeline/blocs enregistrés par tag ou `UserPageExtensions`-like registre (pas d'import de Sessions dans Crm)
- Test: `tests/Feature/Sessions/EnrolmentsTest.php`, `PriceSharesTest.php`

**Interfaces:**
- `enrolments` : `id, tenant_id, person_id, training_session_id, dossier_id, dossier_line_id, status, waitlist_position, entered_on, left_on, abandon_reason_id, planned_hours, price_share (numeric(12,2), null en prix de groupe), bpf_category_id, adaptation_needs (text, donnée personnelle), notes, timestamps` ; unique `(training_session_id, person_id)` hors annulées.
- `EnrolmentStatus` : `enrolled` « Inscrit », `waitlisted` « En attente », `confirmed` « Confirmé », `abandoned` « Abandon », `cancelled` « Annulé ».
- `Enrolments::enrol(Dossier, DossierLine, TrainingSession, string $personId, array $extra): Enrolment` — refuse : ligne non `training` ou formation ≠ celle de la session ; session intra d'un autre contractant ; session annulée ; doublon. Inter complète (inscrits + confirmés ≥ capacity) → `waitlisted`.
- `Enrolments::promote(Enrolment)` (manuelle), `abandon(Enrolment, CarbonImmutable $leftOn, string $reasonId)` (garde sa part), `cancel(Enrolment)` (libère la place, sans promotion automatique).
- `PriceShares::recompute(DossierLine): void` — prix par stagiaire : `total_ht` réparti sur les inscriptions non annulées (abandons inclus), reste aux premières par `created_at, id` (Review Focus 3).

- [ ] **Step 1: Failing tests** : Review Focus 2 et 3 ; doublon refusé ; formation différente refusée ; abandon exige motif et date, garde la part ; la fiche personne liste ses inscriptions (visibles selon `SESSIONS_VIEW`) ; recherche de stagiaire (Review Focus 5) depuis l'écran d'inscription.
- [ ] **Step 2–4:** RED → implement → verts.
- [ ] **Step 5: Commit** `feat(sessions): enrolments, waiting list and price shares`

### Task 6: Financements

**Files:**
- Create: migration `fundings` ; `Sessions/Fundings/Funding.php`, `FundingStatus.php`, `FundingWriter.php`, `FundingBalance.php`, `FundingController.php` ; section « Financements » de la page dossier
- Test: `tests/Feature/Sessions/FundingsTest.php`, `FundingBalanceTest.php`

**Interfaces:**
- `fundings` : `id, tenant_id, dossier_id, funder_organisation_id | funder_person_id (CHECK un seul), funding_type_id, dossier_line_id (nullable), enrolment_id (nullable) (CHECK : pas les deux), amount numeric(12,2) ≥ 0, status, agreement_number, agreed_on, notes, timestamps`.
- `FundingStatus` : `to_request` « À demander », `requested` « Demandé », `agreed` « Accordé », `refused` « Refusé ».
- `FundingBalance::for(Dossier): array{price_cents:int, funded_cents:int, remaining_cents:int, lines: array<string, array{price_cents, funded_cents, remaining_cents}>, enrolments: array<string, array{price_cents, funded_cents, remaining_cents}>}` — financé = non refusés ; `lines`/`enrolments` présents seulement pour les cibles visées ; reste négatif = « Financements supérieurs au prix ».
- Un financement visant une ligne/inscription d'un autre dossier → 422.

- [ ] **Step 1: Failing tests** : Review Focus 1 ; OPCO 2 100 + entreprise 900 sur 3 000 → reste 0 ; OPCO refusé → reste 2 100 ; financeur personne accepté ; financement par inscription : reste par inscription ; montants au centime.
- [ ] **Step 2–4:** RED → implement → verts.
- [ ] **Step 5: Commit** `feat(sessions): fundings and balance`

### Task 7: Créer le dossier depuis un devis accepté

**Files:**
- Create: `Sessions/Dossiers/DossierFromQuote.php`, `DossierFromQuoteController.php`, page assistant `resources/js/pages/sessions/dossiers/from-quote.tsx`
- Modify: page devis du Crm (bouton « Créer le dossier » / lien « Voir le dossier » — le Crm reçoit l'URL et l'existence via un registre, sans importer Sessions)
- Test: `tests/Feature/Sessions/DossierFromQuoteTest.php`, `tms/e2e/dossier.spec.ts`

**Interfaces:**
- `DossierFromQuote::prepare(string $quoteId, User): array` (récapitulatif + sessions existantes compatibles par ligne) ; `create(string $quoteId, array $plan, User): Dossier` — `plan.lines[i] = {session_id | new_session: {...}, pricing_mode, people: list<person_id | new_person: {...}>}` ; tout dans une transaction ; verrou sur le devis + unique `quote_id` (Review Focus 4 : second appel → 409 « Un dossier existe déjà pour ce devis. » avec lien) ; financement initial « Entreprise » (ou « Personnel » pour un particulier) du montant total, à l'état « À demander ».
- Lignes sans formation → lignes `fee`, sans session.

- [ ] **Step 1: Failing tests** : création complète (2 lignes formation + 1 frais, 1 session existante + 1 nouvelle intra, 3 stagiaires dont 1 créé) ; Review Focus 4 ; devis non accepté → 404 ; sans `sessions.dossiers.update` → 403 ; e2e : depuis un devis accepté, assistant jusqu'au dossier affiché avec ses stagiaires et « Reste à financer : 0,00 € ».
- [ ] **Step 2–4:** RED → implement → verts (suite, front, e2e).
- [ ] **Step 5: Commit** `feat(sessions): dossier from an accepted quote`

### Task 8: Historique, RGPD, accueil, documentation, version

**Files:**
- Create: fournisseurs d'historique (`DossiersProvider`, `EnrolmentsProvider`) taggés `Timeline::TAG` ; `Sessions/Gdpr/EnrolmentAnonymisation.php` ; carte d'accueil « Sessions qui démarrent dans les 7 jours »
- Modify: `Crm/Gdpr/Anonymiser` (appel par registre), export RGPD d'une personne (ses inscriptions), `LinkedData::register('sessions.enrolments', …)`, `tms/docs/` (`sessions.md` nouveau guide, `admin.md` référentiels et règle d'abandon, `data-dictionary.md`), `CLAUDE.md` (section module Sessions), `tms/VERSION` → `0.6.0`
- Test: `tests/Feature/Sessions/GdprTest.php`, `TimelineTest.php`, `HomeTest.php`

- [ ] **Step 1: Failing tests** : l'anonymisation efface `adaptation_needs` et `notes` des inscriptions ; l'export RGPD contient les inscriptions ; une personne inscrite n'est pas supprimée par l'annulation d'un import (`LinkedData`) ; l'historique du dossier et de la fiche personne montre création, transitions, inscriptions, abandons ; carte d'accueil filtrée par périmètre.
- [ ] **Step 2–4:** RED → implement → verts.
- [ ] **Step 5:** vérification complète : suite, `--group=slow`, pint, phpstan, lint/types/build, e2e, bats.
- [ ] **Step 6: Commit** `feat(sessions): history, GDPR and home card` puis `docs: lot 2a` et `chore: 0.6.0`.

---

## Hors périmètre (sous-lots suivants)

Créneaux, intervenants, salles (2b) ; convention, convocation, attestations, « Bon pour accord » en ligne (2c) ; émargement, heures réalisées, SoWeSign (2d) ; factures et dédit (lot 3) ; reprise Formevol.
