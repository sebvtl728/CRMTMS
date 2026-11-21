# Émargement en ligne — plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (choisi : exécution inline). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal :** les stagiaires signent chaque demi-journée depuis un QR code, le formateur valide, les présences se remplissent, une feuille PDF signée est archivée.

**Architecture :** extension du module Sessions (`app/Modules/Sessions/Attendance/Signing/`) : créneaux (session × jour × matin/après-midi), signatures, feuilles signées ; écriture des présences par `Attendances` ; page publique à jeton (`emargement/{token}`) ; porte publique `Sessions\Public\AttendanceSigning` pour un futur connecteur SoWeSign.

**Tech Stack :** Laravel 12, Pest, PostgreSQL RLS, Inertia React 19 / TS, Gotenberg (`PdfRenderer`), `bacon/bacon-qr-code` (déjà là).

**Spec :** `docs/superpowers/specs/2026-11-20-emargement-design.md` (fait autorité).

## Global Constraints

- Toute table : `tenant_id uuid not null`, `BelongsToTenant`, `Rls::enable` ; migrations via `--database=pgsql_owner` uniquement ; jamais `migrate:fresh` sur la base de dev.
- Tous les messages utilisateur en français ; erreurs affichées = `UserFacingHttpException` ou validation.
- Coupure des demi-journées : **13:00** ; matin = séance avec `start < 13:00`, après-midi = `end > 13:00`.
- Jeton : 40 caractères `[A-Za-z0-9]`, seul `sha256` stocké ; utilisable seulement si créneau `open` **et** `date` = aujourd'hui (Paris).
- Lien invalide (inconnu, mal formé, fermé, validé, autre jour, session à la corbeille ou annulée) : **même page 404** « Ce lien d'émargement n'est plus valide. Demandez au formateur. »
- Page publique : noms = prénom + initiale du nom (« Léa D. »), homonymes « Léa D. (2) » ; jamais nom complet, e-mail, note, besoin d'adaptation.
- Dessins : `Core\Signatures\SignatureDrawing::decode` ; fichiers par `TenantStorage` zone **`attendance_signatures`** (nouvelle) ; feuilles signées zone `attendance_sheets`.
- Journal : `drawing_path`, `ip`, `user_agent`, `trainer_signature_path` dans `$auditPersonal` ; aucun nom de stagiaire.
- Message de validation par un formateur non habilité : « Une autre séance de cette demi-journée est animée par un autre formateur : un gestionnaire des présences la valide. »
- Tests locaux : projet Compose `tmse` (`COMPOSE_PROJECT_NAME=tmse docker compose exec -T app …`) ; tests ciblés + `tests/Arch` ; jamais deux suites Pest en parallèle ; e2e sous verrou `mkdir /Users/sebastienveitl/Desktop/CRM/.superpowers/e2e.lock`.
- Commits anglais conventionnels + `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Ne pas toucher Finance ni questionnaires.

Alias de commande utilisé ci-dessous : `T="COMPOSE_PROJECT_NAME=tmse docker compose exec -T app php artisan test"`.

## Review Focus

1. Classe entière derrière **une seule IP** (wifi du centre) : 15 stagiaires × interrogation ne doivent pas déclencher le throttle → limite par jeton + IP généreuse (Task 5 : test 20 signatures d'IP identique passent).
2. Deux stagiaires choisissent **le même nom** en même temps : un seul gagne, l'autre reçoit 409 « Ce nom a déjà signé. » (Task 3 : contrainte unique + test).
3. Créneau demandé sans séance dans cette demi-journée (séance 9 h–12 h, « après-midi ») ou séance annulée → 409 en français (Task 3).
4. Stagiaire entré tard / sorti ce jour-là : absent de la liste publique et refusé à la signature (Task 3, règle `Attendances::expected`).
5. Séance déplacée après validation : `Attendances::review` existant garde la main (les lignes `source = signing` suivent la même révision) ; la revalidation est impossible, la correction se fait dans la grille (Task 4 : test que `review` ne casse pas une ligne `signing`).

---

### Task 1 : Schéma et modèles

**Files :**
- Create : `database/migrations/2027_01_25_000001_create_attendance_signing_tables.php`
- Create : `app/Modules/Sessions/Attendance/Signing/SignSlot.php`, `Signing/AttendanceSignature.php`, `Signing/SignedSheet.php`
- Modify : `app/Modules/Core/Tenancy/TenantStorage.php` (AREAS + `attendance_signatures`), `app/Modules/Sessions/SessionsServiceProvider.php` (MorphAliases), `app/Modules/Sessions/Attendance/Attendance.php` (`source` fillable, `SOURCE_SIGNING = 'signing'`)
- Test : `tests/Feature/Sessions/Signing/SchemaTest.php` + `tests/Arch` existants

**Interfaces — Produces :**
- `attendance_sign_slots` : `id uuid`, `tenant_id`, `training_session_id` (fk), `date date`, `half varchar(10)` CHECK in (`morning`,`afternoon`), `status varchar(10)` CHECK in (`open`,`closed`,`validated`), `token_hash char(64) null unique`, `opened_at`, `opened_by` (fk users null), `validated_at null`, `validated_by null`, `trainer_name varchar(200) null`, `validator_role varchar(10) null` CHECK (`trainer`,`manager`), `trainer_signature_path varchar(300) null`, `trainer_signature_sha256 char(64) null`, timestamps ; unique (`tenant_id`,`training_session_id`,`date`,`half`).
- `attendance_signatures` : `id`, `tenant_id`, `slot_id` (fk cascade non : restrict), `enrolment_id` (fk), `drawing_path varchar(300) null`, `drawing_sha256 char(64)`, `signed_at timestamptz`, `source varchar(40) default 'online'`, `ip varchar(45) null`, `user_agent varchar(300) null`, timestamps ; unique (`slot_id`,`enrolment_id`).
- `attendance_signed_sheets` : `id`, `tenant_id`, `training_session_id`, `month date` (1er du mois), `path varchar(300)`, `pdf_sha256 char(64)`, `generated_by` (fk users null), timestamps ; index (`training_session_id`,`month`).
- `attendances.source varchar(20) null`.
- Modèles `SignSlot` (consts `MORNING`, `AFTERNOON`, `OPEN`, `CLOSED`, `VALIDATED`, `MORPH_ALIAS = 'attendance_sign_slot'`), `AttendanceSignature` (`MORPH_ALIAS = 'attendance_signature'`), `SignedSheet` (`MORPH_ALIAS = 'attendance_signed_sheet'`) : `Auditable`, `BelongsToTenant`, `HasUuids`, `$auditPersonal` selon Global Constraints, `date`/`month` castés `immutable_date`.

- [ ] **Step 1 :** test `SchemaTest` : les trois modèles se créent pour un tenant ; un second `AttendanceSignature` même (`slot_id`,`enrolment_id`) lève `UniqueConstraintViolationException` ; `half = 'evening'` lève une violation CHECK.
- [ ] **Step 2 :** `$T --filter=SchemaTest` → FAIL (table absente).
- [ ] **Step 3 :** migration (`Rls::enable` sur les trois tables) + modèles + zone + alias ; `COMPOSE_PROJECT_NAME=tmse docker compose exec -T app php artisan migrate --database=pgsql_owner` (base dev du conteneur `tmse`, propre au worktree).
- [ ] **Step 4 :** `$T --filter=SchemaTest` et `$T tests/Arch` → PASS.
- [ ] **Step 5 :** commit `feat: attendance signing tables and models`.

### Task 2 : Demi-journées (calcul pur)

**Files :** Create `app/Modules/Sessions/Attendance/Signing/Halves.php` ; Test `tests/Unit/Sessions/HalvesTest.php`

**Interfaces — Produces :**
- `Halves::CUT = '13:00'`
- `Halves::of(string $start, string $end): list<string>` — demi-journées chevauchées (`['morning']`, `['afternoon']`, ou les deux).
- `Halves::minutes(int $duration, string $start, string $end, list<string> $signed): int` — `round(duration × chevauchement_horloge(signed) / (end − start))`, borné à `[0, duration]`.

- [ ] **Step 1 :** tests : `of('09:00','12:30') === ['morning']` ; `of('13:00','17:00') === ['afternoon']` ; `of('09:00','17:00') === ['morning','afternoon']` ; `of('12:00','13:00') === ['morning']` ; `minutes(420,'09:00','17:00',['morning']) === 210` ; `minutes(420,'09:00','17:00',['morning','afternoon']) === 420` ; `minutes(180,'09:00','12:00',[]) === 0`.
- [ ] **Step 2 :** FAIL ; **Step 3 :** implémenter ; **Step 4 :** PASS ; **Step 5 :** commit `feat: half-day split of séances`.

### Task 3 : Ouvrir, fermer, signer, effacer

**Files :** Create `app/Modules/Sessions/Attendance/Signing/AttendanceSigning.php` (service interne), `Signing/SlotSeances.php` ; Test `tests/Feature/Sessions/Signing/SigningTest.php` (+ `tests/Feature/Sessions/Support/signing.php` : `sgOpen()`, `sgDrawing()` = PNG valide en data URI réutilisant le helper des tests de signatures du Core s'il existe, sinon un PNG GD 300×100 avec un trait)

**Interfaces — Produces :**
- `SlotSeances::of(SignSlot|array{session:string,date:string,half:string}): Collection<Seance>` — séances non annulées, non supprimées, de la session ce jour, chevauchant la demi-journée (`Halves::of`).
- `SlotSeances::expected(SignSlot): Collection<Enrolment>` — union (distincte) des `Attendances::expected($seance)`.
- `AttendanceSigning::open(TrainingSession $s, string $date, string $half, User $by): array{slot: SignSlot, token: string}` — 409 si : pas aujourd'hui (« L'émargement en ligne s'ouvre le jour même. »), session annulée, aucune séance (« Aucune séance ce [matin|après-midi]. »), mois clôturé (`Closures::assertOpen`), créneau validé (« Cette demi-journée est déjà validée. »). Rouvrir un `closed` ou réouvrir un `open` = nouveau jeton (l'ancien meurt).
- `AttendanceSigning::close(SignSlot $slot): void` — `status = closed`, `token_hash = null`.
- `AttendanceSigning::findByToken(string $token): ?SignSlot` — null pour tout cas invalide (Global Constraints).
- `AttendanceSigning::sign(SignSlot $slot, string $enrolmentId, string $drawingDataUri, string $ip, string $agent, string $source = 'online'): AttendanceSignature` — transaction, `lockForUpdate` du créneau, inscription attendue sinon 422 « Stagiaire introuvable sur cette demi-journée. », déjà signé → 409 « Ce nom a déjà signé. » ; fichier `TenantStorage::path('attendance_signatures', "<session>/<slot>/<id>.png")`, supprimé si la transaction échoue.
- `AttendanceSigning::erase(AttendanceSignature $sig): void` — 409 si créneau validé ; supprime fichier et ligne.
- `AttendanceSigning::publicNames(Collection<Enrolment>): array<string enrolmentId, string label>` — « Léa D. », homonymes numérotés par ordre d'id.

- [ ] **Step 1 :** tests (horloge figée `CarbonImmutable::setTestNow('2026-09-10 09:30', 'Europe/Paris')`) :
  - ouvre le matin d'une séance 09:00–17:00 → jeton 40 car., `token_hash = sha256(jeton)`, `findByToken` retrouve le créneau ;
  - réouvrir donne un autre jeton, l'ancien → null ;
  - `open` un autre jour → 409 ; après-midi d'une séance 09:00–12:00 → 409 ; séance annulée → 409 ; mois clôturé → 409 ;
  - `findByToken` null : jeton mal formé, créneau fermé, lendemain (setTestNow +1 jour), session à la corbeille ;
  - `sign` écrit fichier + sha256, `source = online` ; deuxième fois même inscription → 409 ; stagiaire entré le 14/09 → 422 ; inscription d'une autre session → 422 ;
  - `publicNames` : Léa Durand + Léa Dupont → « Léa D. », « Léa D. (2) » ;
  - journal : aucune ligne `audit_logs` ne contient « Durand » ni le chemin du dessin.
- [ ] **Step 2 :** FAIL ; **Step 3 :** implémenter ; **Step 4 :** `$T --filter=SigningTest` PASS ; **Step 5 :** commit `feat: open slots and record online signatures`.

### Task 4 : Validation et écriture des présences

**Files :** Modify `app/Modules/Sessions/Attendance/Attendances.php` (+ `fromSigning`, `record` remet `source = null`), Modify `Signing/AttendanceSigning.php` (+ `validate`) ; Test `tests/Feature/Sessions/Signing/ValidationTest.php`

**Interfaces — Produces :**
- `Attendances::fromSigning(Seance $seance, string $enrolmentId, string $status, int $minutes): void` — interne ; sous le même `lock()` ; ne touche **que** les lignes absentes ou `source = 'signing'` ; écrit `source = 'signing'`, `recorded_by` = utilisateur courant.
- `AttendanceSigning::validate(SignSlot $slot, string $drawingDataUri, User $by): SignSlot` — une transaction ; `validator_role` = `trainer` si `Trainers::forUser($by)` anime une séance du créneau, sinon `manager` ; 403 (message Global Constraints) si une séance du créneau n'est pas atteinte par `SessionsVisibility::seances(..., ATTENDANCE_MANAGE)` ; puis pour chaque séance de `SlotSeances::of` et chaque attendu : demi-journées de la séance (`Halves::of`) ∩ créneaux validés de ce jour ; signées = celles où il a une signature ; `present` si signées = toutes les demi-journées de la séance, sinon `partial` avec `Halves::minutes` si > 0 (borné `1 … duration−1`), sinon `absent`.
- `validate` accepte un créneau `open` ou `closed`, un jour passé ; refuse `validated` (409).

- [ ] **Step 1 :** tests :
  - séance 09:00–12:00, Léa signée, Paul non → validation : Léa `present` 180, Paul `absent` 0, `source = signing` ;
  - séance 09:00–17:00 (pause 60, durée 420) : matin validé (Léa signée) → Léa `partial` 210 ; après-midi validé (Léa signée) → `present` 420 ; Paul signé l'après-midi seulement → `partial` 210 ;
  - présence saisie à la main avant (`excused`) → inchangée après validation ;
  - `record` après validation remet `source` à null, une revalidation impossible (409) ;
  - mois clôturé entre ouverture et validation → 409, créneau toujours `open`, aucune présence ;
  - formateur A valide un créneau contenant la séance du formateur B → 403 message exact ; gestionnaire → OK, `validator_role = manager` ; formateur A seul → `trainer` ;
  - validation le lendemain (créneau resté ouvert) → OK ;
  - `Attendances::review` après déplacement de la séance garde `source = signing` et passe `needs_review`.
- [ ] **Step 2 :** FAIL ; **Step 3 :** implémenter ; **Step 4 :** `$T --filter='ValidationTest|AttendanceTest'` PASS ; **Step 5 :** commit `feat: validate a half-day and fill attendance from signatures`.

### Task 5 : HTTP (formateur et page publique)

**Files :**
- Create `Signing/SigningController.php` (authentifié), `Signing/PublicSigningController.php`, `Signing/SlotQr.php` (SVG bacon, comme `TwoFactorAuthenticator`)
- Modify `routes/sessions.php`, `app/Providers/RateLimitServiceProvider.php`, `app/Modules/Sessions/Attendance/AttendanceRows.php` (grid : `slots` du jour ; session : `slots` du mois)
- Test `tests/Feature/Sessions/Signing/SigningHttpTest.php`

**Interfaces — Produces (routes) :**
- `POST /sessions/{trainingSession}/signing-slots` (`date`, `half`) → 201 `{slot_id, url}` — `can:sessions.attendance.manage` + périmètre sur une séance du créneau (404 sinon).
- `GET /emargement/creneaux/{slot}` (Inertia `sessions/attendance-signing`) et `GET /emargement/creneaux/{slot}/state` (JSON : `status`, `url`, `qr_svg`, `rows: [{enrolment_id, name (complet, authentifié), signed_at|null, signature_id|null}]`, `can_validate`) ; `POST …/close` ; `DELETE …/signatures/{signature}` ; `POST …/validate` (`drawing`).
- Public : `GET /emargement/{token}` (Inertia `public/attendance-signing` : `organisme`, `session`, `date` « jeudi 10 septembre 2026 », `half_label` « Matin|Après-midi », `trainees: [{id, label}]` non signés) ; `POST /emargement/{token}` (`enrolment_id`, `drawing`) → redirige avec `status = signed` ; `where('token','[A-Za-z0-9]{40}')`, sinon même 404.
- Throttles : `attendance-signing` 240/min par `sha256(token).ip`, `attendance-signing-post` 60/min par `sha256(token).ip` ; `attendance-signing-open` 30/min par utilisateur.
- `AttendanceRows::grid()['slots']` : `[{half, status|null, slot_id|null, signed, expected}]` pour le jour de la séance ; `session()['seances'][*]` inchangé, `session()['slots']` du mois : `[{date, half, status, signed, expected, to_validate: bool}]` (`to_validate` = non validé et jour passé).

- [ ] **Step 1 :** tests : ouvrir par HTTP puis page publique 200 sans « Durand » complet (contient « Léa D. ») ni e-mail ; signer → 302 + ligne ; jeton inconnu, fermé, validé, mal formé (`abc`) → 404 avec **le même corps** (comparer le prop `state = invalid`) ; autre formateur → 404 sur `state` ; 20 signatures depuis la même IP passent (Review Focus 1) ; `state` liste signés/attendus ; `grid` expose `slots`.
- [ ] **Step 2 :** FAIL ; **Step 3 :** implémenter ; **Step 4 :** `$T --filter=SigningHttpTest` PASS ; **Step 5 :** commit `feat: signing endpoints and public signing page routes`.

### Task 6 : Feuille signée (PDF archivé)

**Files :** Create `Signing/SignedSheetPdf.php`, `Signing/SignedSheetController.php`, `resources/views/documents/attendance-signed-sheet.blade.php` ; Modify `routes/sessions.php` ; Test `tests/Feature/Sessions/Signing/SignedSheetTest.php`

**Interfaces — Produces :**
- `SignedSheetPdf::generate(TrainingSession $s, string $month /* AAAA-MM */, User $by): SignedSheet` — HTML (Blade, `{{ }}` seulement, dessins en data URI lus du disque), `PdfRenderer::fromHtml`, archive `TenantStorage::path('attendance_sheets', "<session>/signed-<AAAA-MM>-<YmdHis>.pdf")`, `pdf_sha256` ; Gotenberg en échec → `PdfRenderingFailed` (503), rien d'archivé.
- `SignedSheetPdf::html(TrainingSession $s, string $month): string` (testable).
- Routes : `POST /sessions/{trainingSession}/signed-sheets` (`month`) — gestionnaire (périmètre ≠ « Ses séances »), 403 sinon ; `GET /sessions/{trainingSession}/signed-sheets` (liste JSON : `id, month, generated_at, sha256, url`) ; `GET /signed-sheets/{sheet}` → archive, 410 si absente ou sha256 différent.
- Contenu : en-tête organisme + logo + session + mois + « Mois clôturé le … » / « Mois non clôturé » ; par jour : créneaux, modules, formateur ; tableau stagiaire (nom complet) × Matin / Après-midi : dessin + heure, « Absent », « Non validé » ; présence finale de la grille ; « Validé par <nom> (formatrice|formateur ou gestionnaire des présences) le … » + dessin ; jour sans créneau : « Émargement papier ou saisie manuelle » ; mention méthode ; annexe des sha256 des dessins.

- [ ] **Step 1 :** tests (`fakeSheetGotenberg`, `sheetHtml`) : HTML contient `data:image/png;base64,`, « Léa Durand », « Validé par », « Mois non clôturé », « Non validé » pour un créneau ouvert ; échappement (`<script>` dans le titre) ; archive écrite et `pdf_sha256` = sha256 des octets ; téléchargement 200 ; fichier altéré → 410 ; formateur → 403 sur la génération, 200 sur le téléchargement de sa session ; Gotenberg 500 → 503 français, aucune ligne.
- [ ] **Step 2 :** FAIL ; **Step 3 :** implémenter ; **Step 4 :** PASS ; **Step 5 :** commit `feat: archived signed attendance sheet per session and month`.

### Task 7 : Porte publique pour un connecteur

**Files :** Create `app/Modules/Sessions/Public/AttendanceSigning.php` ; Test `tests/Feature/Sessions/Signing/PublicPortTest.php`

**Interfaces — Produces :**
- `slots(string $sessionId, string $from, string $to): list<array{id:string, date:string, half:string, status:string, seance_ids:list<string>, expected: list<array{enrolment_id:string, person_id:string}>}>`
- `recordExternal(string $slotId, string $enrolmentId, CarbonImmutable $signedAt, ?string $pngDataUri, string $source): void` — mêmes règles que `sign` hors jeton et jour ; `drawing_path` null si pas de dessin ; `source` ≠ `online`.
- `validateExternal(string $slotId, string $source, ?string $trainerPngDataUri, string $trainerName): void` — même calcul des présences, `validator_role = trainer`, `validated_by = null`.
- Aucun type interne dans les signatures ; rien ne nomme SoWeSign.

- [ ] **Step 1 :** tests avec `source = 'fake'` : slots renvoie le créneau et les attendus ; recordExternal sans dessin + validateExternal → présences `present`/`absent` ; double enregistrement → 409.
- [ ] **Step 2–4 :** FAIL → implémenter → PASS ; `$T tests/Arch` PASS. **Step 5 :** commit `feat: public attendance signing port for future connectors`.

### Task 8 : RGPD

**Files :** Modify `app/Modules/Sessions/Gdpr/EnrolmentAnonymisation.php`, `app/Modules/Sessions/Gdpr/PersonSessionsExport.php` ; Test `tests/Feature/Sessions/Signing/SigningGdprTest.php`

- [ ] **Step 1 :** tests : anonymiser la personne → ses `drawing_path`, `ip`, `user_agent` à null, fichiers supprimés, `signed_at` gardé ; dessin d'un autre stagiaire intact ; session individuelle → feuilles signées (lignes + fichiers) supprimées ; session collective → gardées ; export RGPD contient `emargements: [{date, demi_journee, signe_le, source}]`.
- [ ] **Step 2–4 :** FAIL → implémenter → PASS (`--filter='SigningGdprTest|Anonymis'`). **Step 5 :** commit `feat: anonymise and export attendance signatures`.

### Task 9 : Écrans

**Files :**
- Create `resources/js/pages/sessions/attendance-signing.tsx` (plein écran : QR + lien en clair, liste ✓ / en attente, interrogation `state` toutes les 5 s, « Effacer » par ligne, « Fermer », « Ouvrir la page de signature ici » (nouvel onglet), dialogue « Signer et valider » avec `SignaturePad` et l'avertissement « Les stagiaires qui n'ont pas signé seront notés absents. »)
- Create `resources/js/pages/public/attendance-signing.tsx` (liste des noms → pad → « Je signe ma présence » → « Merci, votre présence est enregistrée. » 3 s puis retour liste ; état `invalid`)
- Modify `resources/js/components/sessions/session-attendance.tsx` : dans `AttendanceDialog`, section « Émargement en ligne » (boutons Matin / Après-midi selon `slots`, état, ouvre la page) ; dans `SessionAttendance`, liste des créneaux du mois (badge « à valider ») et section « Feuille signée » (Générer si gestionnaire, versions avec empreinte abrégée, Télécharger).
- [ ] **Step 1 :** `COMPOSE_PROJECT_NAME=tmse docker compose run --rm vite sh -c "npm ci && npm run lint && npm run types && npm run build"` → OK.
- [ ] **Step 2 :** vérification manuelle dans le navigateur (pile `tmse` + caddy sur un port libre, ex. 8082) : ouvrir, signer depuis la page publique, valider, voir la grille.
- [ ] **Step 3 :** commit `feat: trainer signing screen and public signing page`.

### Task 10 : Docs, e2e, PR

- [ ] `tms/docs/sessions.md` : section « Émargement en ligne (0.11) » ; `tms/docs/data-dictionary.md` : trois tables + `attendances.source` ; `CLAUDE.md` : bloc « Émargement en ligne : points d'architecture » ; `tms/CHANGELOG.md` « Non publié ».
- [ ] e2e facultatif `tests/e2e/emargement.spec.ts` (sous verrou) si le temps le permet.
- [ ] `vendor/bin/pint`, `phpstan analyse --memory-limit=1G`, tests ciblés + `tests/Arch` verts.
- [ ] commit `docs: online attendance signing`, push de la branche et PR **seulement après accord de l'utilisateur**.
