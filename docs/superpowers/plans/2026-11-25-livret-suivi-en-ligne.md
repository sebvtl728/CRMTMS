# Livret d'apprentissage, partie B (B1 + B2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Les formateurs suivent le livret d'apprentissage dans le TMS (grille centre, visites, observations centre, visa) et l'apprenti puis le tuteur remplissent et signent chaque mois leur page par un lien sécurisé ; le livret PDF imprime tout.

**Architecture:** Tout dans `tms/app/Modules/Admissions/Booklet/` (tables `booklet_months`, `booklet_links`, `booklet_evaluations`, `booklet_visits`), nouveau droit `admissions.booklet.manage` à périmètre (dont « Ses séances »), lecture de Sessions par `Sessions\Public\Admission`, signature sur le modèle de `Sessions\Attendance\Signing\RemoteCatchUp` (sha256 du jeton, code HMAC, `SignatureDrawing`). `BookletPdf` (partie A) lit les nouvelles tables.

**Tech Stack:** Laravel 12 / PHP 8.4, PostgreSQL 17 (RLS), Inertia + React 19 / TypeScript, Pest, Gotenberg.

**Spec:** `docs/superpowers/specs/2026-11-24-livret-suivi-en-ligne-design.md` (fait autorité ; partie A : `2026-11-23-livret-apprentissage-design.md`).

## Global Constraints

