# Rattrapage d'émargement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a trainee who missed a half-day sign afterwards — on site (tablet mode on a past half-day) or remotely (personal e-mail link + 6-digit e-mail code) — and let an attendance manager confirm or refuse each catch-up before it counts as attendance.

**Architecture:** Extends `Sessions/Attendance/Signing`: `attendance_signatures` gains a `kind` and a confirmation state; only `on_time` and confirmed catch-ups count in `SlotValidation`'s attendance computation. On-site catch-up = a separate per-slot token valid until the end of the opening day; remote catch-up = a per-(slot, enrolment) link table with an HMAC code. Public pages reuse `public/attendance-signing` with a `mode`.

**Tech Stack:** Laravel 12 / PHP 8.4, Inertia + React 19 TS, PostgreSQL 17 (FORCE RLS), Pest, Gotenberg.

**Spec:** `docs/superpowers/specs/2026-11-21-emargement-rattrapage-design.md` (and `2026-11-20-emargement-design.md` for everything it does not restate).

**Start:** after PRs 47 (advance entry) and 48 (sheet preview) are merged — `git merge main` into `emargement-rattrapage` first (CHANGELOG: keep every entry).

## Global Constraints

- Every table: `tenant_id uuid not null`, `BelongsToTenant`, `Rls::enable`; migrations `--database=pgsql_owner`; new migration date after `2027_01_26_000001`.
- French UI and messages; personal values never in `audit_logs` (`$auditPersonal`) nor in logs.
- Mail by `TenantMailer` in a `TenantAwareJob`, `ShouldBeEncrypted` (clear token only in the payload), after commit; failure reason without server text.
- Link: 40 chars `[A-Za-z0-9]`, only sha256 stored; remote link valid **7 days**, single use; code **6 digits, 10 minutes, 5 tries, one new code per minute** (same as `Core\Signatures`), HMAC `sha256(link_id|code, app.key)`.
- On-site catch-up: **past** day only (never today), slot ever opened (`open|closed|validated`), month not closed (`Closures::assertOpen`); token dies at close, end of the opening day (Paris), or month closure.
- Catch-up possible only for an **expected** trainee (`SlotSeances::expected`) **without** a signature on that slot (unique slot+enrolment kept).
- Confirm / refuse: `sessions.attendance.manage` with a scope other than « Ses séances » on the session; refusal reason ≤ 300 chars, in `$auditPersonal`.
- Manual attendance (`attendances.source` null) is never overwritten.
- Every invalid public link → the same 404 page as the online signing.
- After adding mail templates or rights, update the catalogue tests (`Core\MailTemplatesTest` list of editable keys).

## Review Focus

1. A catch-up signed on a half-day whose month gets closed before confirmation → confirmation refused 409 « Ce mois est clôturé… », nothing written (Task 2 test).
2. A trainee whose attendance was entered by hand, then catches up and is confirmed → attendance row unchanged, confirmation still recorded (Task 2 test).
3. Two remote links sent for the same trainee and half-day → the first one is a 404, the second works (Task 4 test).
4. Wrong code five times → the sixth try refused even with the right code until a new code is requested (Task 4 test).
5. On-site catch-up page opened, then the trainee signs on time through another path (connector) → the on-site page no longer lists him and a second signature is refused 409 (Task 3 test).

---

### Task 1: Data model and « only counted signatures make attendance »

**Files:**
- Create: `tms/database/migrations/2027_01_27_000001_add_attendance_catch_ups.php`
- Create: `tms/app/Modules/Sessions/Attendance/Signing/CatchUpLink.php`
- Modify: `AttendanceSignature.php` (constants, fillable, casts, `$auditPersonal`), `SignSlot.php` (catch-up columns), `SlotValidation.php` (`writeAttendance` counts only counted signatures; expose `recompute`)
- Test: `tms/tests/Feature/Sessions/Signing/CatchUpModelTest.php`

