# Test de positionnement en ligne — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** a positioning test answered on screen through the candidate's link (desktop only, copy/paste blocked, exits traced), auto- and hand-graded, its score on the candidature; drafted from a PDF by the AI.

**Architecture:** module Admissions, folder `app/Modules/Admissions/OnlineTests/`. A test (`online_tests`) has versions frozen at publication (`online_test_versions.content` jsonb: sections → questions); a sending (`positioning_sendings`, existing link) may point to a published version (`online_test_version_id`); the candidate's answers live in `online_test_attempts` (one per sending). Grading is a pure class over (content, answers).

**Tech Stack:** Laravel 12, Pest, Inertia React 19 TS, PostgreSQL 17 (RLS), Playwright.

**Spec:** `docs/superpowers/specs/2026-11-19-test-positionnement-en-ligne-design.md`

## Global Constraints
- French UI; every table `tenant_id` + `BelongsToTenant` + `Rls::enable`; migrations via `pgsql_owner`.
- Admissions never names Catalogue/Crm/Sessions tables; it may use `Connectors\Ai\Ai` and `Catalogue\Assistant\PdfText` (arch allowlist extended, ruling).
- Points are integers (no float); numeric answers compared as decimal strings with `brick/math`, tolerance default `0.01`.
- Desktop gate: `min-width: 1024px` and `(pointer: fine)`; otherwise « Ouvrez ce lien sur un ordinateur ».
- Answers and traces are personal data (`$auditPersonal`), erased by `CandidatureAnonymisation`.
- One attempt per link; answers saved server-side as typed; submission final; auto-submit at time limit (+30 s grace server-side).

## Question model (content JSON, validated by `OnlineTestContent::validate(array): array`)
`{sections: [{title, intro?, questions: [{id (uuid), type, label, support_text?, image?, choices?: [{id, label}], answer?, tolerance?, points (int ≥ 0), required (bool)}]}]}`
Types: `short_text`, `long_text`, `single_choice`, `multiple_choice`, `true_false`, `number`, `yes_no`. Auto-graded when `answer` is set on `single_choice` (choice id), `multiple_choice` (list of ids, all-or-nothing), `true_false` (bool), `number` (decimal string); texts always hand-graded; a question without `answer` and 0 points is profile only.

## Review Focus
1. Candidate reloads the page mid-test → same attempt, answers kept, timer continues from `started_at`.
2. Test edited after a candidate started → his version unchanged (frozen content), grading on it.
3. Time limit exceeded / request after deadline → answers refused (409), attempt auto-submitted.
4. Paste attempt and leaving the tab → trace recorded (`events`), shown on the candidature.
5. Hand grading of an open question > its points → refused; total recomputed; never moves the stage back.

---

### Task 1: tests and versions (model, editor service)
**Files:** migration `2027_01_19_000001_create_online_tests.php` (`online_tests`: title, das ids jsonb? no — pivots `online_test_das`, `online_test_trainings`; `draft_content` jsonb; `time_limit_minutes` nullable smallint; `current_version_id`; soft deletes. `online_test_versions`: `online_test_id`, `number`, `content` jsonb, `total_points` int, `published_by`, `created_at`); `OnlineTest`, `OnlineTestVersion`, `OnlineTestContent`, `OnlineTests` (create/update draft/publish/trash/offered($trainingId,$dasId)); `ReferenceUsage` for DAS, `TrainingUsage` for trainings.
**Tests:** `tests/Feature/Admissions/OnlineTests/OnlineTestsTest.php`: content validation (unknown type, choice answer not a choice id, negative points → French errors); publish freezes version n+1 with total points; draft edits do not touch published version; offered() by training or DAS, unpublished/trashed excluded.

### Task 2: editor screens
**Files:** `OnlineTestController` (index, create, edit, update, publish, destroy), routes `admissions/online-tests` (`catalogue.manage`), pages `admissions/online-tests/{index,edit}.tsx` (sections, questions by type, answers, points, DAS/trainings, time limit, « Publier »), sidebar entry « Tests en ligne » under Formation.
**Tests:** `OnlineTestScreensTest.php`: rights (403 without catalogue.manage), save draft, publish flash « Test publié (version 2). ».

### Task 3: link to an online test + candidate passing
**Files:** migration adds `positioning_sendings.online_test_version_id` (nullable FK) ; `online_test_attempts` (sending_id unique, candidature_id, version_id, started_at, deadline_at, submitted_at, answers jsonb, events jsonb, auto_score int, manual_scores jsonb, score int, max_score int); `PositioningSendings::link/send` accept `online_test_id` (offered + published); `OnlineAttempts` (start, save, event, submit, expire); public routes `test-positionnement/{token}/start|answers|events|submit` (JSON, throttle); page `public/positioning-test.tsx` online mode (desktop gate, sections, autosave debounce 1 s, timer, anti-copy handlers, confirm submit).
**Tests:** `OnlineAttemptsTest.php`: start once (second start returns the same attempt); answers saved and reloaded; unknown question id refused; after deadline 409 and auto-submitted; submit → stage « Test reçu », `positioning_received_on`, score written to `positioning_result` as « 12 / 20 (à compléter) » when open questions remain; events appended (`blur`, `paste_blocked`) with counts; link of a PDF sending keeps the upload flow.

### Task 4: grading on the candidature
**Files:** `OnlineGrading` (pure: auto score per question), `OnlineAttemptController::grade` (PUT manual scores, update right), candidature props `positioning.attempt` (questions, answers, auto/manual scores, events summary, totals), component `online-attempt-panel.tsx`.
**Tests:** `OnlineGradingTest.php` (unit: each type, tolerance, multiple all-or-nothing); grade > points refused « La note dépasse le barème de la question. »; final score « 15 / 20 » once all graded; anonymisation clears answers/events.

### Task 5: draft from a PDF (AI)
**Files:** `OnlineTestFromPdf` (PdfText::extract → Ai::structured with schema of the content model, purpose `positioning.online_test_from_pdf`, answers never asked), route POST `admissions/online-tests/from-pdf` (`throttle:ai`), button « Créer depuis un PDF » on index.
**Tests:** `OnlineTestFromPdfTest.php` with `Http::fake` of the AI provider: draft created with sections/questions, unknown types dropped, no answers kept even if returned; AI not configured → French error.

### Task 6: docs, e2e, review
Docs (`admissions.md`, `data-dictionary.md`, CHANGELOG, CLAUDE.md); e2e: create a 2-question test, publish, generate link, answer on desktop viewport, submit, see score; mobile viewport shows the desktop message. Full suite, phpstan, pint, lint, types; final review.