- Toute table : `tenant_id uuid not null`, `Rls::enable`, modèle `BelongsToTenant`, UUID v7 ; migrations via `--database=pgsql_owner`.
- Français pour tout texte visible ; jours et mois de **Paris** (`CarbonImmutable::now('Europe/Paris')`).
- Lien : 40 caractères aléatoires, seul `token_hash` = sha256 gardé, valable **30 jours** (jusqu'à 23 h 59 Paris du 30e jour), un seul lien ouvert par (candidature, mois, signataire).
- Code : 6 chiffres, `hash_hmac('sha256', $link->id.'|'.$code, config('app.key'))`, **10 minutes**, **5 essais**, **un envoi par minute** ; messages repris de `RemoteCatchUp` (`WAIT`, `NO_CODE`, `EXPIRED`, `TOO_MANY`, `WRONG`).
- Dessin : `Core\Signatures\SignatureDrawing` (PNG, 200 Ko, non vide), stocké par `TenantStorage` zone `booklet_signatures`.
- Textes : 2 000 caractères par cadre « Semaine » ; points de visite : 3 000.
- Semaines : clés `w1`, `w2`, `w3`, `w45` (libellés « Semaine 1 », « Semaine 2 », « Semaine 3 », « Semaine 4-5 »).
- Valeurs de grille : `A`, `ECA`, `NA` ; côtés `centre`, `company`.
- Admissions ne nomme aucune table de Sessions, du CRM ni du Catalogue ; les 4 nouvelles tables s'ajoutent à `ADMISSIONS_TABLES_LIST` de `tests/Arch/ModuleBoundariesArchTest.php`.
- Données personnelles hors audit (`$auditPersonal`) ; e-mails et jetons jamais journalisés ; erreurs affichées par `UserFacingHttpException` ou `ValidationException`.
- Ne pas toucher `resources/js/pages/admissions/candidatures/show.tsx` (autre session en cours) ; le lien « Suivi du livret » sur la carte Alternance est la tâche 11, à faire après fusion de `claude/admiring-tharp-1e2de1`.
- Tests : `docker compose exec app php artisan test --filter=Booklet` (jamais deux suites Pest en même temps qu'une autre session) ; front : `docker compose run --rm vite sh -c "npm run lint && npm run types"`.
- Commits en anglais, conventionnels, trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>` ; pas de push sans accord.

## Review Focus

- **Contrat raccourci ou dates changées** après des saisies : les mois hors contrat ne s'affichent plus, mais leurs lignes restent et ne cassent ni la page ni le PDF → test tâche 4.
- **Tuteur changé dans le contrat** pendant qu'un lien tuteur est ouvert : le lien ouvert reste valide pour la personne d'origine (nom figé), le suivant part au nouveau tuteur → test tâche 7.
- **Double envoi concurrent / double clic « Signer »** : verrou de ligne du lien, une seule signature, le second appel reçoit 409 → test tâche 8.
- **Grille de la formation modifiée** après évaluations : savoir-faire retirés ni affichés ni imprimés, compteur « N évaluations portent sur des savoir-faire retirés » → test tâche 2.
- **Candidature à la corbeille ou anonymisée** avec liens ouverts : même 404 publique, pages TMS 404 → tests tâches 8 et 10.

---

## B1 — saisie interne

### Task 1: Schéma, modèles, droit et périmètre

**Files:**
- Create: `tms/database/migrations/2027_01_31_000001_create_booklet_follow_up_tables.php`
- Create: `tms/app/Modules/Admissions/Booklet/BookletMonth.php`, `BookletLink.php`, `BookletEvaluation.php`, `BookletVisit.php`, `BookletAccess.php`
- Modify: `tms/app/Modules/Admissions/Permissions.php`, `tms/app/Modules/Admissions/AdmissionsServiceProvider.php` (morph aliases, scopes « Ses séances »), `tms/app/Modules/Sessions/Public/Admission.php`, `tests/Arch/ModuleBoundariesArchTest.php`
- Test: `tms/tests/Feature/Admissions/BookletAccessTest.php`

**Interfaces:**
- Produces: `Permissions::BOOKLET_MANAGE = 'admissions.booklet.manage'` (groupe « Livret d'apprentissage », scopé, scopes en plus : `[AccessScope::Trainer]`) ; `BookletAccess::candidatures(Builder $q, User $u): Builder` (candidatures avec contrat, hors corbeille, dans le périmètre) ; `BookletAccess::allows(User $u, Candidature $c): bool` ; `Admission::trainerDossierIds(User $u): Builder` (sous-requête des `dossier_id` dont une inscription vise une session ayant une séance non annulée du formateur lié à l'utilisateur ; `whereRaw('false')` sans formateur) ; route binding `bookletCandidature` → 404 hors périmètre ; modèles avec casts (`apprentice_entries`, `observations` en array ; dates immuables) ; `BookletMonth::PLACES = ['company', 'centre']`, `BookletLink::SIGNERS = ['apprentice', 'tutor']`, `BookletEvaluation::LEVELS = ['A', 'ECA', 'NA']`.

Colonnes : celles de la spec § « Données ». Ajouts : `booklet_months.apprentice_ip`, `apprentice_agent`, `tutor_ip`, `tutor_agent`, `apprentice_link_id`, `tutor_link_id` ; `booklet_links.closed_at`, index unique partiel `(candidature_id, month, signer) where closed_at is null`. Défauts du droit : Formateur → `Trainer` ; AdminOrganisme → `all` ; ResponsableDas → `das` ; ChargeRelations, Assistant → `all` ; Commercial, Direction → aucun.

- [ ] **Step 1: Write the failing tests**

```php
it('lets a trainer reach only the apprentices of his séances', function () {
    $c = bookletApprentice();                       // séances de Laurent Cauret
    $trainer = bookletTrainerUser('Cauret');         // helper : utilisateur Formateur lié au formateur « Cauret »
    $other = bookletTrainerUser('Autre');
    expect(BookletAccess::allows($trainer, $c))->toBeTrue()
        ->and(BookletAccess::allows($other, $c))->toBeFalse();
    $this->actingAs($other)->get("/admissions/livret/{$c->id}")->assertNotFound();
});
it('keeps the candidature itself out of reach of a trainer', function () {
    $c = bookletApprentice();
    $this->actingAs(bookletTrainerUser('Cauret'))->get("/admissions/candidatures/{$c->id}")->assertNotFound();
});
it('grants the new right once per role by default', function () {
    expect(Scopes::of(adUserWithRole(Role::Formateur), Permissions::BOOKLET_MANAGE))->toBe(AccessScope::Trainer)
        ->and(Scopes::of(admin(), Permissions::BOOKLET_MANAGE))->toBe(AccessScope::All);
});
```

- [ ] **Step 2: Run** `docker compose exec app php artisan test --filter=BookletAccess` — Expected: FAIL (`Permissions::BOOKLET_MANAGE` indéfini).
- [ ] **Step 3: Implement** migration, modèles, droit (déclarer `Trainer` en scope en plus comme `Sessions\Permissions::TRAINER_SCOPED`), `Admission::trainerDossierIds`, `BookletAccess` (switch sur `Scopes::of` : `all` ; `das` = `das_id` suivis comme `AdmissionsVisibility` ; `own` = responsable ou créateur ; `trainer` = `dossier_id in trainerDossierIds`), binding. Ajouter les 4 tables à `ADMISSIONS_TABLES_LIST`. Helper `bookletTrainerUser()` dans `tests/Feature/Admissions/Support/booklet.php`.
- [ ] **Step 4: Run** `php artisan migrate --database=pgsql_owner` puis `php artisan test --filter='BookletAccess|ArchTest|ModuleBoundaries'` — Expected: PASS.
- [ ] **Step 5: Commit** `feat(admissions): booklet follow-up tables and right`

### Task 2: Grille A / ECA / NA côté centre

**Files:**
- Create: `tms/app/Modules/Admissions/Booklet/BookletGrid.php`, `BookletGridController.php`
- Modify: `tms/routes/admissions.php`
- Test: `tms/tests/Feature/Admissions/BookletGridTest.php`

**Interfaces:**
- Consumes: `BookletAccess`, `Trainings::competences(string $id)`.
- Produces: `BookletGrid::skillKey(string $at, string $cp, string $skill): string` (sha256 de `mb_strtolower` des trois titres, espaces réduits, joints par `|`) ; `BookletGrid::tree(Candidature $c): array{activities: list<array{title, competences: list<array{title, skills: list<array{key, title, centre: ?string, company: ?string}>}>}>, orphans: int}` ; `BookletGrid::set(Candidature $c, string $key, string $side, ?string $level, ?User $by): void` (clé inconnue de la grille courante → `ValidationException` « Savoir-faire inconnu » ; `null` supprime) ; route `PUT /admissions/livret/{bookletCandidature}/grille` (`can:admissions.booklet.manage`, body `key`, `level`) côté `centre` seulement.

- [ ] **Step 1: Write the failing tests**: `it('marks a skill on the centre side and reads it back in the tree')` (`level` `ECA` → `tree()[...]['centre'] === 'ECA'`, `company` null) ; `it('refuses the company side and unknown levels from the TMS')` (422) ; `it('hides the marks of skills removed from the training and counts them')` (évaluer « Peser les produits », remplacer la grille par une autre, `tree()['orphans'] === 1`, aucune ligne « Peser les produits »).
- [ ] **Step 2: Run** `--filter=BookletGrid` — FAIL.
- [ ] **Step 3: Implement** (upsert sur l'unique `(candidature_id, skill_key, side)`, `Auditable`).
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat(admissions): centre competence grid of the booklet`

### Task 3: Visites en entreprise

**Files:**
- Create: `tms/app/Modules/Admissions/Booklet/BookletVisits.php`, `BookletVisitController.php`
- Modify: `tms/routes/admissions.php`
- Test: `tms/tests/Feature/Admissions/BookletVisitsTest.php`

**Interfaces:**
- Produces: `BookletVisits::list(Candidature $c): list<array{id, visited_on: string (Y-m-d), points: string, trainer: ?string}>` (ordre date croissante) ; `create(Candidature $c, array $data, User $by)`, `update(BookletVisit $v, array $data, User $by)`, `delete(BookletVisit $v, User $by)` (logique) ; validation : `visited_on` date requise ≤ aujourd'hui (Paris), `points` requis ≤ 3000, `trainer_user_id` facultatif (défaut : l'utilisateur) ; routes `POST/PUT/DELETE /admissions/livret/{bookletCandidature}/visites[/{visit}]` (visite d'une autre candidature → 404).

- [ ] **Step 1: Write the failing tests**: création puis liste (« Points abordés : hygiène ») ; date future refusée (« La date ne peut pas être dans le futur. ») ; suppression logique ; `points` absent de `audit_logs` (`[donnée personnelle]`).
- [ ] **Step 2: Run** `--filter=BookletVisits` — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat(admissions): company visits of the booklet`

### Task 4: Mois de suivi : observations centre, visa, « Signé sur papier »

**Files:**
- Create: `tms/app/Modules/Admissions/Booklet/BookletMonths.php`, `BookletMonthController.php`
- Modify: `tms/app/Modules/Sessions/Public/Admission.php` (rien si `booklet()` suffit), `tms/routes/admissions.php`
- Test: `tms/tests/Feature/Admissions/BookletMonthsTest.php`

**Interfaces:**
- Consumes: `Admission::booklet($dossierId, $personId)['months']` (minutes en centre par `Y-m`).
- Produces: `BookletMonths::months(Candidature $c, ?CarbonImmutable $today = null): list<array{month: 'Y-m', label: 'Janvier 2027', places: list<'company'|'centre'>, pages: array<place, array{apprentice_entries, observations, apprentice: SignState, tutor: ?SignState, visa: ?array{by, at}}>, links: array<signer, ?array{email, sent_at, expires_on, email_status}>}>` — mois du contrat de son début au mois courant (Paris), plus récent d'abord ; `SignState = array{status: 'none'|'sent'|'signed'|'paper', at: ?string, name: ?string}` ; `BookletMonths::page(Candidature $c, string $month, string $place): BookletMonth` (firstOrCreate, mois hors contrat ou futur → 409 « Ce mois n'est pas dans le contrat. ») ; `saveObservations(BookletMonth $m, array $entries, User $by)` (place `centre` seulement, refus si visé : 409 « Retirez d'abord le visa. ») ; `visa(BookletMonth $m, User $by)`, `unvisa(BookletMonth $m, User $by)` ; `markPaper(Candidature $c, string $month, string $signer, User $by)` (pose `*_paper` sur les pages concernées : apprenti = toutes les pages du mois, tuteur = page `company` ; ferme le lien ouvert). Routes `PUT .../mois/{month}/{place}/observations`, `POST|DELETE .../mois/{month}/{place}/visa`, `POST .../mois/{month}/papier/{signer}` (`month` `\d{4}-\d{2}`).

- [ ] **Step 1: Write the failing tests**: `it('lists the months of the contract up to the current month of Paris')` (contrat 04/01–31/03/2027, aujourd'hui 15/02/2027 → `['2027-02', '2027-01']`, janvier places `company`,`centre`) ; `it('saves centre observations then freezes them with the visa')` ; `it('refuses observations on the company page')` (422) ; `it('marks the apprentice part signed on paper and closes its open link')` ; `it('keeps rows of months dropped from a shortened contract out of the list without failing')`.
- [ ] **Step 2: Run** `--filter=BookletMonths` — FAIL.
- [ ] **Step 3: Implement** (`observations` et `apprentice_entries` dans `$auditPersonal`, chaque cadre ≤ 2000).
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat(admissions): monthly booklet pages, centre observations and visa`

### Task 5: Écrans « Mes apprentis » et « Livret de … »

**Files:**
- Create: `tms/app/Modules/Admissions/Booklet/BookletPageController.php`, `tms/resources/js/pages/admissions/booklet/index.tsx`, `tms/resources/js/pages/admissions/booklet/show.tsx`, `tms/resources/js/components/admissions/booklet-grid.tsx`, `booklet-months.tsx`, `booklet-visits.tsx`
- Modify: `tms/routes/admissions.php`, `tms/resources/js/components/app-sidebar.tsx` (entrée « Mes apprentis » pour qui a le droit, y compris `trainer_only`), `tms/app/Http/Middleware/HandleInertiaRequests.php` (drapeau `can.booklet`), route PDF `livret.pdf` acceptant aussi `admissions.booklet.manage` sur le périmètre du livret
- Test: `tms/tests/Feature/Admissions/BookletPagesTest.php`

**Interfaces:**
- Consumes: tâches 1 à 4.
- Produces: `GET /admissions/livret` (`index` : `rows: list<{id, apprentice, employer, training, pending: int}>`, paginé 50, recherche par nom) ; `GET /admissions/livret/{bookletCandidature}` (`show` : `candidature: {id, number, apprentice, employer, tutor, training, contract: {starts_on, ends_on}}`, `months`, `grid`, `visits`, `trainers: list<{id, label}>`, `onglet` via `?onglet=suivi|grille|visites`). Aucune donnée sensible ni champ de la candidature au-delà.
- `pending` = parts non signées (ni papier) des mois passés.

- [ ] **Step 1: Write the failing tests**: Inertia `admissions/booklet/index` pour un formateur ne liste que ses apprentis ; `show` expose `months`, `grid`, `visits` et pas `rqth`, `interview_report` ; le formateur télécharge `livret.pdf` de son apprenti, pas d'un autre (404).
- [ ] **Step 2: Run** `--filter=BookletPages` — FAIL.
- [ ] **Step 3: Implement** contrôleur + pages (onglets shadcn ; grille : boutons A / ECA / NA par ligne côté centre, colonne entreprise en lecture ; mois : cartes par mois, textes en lecture, `Textarea` observations centre, bouton « Viser » / « Retirer le visa », « Signé sur papier » ; visites : tableau + dialogue). Après chaque modification : `router.reload({ only: [...] })`.
- [ ] **Step 4: Run** tests + `npm run lint && npm run types` — PASS.
- [ ] **Step 5: Commit** `feat(admissions): trainer screens of the booklet follow-up`

### Task 6: Le PDF reprend les saisies

**Files:**
- Modify: `tms/app/Modules/Admissions/Booklet/BookletPdf.php`, `tms/resources/views/documents/apprenticeship-booklet.blade.php`
- Test: `tms/tests/Feature/Admissions/BookletPdfTest.php` (ajouts)

**Interfaces:**
- Consumes: `BookletMonths::months()` (tous les mois du contrat ici, pas seulement jusqu'au mois courant : ajouter le paramètre `bool $wholeContract = false`), `BookletGrid::tree()`, `BookletVisits::list()`, dessins lus par `TenantStorage` en data URI.
- Produces: pages mensuelles remplies (textes, observations, « Signé en ligne le JJ/MM/AAAA à HH:MM » sous le dessin, « Signé sur papier », « Visé par <nom> le JJ/MM/AAAA », case représentant légal vide) ; grille avec « X » ; visites saisies + lignes vides jusqu'à 6 (au moins 2).

- [ ] **Step 1: Write the failing tests**: `it('prints what was typed: weeks, observations, visa, marks and visits')` (`Semaine 1` « Mise en place du buffet », « Visé par », « X », « hygiène ») ; `it('prints the drawing and the time of an online signature')` (`data:image/png;base64,` et « Signé en ligne le ») ; `it('prints « Signé sur papier » and leaves the box empty')`.
- [ ] **Step 2: Run** `--filter=BookletPdf` — FAIL.
- [ ] **Step 3: Implement** (`{{ }}` seulement ; dessins en `<img src="{{ $uri }}">`).
- [ ] **Step 4: Run** — PASS (anciens tests de A compris).
- [ ] **Step 5: Commit** `feat(admissions): the booklet PDF prints the online follow-up`

## B2 — liens mensuels

### Task 7: Envoi des liens (apprenti, tuteur, enchaînement)

**Files:**
- Create: `tms/app/Modules/Admissions/Booklet/BookletLinks.php`, `BookletMail.php` (modèles), `SendBookletLink.php`, `BookletLinkController.php`
- Modify: `AdmissionsServiceProvider::register()` (modèles d'e-mails), `tms/routes/admissions.php`
- Test: `tms/tests/Feature/Admissions/BookletLinksTest.php`

**Interfaces:**
- Consumes: `BookletMonths::page()`, `Crm\Public\Fiches::contactCard('person', $id)` (e-mail, nom), `AlternanceContract::tutor_person_id`.
- Produces: `BookletLinks::send(Candidature $c, string $month, string $signer, ?User $by): BookletLink` (verrou de la candidature ; ferme le lien ouvert ; e-mail figé ; jeton `Str::random(40)` ; job après commit) ; `BookletLinks::find(string $token): ?BookletLink` (null si inconnu, expiré, fermé, candidature à la corbeille ou anonymisée, contrat absent) ; `BookletLinks::openFor(Candidature $c, string $month, string $signer): ?BookletLink` ; constantes de message `NO_EMAIL_APPRENTICE = 'Ajoutez l\'e-mail de l\'apprenti sur sa fiche.'`, `NO_EMAIL_TUTOR = 'Ajoutez l\'e-mail du tuteur sur sa fiche.'`, `NO_TUTOR = 'Indiquez le tuteur dans le contrat.'`, `FUTURE = 'Ce mois n\'a pas encore commencé.'`, `SIGNED = 'Cette part est déjà signée.'` (409) ; modèles `booklet_apprentice`, `booklet_tutor` (`{lien}` requis, variables de la spec) ; `SendBookletLink` (`TenantAwareJob`, `ShouldBeEncrypted`, `TenantMailer`, `email_status` `sent|failed`, motif fixe). Routes `POST .../mois/{month}/envoyer/{signer}` (« Envoyer » et « Renvoyer » = même route).

- [ ] **Step 1: Write the failing tests**: envoi apprenti → `booklet_links` 1 ligne, `token_hash` de 64 hex, `expires_at` 30 jours fin de journée Paris, job en file (`Queue::fake`) avec le jeton en clair seulement dans la charge ; « Renvoyer » ferme l'ancien ; refus sans e-mail / sans tuteur / mois futur / part signée ; `it('keeps the open link of the former tutor and sends the next one to the new tutor')`.
- [ ] **Step 2: Run** `--filter=BookletLinks` — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat(admissions): monthly booklet links for the apprentice and the tutor`

### Task 8: Remplir et signer par le lien (serveur)

**Files:**
- Create: `tms/app/Modules/Admissions/Booklet/BookletAnswering.php`, `PublicBookletController.php`, `SendBookletCode.php`
- Modify: `tms/routes/admissions.php` (routes publiques hors `auth`), `tms/app/Providers/RateLimitServiceProvider.php` (`booklet-public` 240, `booklet-public-post` 60, clés jeton + IP comme `attendance-signing`)
- Test: `tms/tests/Feature/Admissions/BookletPublicTest.php`

**Interfaces:**
- Consumes: `BookletLinks::find`, `BookletGrid::tree/set`, `SignatureDrawing`, `BookletLinks::send` (enchaînement).
- Produces: `GET livret/{token}` (Inertia `public/booklet` : `state: 'open'|'signed'|'invalid'`, `signer`, `month_label`, `organisme`, `apprentice` (prénom + initiale du nom pour le tuteur, prénom nom pour l'apprenti), `employer`, `training`, `pages` (apprenti : toutes les places ; tuteur : `company` seulement, avec les textes de l'apprenti en lecture), `grid` (tuteur seulement, côté `company`)) ; `POST livret/{token}/save` (`entries.{place}.{w1..w45}` ; tuteur : `observations.{w1..w45}` et `marks: list<{key, level}>`) ; `POST livret/{token}/code` ; `POST livret/{token}/sign` (`code`, `drawing`). `BookletAnswering::save(BookletLink $l, array $data)`, `sendCode(BookletLink $l)`, `sign(BookletLink $l, string $code, string $drawing, string $ip, string $agent)` : verrou du lien, code vérifié, dessin archivé, signature posée (apprenti : toutes les pages du mois ; tuteur : page `company`), lien fermé, puis, si apprenti et aucun lien tuteur ouvert ni part tuteur signée et tuteur avec e-mail : `BookletLinks::send(..., 'tutor', null)`. Tout cas invalide → même page 404 (`state: 'invalid'`, statut 404). Modèle `booklet_code` (`{code}` requis).

- [ ] **Step 1: Write the failing tests**: apprenti enregistre `w1` puis relit ; code (5 essais, expiré, attente d'une minute) ; signature → `apprentice_signed_at`, lien fermé, lien tuteur envoyé ; tuteur ne voit pas la page centre (`pages` sans `centre`) ni les données sensibles ; tuteur coche `ECA` → `BookletGrid::tree()` côté `company` ; `it('signs once under two concurrent requests')` (second appel 409) ; lien expiré / fermé / candidature à la corbeille → 404 identique ; throttle 429 par jeton + IP.
- [ ] **Step 2: Run** `--filter=BookletPublic` — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat(admissions): fill and sign the monthly booklet page by link`

### Task 9: Page publique (front)

**Files:**
- Create: `tms/resources/js/pages/public/booklet.tsx`
- Modify: (réutiliser le pad de dessin de `resources/js/pages/public/attendance-signing.tsx` : l'extraire en `resources/js/components/signature-pad.tsx` s'il n'existe pas déjà)
- Test: `tms/tests/Feature/Admissions/BookletPublicTest.php` (rendu Inertia déjà couvert) ; lint + types

**Interfaces:**
- Consumes: props de la tâche 8.
- Produces: page mobile d'abord : en-tête, cadres « Semaine… » (`Textarea`, enregistrement à la sortie du champ et toutes les 10 s si modifié, « Enregistré » discret), grille tuteur (A / ECA / NA par savoir-faire), bloc « Signer » (« Recevoir mon code », champ code, pad, « Signer »), état signé « Merci, votre page de <mois> est signée. ».

- [ ] **Step 1: Implement** la page.
- [ ] **Step 2: Run** `npm run lint && npm run types && npm run build` — PASS.
- [ ] **Step 3: Vérifier à la main** dans le navigateur intégré (lien de dev généré par `tinker`), téléphone 375 px.
- [ ] **Step 4: Commit** `feat(admissions): public page of the monthly booklet follow-up`

### Task 10: RGPD, documentation

**Files:**
- Modify: `tms/app/Modules/Admissions/Gdpr/CandidatureAnonymisation.php`, `PersonCandidaturesExport.php`, `tms/docs/admissions.md`, `tms/docs/data-dictionary.md`, `tms/docs/admin.md` (§ rôles : nouveau droit), `CLAUDE.md` (section Admissions : 2 lignes « Livret »), `CHANGELOG.md`
- Test: `tms/tests/Feature/Admissions/BookletGdprTest.php`

**Interfaces:**
- Produces: anonymisation : `apprentice_entries`, `observations`, `points` vidés, fichiers de dessin supprimés, `*_ip`, `*_agent`, `*_signer_name`, `booklet_links.email` effacés, liens fermés ; évaluations gardées. Export : clé `livret` (`months`, `evaluations`, `visits`).

- [ ] **Step 1: Write the failing tests**: après anonymisation, fichier de dessin absent (`Storage::assertMissing`), textes nuls, lien → 404 ; export contient « Mise en place du buffet ».
- [ ] **Step 2: Run** `--filter=BookletGdpr` — FAIL.
- [ ] **Step 3: Implement** + docs.
- [ ] **Step 4: Run** `--filter=Booklet`, `pint`, `phpstan` — PASS.
- [ ] **Step 5: Commit** `feat(admissions): anonymise and export the booklet follow-up`

### Task 11 (après fusion de la branche « Suivi de formation »): lien sur la carte Alternance

**Files:** Modify: `tms/resources/js/pages/admissions/candidatures/show.tsx` (carte Alternance : bouton « Suivi du livret » vers `/admissions/livret/{id}` si `can.booklet` et contrat).

- [ ] **Step 1:** Ajouter le bouton ; **Step 2:** lint + types ; **Step 3:** Commit `feat(admissions): open the booklet follow-up from the alternance card`