**Interfaces:**
- Produces: `AttendanceSignature::KIND_ON_TIME = 'on_time'`, `KIND_ONSITE = 'catch_up_onsite'`, `KIND_REMOTE = 'catch_up_remote'`; columns `kind` (default `on_time`), `confirmed_at`, `confirmed_by`, `refused_at`, `refused_by`, `refusal_reason`; scope `AttendanceSignature::scopeCounted(Builder)` = `kind = on_time` OR `confirmed_at not null`; `isPendingCatchUp(): bool`.
- Produces: table `attendance_catch_up_links` (columns per spec § 6.1), model `CatchUpLink` (`MORPH_ALIAS = 'attendance_catch_up_link'`, `email` in `$auditPersonal`), unique partial index one active (`used_at`, `revoked_at` null) link per (slot_id, enrolment_id).
- Produces: `attendance_sign_slots.catch_up_token_hash` (unique, nullable), `catch_up_opened_at`, `catch_up_opened_by`, `catch_up_expires_at`.
- Produces: `SlotValidation::recompute(SignSlot $slot): void` (public; runs `writeAttendance` for a **validated** slot, inside the caller's transaction).

- [ ] **Step 1: Write the failing tests**

```php
it('stores the kind of a signature, on time by default', /* sign normally → kind 'on_time', confirmed_at null */);
it('counts a catch-up only once confirmed when the half-day is validated', function () {
    // slot validated with Léa unsigned → absent; insert a catch_up_remote signature for Léa (unconfirmed)
    // → SlotValidation::recompute($slot) keeps Léa 'absent';
    // set confirmed_at → recompute → Léa 'present', duration of the séance.
});
it('keeps one active catch-up link per trainee and half-day', /* second active row → unique violation */);
it('enables RLS on attendance_catch_up_links', /* Rls test helper as in SchemaTest */);
```

- [ ] **Step 2: Run** `docker compose exec app php artisan test tests/Feature/Sessions/Signing/CatchUpModelTest.php` — Expected: FAIL (columns / class missing).
- [ ] **Step 3: Migration, models, `scopeCounted`, `recompute`; `writeAttendance` reads `AttendanceSignature::query()->counted()`.** Register the morph alias in `SessionsServiceProvider` next to the existing signing aliases.
- [ ] **Step 4: Run** the new test file + `tests/Feature/Sessions/Signing` + `tests/Arch` — Expected: all PASS.
- [ ] **Step 5: Commit** `feat(sessions): catch-up signature kinds and links (data)`.

### Task 2: Confirm or refuse a catch-up

**Files:**
- Create: `tms/app/Modules/Sessions/Attendance/Signing/CatchUpConfirmation.php`, `CatchUpController.php` (authenticated actions, also used by Tasks 3–4)
- Modify: `tms/routes/sessions.php`, `SlotRows.php` (`forMonth` rows gain `to_confirm`; new `pendingCatchUps(string $sessionId, string $month): list<array>`)
- Test: `tms/tests/Feature/Sessions/Signing/CatchUpConfirmationTest.php`

**Interfaces:**
- Consumes: Task 1 (`KIND_*`, `counted`, `recompute`).
- Produces: `CatchUpConfirmation::confirm(AttendanceSignature $s, User $by): void`, `refuse(AttendanceSignature $s, User $by, string $reason): void` — one transaction: session lock → slot lock (as `SlotValidation::complete`), `Closures::assertOpen` on the slot's month, 409 if already confirmed/refused or not a catch-up; confirm → `confirmed_at/by`, then `recompute` if the slot is validated.
- Produces routes (middleware `auth`, `can:sessions.attendance.manage`): `POST sessions/rattrapages/{signature}/confirm` → `sessions.catch-ups.confirm`, `POST sessions/rattrapages/{signature}/refuse` → `sessions.catch-ups.refuse` (body `reason`, required, ≤ 300: « Indiquez un motif (300 caractères au plus). »); both 404 unless the user's `ATTENDANCE_MANAGE` scope on the session is not `Trainer` (`Scopes::of`) and reaches it (`SessionsVisibility::sessions`).
- Produces: `GET /sessions/{session}/attendance?month=` JSON gains `catch_ups: [{signature_id, enrolment_id, name, date, half, half_label, kind, kind_label ('Sur place' | 'À distance'), signed_at, drawing_url, manual_attendance: bool}]` (pending only).

- [ ] **Step 1: Write the failing tests**

```php
it('confirms a catch-up: the trainee becomes present on the validated half-day', /* absent → confirm → present; audit row has no name */);
it('refuses a catch-up with a reason, keeping the signature without attendance', /* reason required (422 French), stays absent, refused_at set */);
it('never touches an attendance entered by hand', /* Attendances::record manual 'excused' → confirm → still 'excused', confirmed_at set, catch_ups[0].manual_attendance true before */);
it('refuses to confirm once the month is closed', /* close month → 409 'Ce mois est clôturé' and confirmed_at null */);
it('keeps the trainer (« Ses séances ») out of confirmation', /* trainer user → 404 */);
it('lists pending catch-ups on the « Présences » tab', /* JSON catch_ups.0.kind_label 'À distance' */);
```

- [ ] **Step 2: Run** the file — Expected: FAIL (routes missing).
- [ ] **Step 3: Implement** `CatchUpConfirmation`, the two controller actions, `SlotRows::pendingCatchUps`, attendance JSON key; `drawing_url` = existing authenticated drawing route if one exists, else a new `GET sessions/rattrapages/{signature}/dessin` serving the PNG (same access rule).
- [ ] **Step 4: Run** the file + Signing + Arch — Expected: PASS.
- [ ] **Step 5: Commit** `feat(sessions): confirm or refuse a signing catch-up`.

### Task 3: On-site catch-up (tablet mode on a past half-day)

**Files:**
- Create: `tms/app/Modules/Sessions/Attendance/Signing/OnsiteCatchUp.php`
- Modify: `CatchUpController.php`, `PublicSigningController.php` (mode `catch_up_onsite`), `AttendanceSigning::sign` (new parameter `string $kind = AttendanceSignature::KIND_ON_TIME`), `routes/sessions.php`, `SlotRows::state` (`catch_up: {open: bool, public_url: ?string, expires_at: ?string}`, `can_catch_up: bool`)
- Test: `tms/tests/Feature/Sessions/Signing/OnsiteCatchUpTest.php`

**Interfaces:**
- Consumes: Task 1, `AttendanceSigning::sign`, `SlotSeances::expected`.
- Produces: `OnsiteCatchUp::open(SignSlot $slot, User $by): string` (clear token; refuses today: 409 « Le jour même, utilisez l'émargement en ligne. », slot never opened: 404, month closed: 409; renews the token), `close(SignSlot $slot): void`, `static find(string $token): ?SignSlot` (null when unknown, expired — `catch_up_expires_at` = end of the opening day Paris —, month closed, session cancelled or trashed).
- Produces routes: `POST sessions/emargement/{slot}/catch-up` (open, `can:sessions.attendance.manage`, reaches a séance) → JSON state; `POST sessions/emargement/{slot}/catch-up/close`; public `GET|POST emargement/r/{token}` (`throttle:attendance-signing` / `-post`), page `public/attendance-signing` with `mode: 'catch_up_onsite'`, `banner: 'Rattrapage du lundi 21 novembre 2026, matin'`, trainees = expected without any signature on the slot; POST signs with `requireOpen: false`, `kind: KIND_ONSITE`.

- [ ] **Step 1: Write the failing tests**

```php
it('opens an on-site catch-up on a past half-day and lets an unsigned trainee sign', /* sgNow next day; open → public page lists Léa only; sign → kind catch_up_onsite, confirmed_at null; attendance unchanged */);
it('refuses an on-site catch-up today, on a never-opened day and in a closed month', /* 409 / 404 / 409 with French messages */);
it('ends the catch-up link at the end of the day and when closed', /* travel to next day → 404; close → 404 */);
it('refuses a second signature for a trainee signed meanwhile', /* recordExternal for Léa → page no longer lists her; POST → 409 'Ce nom a déjà signé.' */);
```

- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** file + Signing + Arch — Expected: PASS.
- [ ] **Step 5: Commit** `feat(sessions): on-site signing catch-up on a past half-day`.

### Task 4: Remote catch-up (e-mail link + e-mail code)

**Files:**
- Create: `RemoteCatchUp.php`, `SendCatchUpMail.php` (job), `CatchUpMail.php`, `CatchUpMailTemplates.php` (keys `attendance_catch_up`, `attendance_catch_up_code`)
- Modify: `CatchUpController.php`, `PublicSigningController.php` (mode `catch_up_remote`), `routes/sessions.php`, `SessionsServiceProvider.php` (register templates), `tests/Feature/Core/MailTemplatesTest.php` (editable keys list)
- Test: `tms/tests/Feature/Sessions/Signing/RemoteCatchUpTest.php`

**Interfaces:**
- Consumes: Task 1 (`CatchUpLink`), `AttendanceSigning::sign` (Task 3 `kind`), `Closures`, `Crm\Public\Fiches` (person e-mail).
- Produces: `RemoteCatchUp::send(SignSlot $slot, string $enrolmentId, string $email, User $by): CatchUpLink` (expected + unsigned or 422 `enrolment_id` « Ce stagiaire a déjà signé cette demi-journée. »; e-mail validated; past or today's slot not validated-blocked — any slot status; month open; revokes the previous active link; queues `SendCatchUpMail` with the clear token), `static find(string $token): ?CatchUpLink`, `sendCode(CatchUpLink): void` (429 « Patientez une minute avant de demander un nouveau code. »), `sign(CatchUpLink, string $code, string $drawing, string $ip, string $agent): AttendanceSignature` (drawing checked before the code; wrong code 422 `code` « Code incorrect. » and `code_attempts++`; 5 tries → 422 « Trop d'essais : demandez un nouveau code. »; expired → « Ce code a expiré : demandez-en un nouveau. »; marks `used_at`).
- Produces templates: `attendance_catch_up` — label « Rattrapage d'émargement », subject « Signez votre présence du {date} », variables `lien` (required), `stagiaire`, `date`, `demi_journee`, `session`, `organisme`, `echeance`; `attendance_catch_up_code` — label « Code de rattrapage d'émargement », subject « Votre code : {code} », variables `code` (required), `organisme`.
- Produces routes: `POST sessions/emargement/{slot}/catch-up-links` (body `enrolment_id`, `email`) → JSON state; public `GET emargement/l/{token}`, `POST emargement/l/{token}/code`, `POST emargement/l/{token}` (throttles as Task 3); page mode `catch_up_remote` with `trainee_first_name`, `banner`, consent checkbox label « Je confirme avoir été présent(e) à cette demi-journée. ».
- `SlotRows::state` rows gain `catch_up_link: {sent_at, email_status, expires_at}|null` and the prefilled `email` (only for authenticated readers).

- [ ] **Step 1: Write the failing tests**

```php
it('sends a personal link by e-mail and lets the trainee sign with the e-mailed code', /* Mail::fake; job sent with {lien}; public page shows first name only; code mail; sign → kind catch_up_remote, link used_at */);
it('replaces a previous link: the first one becomes a 404', /* send twice → old token 404 */);
it('applies the code rules: one per minute, ten minutes, five tries', /* 429; travel 11 min → expired message; 5 wrong → 'Trop d'essais' even with the right code */);
it('refuses a link for a trainee who already signed, or without an e-mail', /* 422 French */);
it('answers the same 404 for an unknown, used, expired or closed-month link', /* four cases */);
it('lists the catch-up templates as editable', /* MailTemplatesTest keys include attendance_catch_up, attendance_catch_up_code */);
```

- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement**, mirroring `Core\Signatures::sendCode/sign` rules and `Questionnaires\Campaigns\SendSurveyInvitation` for the job.
- [ ] **Step 4: Run** file + Signing + `tests/Feature/Core/MailTemplatesTest.php` + Arch — Expected: PASS.
- [ ] **Step 5: Commit** `feat(sessions): remote signing catch-up by e-mail link and code`.

### Task 5: Screens

**Files:**
- Modify: `tms/resources/js/pages/sessions/attendance-signing.tsx` (past day: **Rattrapage sur place** / **Fermer le rattrapage**, « Ouvrir la page de signature ici », QR under « Afficher le QR code »; per unsigned row **Envoyer un lien de rattrapage** dialog with the e-mail prefilled, then « Lien envoyé le … »), `tms/resources/js/pages/public/attendance-signing.tsx` (modes: banner « Rattrapage … »; remote: greeting « Bonjour {prénom}, signez votre présence du … », consent checkbox, **Recevoir mon code**, code field, **Signer**), `tms/resources/js/components/sessions/session-attendance.tsx` (block **Rattrapages à confirmer** with count badge, thumbnail, type, signed at, « Présence saisie à la main : non modifiée » when `manual_attendance`, **Confirmer** / **Refuser** with reason dialog)
- Test: lint, types, build; no JS test framework in the repo (server behaviour covered by Tasks 2–4).

- [ ] **Step 1:** Implement the three screens against the JSON of Tasks 2–4.
- [ ] **Step 2: Run** `docker compose run --rm vite sh -c "npx prettier --write <files> && npm run lint && npm run types && npm run build"` — Expected: no error, « built in ».
- [ ] **Step 3: Commit** `feat(sessions): catch-up screens`.

### Task 6: Signed sheet, GDPR, docs

**Files:**
- Modify: `SignedSheetPdf.php` + `resources/views/documents/attendance-signed-sheet.blade.php` (cell mention per spec § 6.5; annex method lines), `SigningAnonymisation.php` (links: `email` null, `revoked_at` now), `Sessions\Gdpr\PersonSessionsExport` (kind + confirmation per signature), `tms/docs/sessions.md` (« Rattrapage »), `tms/docs/admin.md` (two templates), `tms/docs/data-dictionary.md`, `CLAUDE.md` (two lines under the Sessions bullets), `tms/CHANGELOG.md` (« Non publié › Ajouté »)
- Test: `tms/tests/Feature/Sessions/Signing/CatchUpSheetGdprTest.php`

- [ ] **Step 1: Write the failing tests**

```php
it('prints the catch-up mention on the signed sheet', /* html() contains 'Signé en rattrapage le' and 'confirmé par'; pending → 'Rattrapage à confirmer'; refused → 'Rattrapage refusé' */);
it('erases catch-up link e-mails on anonymisation and exports the kind', /* scrub → email null, revoked; export row 'type' => 'Rattrapage à distance', 'confirmé' => true */);
```

- [ ] **Step 2: Run** — Expected: FAIL. **Step 3:** implement. **Step 4:** run file + `tests/Feature/Sessions` + `tests/Arch` + phpstan + pint — Expected: PASS.
- [ ] **Step 5: Commit** `feat(sessions): catch-ups on the signed sheet, GDPR and docs`.
