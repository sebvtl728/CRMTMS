# Lot 1c – Pipeline, actions et relances Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Donner à CAP FORMATION le suivi commercial au quotidien : opportunités dans un pipeline (liste + kanban), actions et comptes rendus, relances avec écran « À faire », planning de la semaine, et envoi des rendez-vous vers l'agenda (flux iCal, puis Microsoft 365) — après avoir soldé les reprises de 1b dont 1c dépend.

**Architecture:** Deux nouveaux sous-modules dans `app/Modules/Crm` : `Opportunities` (opportunité, historique d'étapes, règles de passage) et `Activities` (actions, relances, planning). Ils se branchent sur le socle existant : `CrmVisibility` (filtre DAS), `Timeline` (nouveaux `TimelineProvider` sous le tag `Timeline::TAG`), `LastContact`, `Merger` / `Anonymiser` / `UndoImport` (via un registre de données liées), référentiels `ReferenceList::PipelineStage|ActionType|ActionResult|LossReason|Priority|FundingMode`. Le calendrier externe passe par la couche connecteurs (`Connectors/Calendar`).

**Tech Stack:** Laravel 12, Inertia/React 19/TS, PostgreSQL 17 (RLS), Redis/Horizon, `@dnd-kit/core` (kanban), `eluceo/ical` ou génération iCal maison (RFC 5545), Microsoft Graph (HTTP), Pest, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-11-tms-design.md` (§7 Opportunité / Historique d'étapes / Action, §8 « 1c – Pipeline et actions », §10 connecteurs) ; `Cdc/cdc-activité-commercial.md` (« Pipeline des opportunités », « Suivi des actions et relances ») ; reprises `docs/superpowers/plans/2026-11-13-lot1c-carryover.md` ; règles `CLAUDE.md`.

## Global Constraints

- Toutes les règles de `CLAUDE.md` (RLS + `BelongsToTenant` + `Auditable`, morph aliases, référentiels par id + `ReferenceUsage::register`, clés de cache préfixées, jobs tenant-aware, `UserFacingHttpException` pour les messages d'erreur, limiteurs nommés).
- Étapes par défaut (référentiel existant) : Nouveau 10 %, Contacté 20 %, Étude 40 %, Proposition 60 %, Négociation 80 %, Gagné 100 % (système, issue « won »), Perdu 0 % (système, issue « lost »).
- Priorités : Urgent, Élevé, Moyen, Bas. Types d'action : Prise de rendez-vous, Rappel, Envoi de nouveauté, Suivi, Rendez-vous physique, Envoi de devis, Appel, E-mail, Note. Résultats : Réussi, Échec, En attente, À replanifier.
- Montants en euros, `numeric(12,2)`, saisis et affichés au format français (`12 500,00 €`).
- Dates et « en retard » calculés en `Europe/Paris` (une relance du jour n'est pas en retard avant minuit).
- Saisie d'une action en moins de 2 minutes : formulaire court, pré-rempli depuis la fiche courante.
- Le compte rendu d'une action liée à une personne est une donnée personnelle (`$auditPersonal`, effacé par l'anonymisation).
- Branche : `lot1c-pipeline` créée depuis `main`.

## Review Focus

1. **Glisser une opportunité vers « Perdu » sans motif** (kanban ou appel direct) → refus serveur 422 « Le motif de perte est obligatoire. », la carte revient à sa colonne, une fenêtre demande le motif. Test : Task 3 `moving to lost without a reason is refused`.
2. **Relance due aujourd'hui à 23 h 30 vue à 23 h 45, et relance d'hier** → aujourd'hui = « Aujourd'hui », hier = « En retard » (fuseau Paris, pas UTC). Test : Task 6 `overdue boundary uses Europe/Paris midnight`.
3. **Fusion de deux personnes ou entreprises qui portent des opportunités et des actions** → tout est rattaché à la fiche conservée ; anonymiser une personne efface les comptes rendus de ses actions. Tests : Task 4 `merge moves opportunities`, Task 5 `merge moves actions` et `anonymisation scrubs action reports`.
4. **Rendez-vous un jour de changement d'heure dans le flux iCal** → `DTSTART;TZID=Europe/Paris` correct, l'agenda affiche la bonne heure. Test : Task 8 `ical event across dst keeps local time`.
5. **Responsable de DAS** → ne voit que les opportunités de ses DAS, et uniquement leurs actions ; kanban, liste, « À faire » et planning compris. Test : Task 3 `das visibility on opportunities` et Task 6 `todo respects das visibility`.

---

## Décisions de ce plan (à valider par Sébastien en relisant)

- **Formations d'une opportunité** : le catalogue arrive en 1d. En 1c, une opportunité porte un **objet** (texte court, ex. « Formation HACCP 3 jours ») et un DAS ; le lien vers les formations du catalogue s'ajoute en 1d sans migration destructive. **[choix Claude]**
- **Mode de financement** porté par l'opportunité (prérempli depuis la personne pour un particulier), car il peut différer d'une affaire à l'autre. **[choix Claude]**
- **Visibilité Responsable de DAS** : par le DAS de l'opportunité ; une action est visible si sa fiche ou son opportunité l'est. **[choix Claude]**
- **Agenda** : le **flux iCal privé** (compatible Outlook, Google, Apple) est livré d'abord et suffit pour voir ses RDV dans Outlook ; l'envoi direct dans **Microsoft 365** (Task 9) nécessite que l'administrateur déclare une application dans Azure — il est livré mais activable seulement si CAP FORMATION le souhaite. **[choix Claude]**
- **Mail récapitulatif quotidien des relances** : désactivé par défaut, chaque utilisateur l'active dans ses paramètres. **[choix Claude]**

---

## File Structure

```
tms/app/Modules/Crm/
  Support/LinkedData.php                 registre « données liées » (remplace hasForeignData)
  Opportunities/ Opportunity.php, OpportunityStage.php (service des passages), StageChange.php (historique),
                 OpportunityController.php, OpportunityRequest.php, OpportunityPolicy.php,
                 OpportunityListQuery.php, OpportunitiesProvider.php (timeline)
  Activities/    Activity.php, ActivityController.php, ActivityRequest.php, ActivityPolicy.php,
                 QuickReport.php (compte rendu mobile), TodoQuery.php, WeekPlanning.php,
                 ActivitiesProvider.php (timeline), DailyDigest.php (job + mail)
tms/app/Modules/Connectors/Calendar/ IcalFeed.php, IcalFeedController.php, CalendarToken.php,
                 Microsoft365Connector.php, GraphClient.php, PushActivityToOutlook.php (job)
tms/routes/crm-pipeline.php
tms/resources/js/pages/crm/{opportunities/{index,kanban,show,form},todo,planning,report}.tsx
tms/resources/js/components/crm/{quick-action,stage-dialog,activity-list}.tsx
tms/tests/Feature/Crm/{Opportunities,Activities}/…, tests/Feature/Connectors/{IcalFeedTest,Microsoft365Test}.php
tms/tests/e2e/pipeline.spec.ts
```

---

### Task 1: Reprises structurantes de 1b

**Files:**
- Modify: `app/Modules/Crm/Timeline/{Timeline,TimelineProvider,TimelineEntry,NotesProvider,AttachmentsProvider,AuditProvider,TimelineController}.php`, `resources/js/components/crm/timeline.tsx`, `app/Modules/Crm/Import/UndoImport.php`, `app/Modules/Crm/Timeline/LastContact.php`, `app/Modules/Crm/Import/RowImporter.php`
- Create: `app/Modules/Crm/Support/LinkedData.php`
- Test: `tests/Feature/Crm/TimelineTest.php` (ajouts), `tests/Feature/Crm/LinkedDataTest.php`, `tests/Feature/Crm/Import/UndoImportTest.php` (ajouts)

**Interfaces:**
- Produces:
  - Curseur composite : `TimelineProvider::entriesFor(Model $subject, ?TimelineCursor $before, int $limit): Collection` avec `TimelineCursor(CarbonImmutable $at, string $type, string $id)` encodé en chaîne opaque (base64url JSON) dans l'API ; ordre stable `at desc, type desc, id desc`.
  - `LinkedData::register(string $key, Closure $hasLinked)` où `$hasLinked(Organisation|Person $fiche, array $exceptIds): bool` ; `LinkedData::any(Organisation|Person $fiche, array $exceptIds): bool`. Les quatre vérifications existantes (rattachements, notes, pièces jointes, consentements) sont réenregistrées par `CrmServiceProvider` ; `UndoImport` n'appelle plus que `LinkedData::any`. Tasks 3 et 4 enregistrent opportunités et actions.
  - `LastContact::recompute(string $subjectType, string $subjectId): void` (max des notes, et plus tard des actions faites — Task 4 enregistre sa source via `LastContact::registerSource(Closure)`), appelé après une annulation d'import ; une date importée dans le futur est ramenée à maintenant avec un avertissement de ligne « Date future ramenée à aujourd'hui ».

- [ ] **Step 1: Tests** — deux entrées de fournisseurs différents au même instant de part et d'autre d'une page : aucune perdue ni répétée ; un module fictif enregistré dans `LinkedData` empêche l'annulation de supprimer la fiche ; annulation d'un import de notes → `last_contact_at` recalculé ; note importée datée de l'an prochain → date du jour + avertissement.
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès** — `docker compose exec -T app php artisan test --filter='Timeline|LinkedData|UndoImport'`
- [ ] **Step 5: Commit** — `refactor(crm): composite timeline cursor, linked-data registry and last-contact recompute`

---

### Task 2: Reprises sécurité et visibilité de 1b

**Files:**
- Modify: `app/Modules/Crm/Search/{GlobalSearch,SearchController}.php`, `app/Modules/Crm/Support/FilterInput.php` (ou équivalent), `app/Modules/Crm/CrmServiceProvider.php` (liaisons de routes), `app/Modules/Core/Users/{InvitationController,UserController}.php`, `app/Modules/Core/Access/PermissionSync.php`, `app/Console/Commands/PermissionsSync.php`, commandes de récupération
- Test: `tests/Feature/Crm/{GlobalSearchTest,OrganisationsTest,CrmVisibilityTest}.php`, `tests/Feature/Core/{UserLifecycleTest,PermissionSyncTest}.php`

**Interfaces:**
- Produces: validation commune `FilterInput::text(string $key, int $max = 100): ?string` (tableaux ignorés) utilisée par toutes les listes et la recherche ; une entreprise ou personne en corbeille n'est lisible par URL qu'avec `crm.delete` (sinon 404) ; `user_das` vidé quand l'utilisateur quitte le rôle Responsable de DAS (audité) ; les « DAS suivis » passent par un service public `App\Modules\Crm\Visibility\FollowedDas` appelé via un évènement/registre (plus d'import direct Crm dans `InvitationController`) ; règle DAS : rattachements terminés et entreprises en corbeille n'ouvrent plus la visibilité d'une personne ; `tms:permissions:sync` renvoie un code ≠ 0 et affiche « Échec pour l'organisme <slug> » si un organisme échoue ; la garde « dernier administrateur » (verrou consultatif) est appliquée aussi par la commande `tms:user:reset-2fa` (si elle désactive) et par toute suspension d'organisme qui toucherait les rôles.

- [ ] **Step 1: Tests** (un par point ci-dessus, dont `?q[]=x` et `q` de 500 caractères → aucune erreur 500).
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès** — `docker compose exec -T app php artisan test --filter='GlobalSearch|Organisations|CrmVisibility|UserLifecycle|PermissionSync'`
- [ ] **Step 5: Commit** — `fix(crm): bounded filters, trashed fiches by url, das visibility edges, sync failures`

---

### Task 3: Opportunités et étapes

**Files:**
- Create: migrations `create_opportunities_table`, `create_opportunity_contacts_table`, `create_opportunity_stage_changes_table` ; `app/Modules/Crm/Opportunities/*` (sauf provider) ; `routes/crm-pipeline.php` ; pages `crm/opportunities/{index,show,form}.tsx`, `components/crm/stage-dialog.tsx` ; onglet « Opportunités » sur les fiches entreprise et personne
- Test: `tests/Feature/Crm/Opportunities/{OpportunitiesTest,StageRulesTest,OpportunityVisibilityTest}.php`

**Interfaces:**
- Produces:
  - Table `opportunities` : `id`, `tenant_id`, `organisation_id` null, `person_id` null (exactement un des deux : contrainte CHECK), `title` (objet, requis, 200), `das_id` (FK reference_items, requis), `stage_id` (FK reference_items `pipeline_stage`, requis), `probability` smallint 0–100 (défaut = `meta.probability` de l'étape, modifiable), `amount` numeric(12,2) null, `expected_close_on` date null, `priority_id` null, `funding_mode_id` null, `loss_reason_id` null, `loss_comment` text null, `won_on` date null, `lost_on` date null, `responsible_user_id` null, `source_system`/`source_id` null, `merged_into_id` non (l'opportunité suit sa fiche), timestamps, soft deletes, created_by/updated_by. `weighted_amount` = `amount * probability / 100` (colonne générée).
  - `opportunity_contacts(tenant_id, opportunity_id, person_id)` : interlocuteurs liés (personnes rattachées à l'entreprise ou la personne elle-même).
  - `opportunity_stage_changes(tenant_id, opportunity_id, from_stage_id null, to_stage_id, changed_by, changed_at)`.
  - `OpportunityStage::move(Opportunity $o, ReferenceItem $to, ?string $lossReasonId, ?string $lossComment, ?CarbonImmutable $wonOn): void` — vers « lost » : motif obligatoire (`loss_reason`), `lost_on` = aujourd'hui ; vers « won » : `won_on` (défaut aujourd'hui) ; quitter won/lost efface ces champs ; probabilité alignée sur l'étape sauf si l'utilisateur l'a modifiée depuis le dernier passage (`probability_overridden` bool) ; écrit l'historique ; ne modifie pas `last_contact_at` (seules les actions faites et les notes le font).
  - `Opportunity::cycleDays(): ?int` = `won_on - created_at` (jours, Paris).
  - Routes : `GET /crm/opportunities` (liste paginée 25 ; filtres : texte, DAS, cible entreprise/particulier, responsable, priorité, étape, période de clôture prévue, « mes opportunités » ; tri : clôture prévue, montant, pondéré, création), `GET|POST /crm/opportunities/create`, `GET /crm/opportunities/{opportunity}`, `PUT`, `DELETE` (soft), `POST …/stage` (`stage_id`, `loss_reason_id`, `loss_comment`, `won_on`). Permissions : `crm.view` lire, `crm.create`/`crm.update`/`crm.delete`.
  - Visibilité : `CrmVisibility::opportunities(Builder $q, User $u): Builder` (DAS de l'opportunité pour un Responsable de DAS) ; liaison de route avec `Str::isUuid` + visibilité.
  - `LinkedData::register('opportunities', …)` ; `ReferenceUsage::register` pour `PipelineStage` (stage_id + stage changes), `Das`, `Priority`, `FundingMode`, `LossReason`.
  - Particulier : le mode de financement est affiché en évidence sur la fiche opportunité et prérempli depuis la personne.

- [ ] **Step 1: Tests** — création depuis une entreprise et depuis un particulier ; CHECK un seul compte ; probabilité par défaut 10 % à « Nouveau », 60 % à « Proposition » ; `moving to lost without a reason is refused` (Review Focus 1) ; passage à Gagné renseigne `won_on`, `cycleDays` correct ; retour de Gagné vers Négociation efface `won_on` ; probabilité modifiée à la main conservée au passage suivant ; historique d'étapes complet ; `das visibility on opportunities` (Review Focus 5) ; montant `12 500,00` saisi en français accepté ; assistant sans `crm.delete` → 403.
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès** — `docker compose exec -T app php artisan test --filter=Opportunit`
- [ ] **Step 5: Commit** — `feat(crm): opportunities with stage rules, history and das visibility`

---

### Task 4: Kanban, intégration fusion / RGPD / import

**Files:**
- Create: `resources/js/pages/crm/opportunities/kanban.tsx` (`@dnd-kit/core`), `app/Modules/Crm/Opportunities/OpportunitiesProvider.php`
- Modify: `app/Modules/Crm/Duplicates/Merger.php`, `app/Modules/Crm/Gdpr/{Anonymiser,PersonDataExport}.php`, `app/Modules/Crm/Export/*` (export des opportunités), menu
- Test: `tests/Feature/Crm/Opportunities/{KanbanTest,OpportunityMergeGdprTest,OpportunityExportTest}.php`

**Interfaces:**
- Produces: `GET /crm/opportunities/kanban` (colonnes = étapes actives triées ; en-tête : nombre, montant, montant pondéré ; filtres identiques à la liste ; colonnes Gagné/Perdu limitées aux 90 derniers jours) ; glisser-déposer → `POST …/stage` ; vers Perdu ouvre `stage-dialog` (motif), vers Gagné demande la date ; en cas de refus la carte revient. Mobile < 768 px : liste groupée par étape avec bouton « Changer d'étape ».
  - Timeline : `OpportunitiesProvider` (créée, étape changée « Étude → Proposition », gagnée, perdue avec motif) sur la fiche entreprise/personne.
  - `Merger` déplace `opportunities.organisation_id|person_id` et `opportunity_contacts` (dédoublonnés) vers la fiche conservée.
  - `Anonymiser` : opportunités d'un particulier anonymisé conservées (montants, étapes, statistiques) mais `loss_comment` effacé ; `opportunity_contacts` de la personne supprimés.
  - `PersonDataExport` : liste des opportunités (titre, étape, montant).
  - Export Excel des opportunités (même mécanisme que 1b : `ExportResource`, `crm.export`, neutralisation, audit).

- [ ] **Step 1: Tests** — `merge moves opportunities` ; anonymisation efface `loss_comment` et les contacts ; colonnes du kanban et totaux exacts ; glisser vers Perdu via l'endpoint sans motif → 422 ; export filtré identique à la liste.
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès** (+ `npm run lint && npm run types && npm run build`)
- [ ] **Step 5: Commit** — `feat(crm): pipeline kanban, opportunity timeline, merge, gdpr and export`

---

### Task 5: Actions et saisie rapide

**Files:**
- Create: migration `create_activities_table` ; `app/Modules/Crm/Activities/{Activity,ActivityController,ActivityRequest,ActivityPolicy,ActivitiesProvider}.php` ; `components/crm/quick-action.tsx` (bouton « + Action » dans l'en-tête de l'app et sur chaque fiche/opportunité)
- Modify: `Merger`, `Anonymiser`, `PersonDataExport`, `LinkedData`/`LastContact` enregistrements, `CrmVisibility`
- Test: `tests/Feature/Crm/Activities/{ActivitiesTest,QuickActionTest,ActivityMergeGdprTest}.php`

**Interfaces:**
- Produces:
  - Table `activities` : `id`, `tenant_id`, `type_id` (FK `action_type`, requis), `subject` (titre court, 200, prérempli « Appel – <fiche> »), `report` text null (compte rendu, personnel si lié à une personne), `organisation_id` null, `person_id` null, `opportunity_id` null (au moins un des trois : CHECK), `due_at` timestamptz null (à faire), `done_at` timestamptz null, `duration_minutes` null, `result_id` null (FK `action_result`), `responsible_user_id` (défaut : auteur), `location` null (RDV), timestamps, soft deletes, created_by.
  - Statut dérivé : à faire (`done_at` null), faite, en retard (`due_at` < maintenant, Paris).
  - « Prochaine relance » : à l'enregistrement d'une action faite, champs facultatifs `next_type_id` + `next_due_at` → crée une nouvelle action à faire liée aux mêmes fiche/opportunité (même responsable).
  - Une action **faite** met à jour `last_contact_at` (fiche, personne → entreprise rattachée active, opportunité) via `LastContact::record` ; `LastContact::registerSource` (Task 1) inclut les actions faites.
  - `ActivitiesProvider` dans la timeline ; `LinkedData::register('activities', …)` ; `Merger` déplace les actions ; `Anonymiser` efface `report` et `location` des actions liées à la personne ; `PersonDataExport` les liste ; `ReferenceUsage::register` `ActionType`, `ActionResult`.
  - Routes `POST /crm/activities`, `PUT/DELETE /crm/activities/{activity}`, `POST /crm/activities/{activity}/done` (résultat + compte rendu + prochaine relance) ; visibilité : action visible si sa fiche ou son opportunité est visible.
  - Saisie rapide : fenêtre avec type (boutons pour Appel / RDV / E-mail / Rappel), fiche préremplie depuis la page courante (ou recherche), « fait maintenant » coché par défaut, résultat, compte rendu, prochaine relance (raccourcis : demain, dans 1 semaine, dans 1 mois).

- [ ] **Step 1: Tests** — création rapide depuis une entreprise (1 requête, champs minimaux : type + fiche) ; action faite → `last_contact_at` de la personne et de son entreprise ; prochaine relance créée ; `anonymisation scrubs action reports` (Review Focus 3) ; `merge moves actions` ; compte rendu masqué dans l'audit ; action d'une fiche invisible → 404 pour un Responsable de DAS.
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès**
- [ ] **Step 5: Commit** — `feat(crm): activities, quick entry, follow-ups and last contact`

---

### Task 6: Écran « À faire », compteur et récapitulatif quotidien

**Files:**
- Create: `app/Modules/Crm/Activities/{TodoQuery,DailyDigest}.php`, mail `DailyDigestMail`, page `crm/todo.tsx`, réglage utilisateur « Recevoir le récapitulatif quotidien » (page paramètres)
- Modify: `app-sidebar.tsx` (entrée « À faire » avec badge), `HandleInertiaRequests` (compteur partagé, mis en cache 60 s par utilisateur — clé préfixée tenant), `routes/console.php` (07:30 Europe/Paris via `ForEachTenant`)
- Test: `tests/Feature/Crm/Activities/{TodoTest,DailyDigestTest}.php`

**Interfaces:**
- Produces: `TodoQuery::for(User $u, CarbonImmutable $now): array{overdue, today, week}` (actions à faire du responsable, triées par échéance ; « cette semaine » = jusqu'au dimanche Paris) ; page « À faire » avec trois sections (« En retard » en rouge), actions en ligne « Fait » (ouvre le compte rendu), « Reporter » (demain / +1 semaine / date), « Ouvrir la fiche » ; filtre « Équipe » pour direction et admin (toutes les actions visibles) ; badge = en retard + aujourd'hui ; mail récapitulatif (opt-in, sans compte rendu, avec titres et fiches) envoyé seulement s'il y a au moins une action.

- [ ] **Step 1: Tests** — `overdue boundary uses Europe/Paris midnight` (Review Focus 2) ; `todo respects das visibility` (Review Focus 5) ; reporter met à jour `due_at` ; badge correct et invalidé après « Fait » ; mail envoyé seulement aux utilisateurs inscrits, pas de compte rendu dans le corps ; aucun mail si rien à faire.
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès**
- [ ] **Step 5: Commit** — `feat(crm): todo screen, overdue badge and opt-in daily digest`

---

### Task 7: Planning de la semaine et compte rendu mobile

**Files:**
- Create: `app/Modules/Crm/Activities/{WeekPlanning,QuickReport}.php`, pages `crm/planning.tsx`, `crm/report.tsx`
- Test: `tests/Feature/Crm/Activities/{PlanningTest,QuickReportTest}.php`, e2e mobile

**Interfaces:**
- Produces: `GET /crm/planning?week=2026-W47&user=` — vue semaine lundi→dimanche (Paris), RDV (types « Prise de rendez-vous » et « Rendez-vous physique ») et appels planifiés avec heure, durée, fiche ; navigation semaine précédente/suivante ; choix d'un collègue pour direction/admin ; sur mobile, vue jour par jour.
  - `GET|POST /crm/report` (« Compte rendu de RDV ») : en un seul formulaire mobile — choisir un RDV du jour (ou une fiche), résultat, compte rendu, étape de l'opportunité liée (avec motif si Perdu), prochaine relance ; une seule transaction (action faite + étape + relance).

- [ ] **Step 1: Tests** — semaine ISO correcte autour du changement d'heure ; RDV d'un collègue visible seulement pour direction/admin ; compte rendu mobile : une requête crée l'action faite, change l'étape et planifie la relance ; vers Perdu sans motif → 422 et rien n'est enregistré.
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès** (+ e2e 375×812 : saisir un compte rendu en moins de 6 interactions)
- [ ] **Step 5: Commit** — `feat(crm): weekly planning and one-form mobile meeting report`

---

### Task 8: Flux iCal privé

**Files:**
- Create: `app/Modules/Connectors/Calendar/{IcalFeed,IcalFeedController,CalendarToken}.php`, migration `create_calendar_tokens_table`, section « Mon agenda » dans les paramètres utilisateur
- Test: `tests/Feature/Connectors/IcalFeedTest.php`

**Interfaces:**
- Produces: `calendar_tokens(tenant_id, user_id, token_hash, created_at, last_used_at, revoked_at)` ; l'utilisateur génère un lien `https://<domaine>/calendar/<token>.ics` (jeton 32 octets, stocké haché, affiché une seule fois), peut le régénérer/révoquer ; `GET /calendar/{token}.ics` hors session (résout tenant + utilisateur depuis le jeton, limiteur nommé `calendar-feed` 30/min) renvoie un VCALENDAR RFC 5545 avec VTIMEZONE Europe/Paris, un VEVENT par action datée (RDV et appels) de J-30 à J+180 de l'utilisateur : `UID` = id de l'action, `SUMMARY` = type + fiche, `LOCATION`, `DESCRIPTION` sans compte rendu (donnée personnelle hors de l'outil), `URL` vers la fiche ; `Cache-Control: private, max-age=900`.

- [ ] **Step 1: Tests** — `ical event across dst keeps local time` (Review Focus 4) ; jeton révoqué → 404 ; jeton d'un autre organisme inopérant ; aucun compte rendu dans le flux ; caractères spéciaux et retours ligne échappés (RFC 5545) ; lignes repliées à 75 octets.
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès**
- [ ] **Step 5: Commit** — `feat(connectors): private ical feed of meetings and calls`

---

### Task 9: Connecteur Microsoft 365 (envoi vers Outlook)

**Files:**
- Create: `app/Modules/Connectors/Calendar/{Microsoft365Connector,GraphClient,PushActivityToOutlook}.php`, migration `create_calendar_links_table` (OAuth par utilisateur, jetons chiffrés), routes OAuth, section « Connecter Outlook » ; doc `tms/docs/connectors.md` (déclaration de l'application Azure : permissions `Calendars.ReadWrite`, `offline_access`, URI de redirection)
- Test: `tests/Feature/Connectors/Microsoft365Test.php` (`Http::fake`, aucun appel réel)

**Interfaces:**
- Produces: connecteur `microsoft365` (kind `Sync`, réglages : `tenant` Azure (ou `common`), `client_id` ; secret : `client_secret`) via la couche connecteurs (HostPolicy : hôtes `login.microsoftonline.com` et `graph.microsoft.com` uniquement) ; chaque utilisateur clique « Connecter Outlook » (OAuth code + PKCE, `state` signé) ; jetons d'accès/rafraîchissement chiffrés par utilisateur ; à chaque création/modification/suppression d'une action datée de l'utilisateur, job `PushActivityToOutlook` (tenant-aware, idempotent, `outlook_event_id` mémorisé) crée/maj/supprime l'évènement (sans compte rendu) ; erreurs journalisées dans `connector_runs` sans données personnelles ; jeton révoqué côté Microsoft → lien marqué « À reconnecter » et message à l'utilisateur.

- [ ] **Step 1: Tests** — flux OAuth complet simulé (state invalide → refus) ; création d'une action RDV → POST `/me/events` avec `Europe/Paris` ; modification → PATCH ; suppression → DELETE ; 401 de Graph → rafraîchissement puis nouvel essai ; rafraîchissement refusé → « À reconnecter » ; aucun compte rendu envoyé ; job rejoué → pas de doublon.
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès**
- [ ] **Step 5: Commit** — `feat(connectors): microsoft 365 calendar push for meetings`

---

### Task 10: Durcissement (reprises restantes de 1b)

**Files:** `app/Modules/Crm/Import/{RunImport,ImportBatch,ImportFiles,ImportController}.php`, `app/Modules/Connectors/Core/HostPolicy.php` + clients HTTP, `app/Modules/Crm/Timeline/AttachmentController.php` + purge, `app/Modules/Crm/Export/PersonExport.php`, test de volume
**Interfaces / exigences:**
- Watchdog planifié (toutes les 10 min, `ForEachTenant`) : un lot sans avancement depuis 15 min est **repris** depuis `cursor_line` (une fois), puis marqué échoué si cela se reproduit.
- Une erreur de base non liée à l'unicité n'arrête plus l'import : la ligne passe en erreur (savepoint par ligne au rejeu du paquet).
- Type réel du fichier importé vérifié (signature ZIP pour xlsx, texte pour csv).
- Clients HTTP des connecteurs : IP résolue épinglée pour la requête (`CURLOPT_RESOLVE`).
- Pièces jointes des fiches en corbeille depuis plus de 12 mois : fichiers purgés (planifié), ligne conservée avec « Fichier supprimé ».
- Export personnes : colonnes `Système source`, `Identifiant source`, `Base légale`.
- `original_name` d'un lot : remplacé par « fichier-import.<ext> » à la purge des 30 jours.
- Test de volume recherche : 50 000 fiches, recherche < 1 s dans le conteneur de test (`@group slow`).
- [ ] **Step 1: Tests (un par exigence) ; Step 2: échec ; Step 3: implémenter ; Step 4: succès ; Step 5: Commit** — `fix(crm): import watchdog and row isolation, http ip pinning, attachment purge, export columns`

---

### Task 11: Navigation, documentation, e2e, version

**Files:** `app-sidebar.tsx` (« Commercial » : Pipeline, Opportunités, À faire (badge), Planning, Entreprises, Personnes, Imports, RGPD), `dashboard.tsx` (« Mes relances » : en retard / aujourd'hui ; pipeline pondéré du mois ; prochains RDV), `tms/docs/crm.md` (pipeline, actions, relances, planning, compte rendu mobile, agenda iCal/Outlook), `tms/docs/data-dictionary.md`, `tms/docs/admin.md`, `CLAUDE.md` (Opportunities/Activities, LinkedData, curseur timeline), `CHANGELOG.md` 0.3.0, `VERSION`, `tests/e2e/pipeline.spec.ts`
- [ ] **Step 1: e2e** — créer une opportunité depuis une entreprise, la glisser jusqu'à « Proposition », tenter « Perdu » sans motif (refus) puis avec motif ; saisie rapide d'un appel avec relance demain ; la relance apparaît dans « À faire » et dans le planning ; compte rendu mobile 375×812.
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès** — Pest complet, pint, phpstan, lint/types/build, `npm run e2e`
- [ ] **Step 5: Commit** — `docs(crm): pipeline guide, navigation and lot 1c finishing touches`

---

## Ordre d'exécution

1 → 2 → 3 → 4 → 5 → 6 → 7 → **point de livraison : Sébastien utilise pipeline, actions, « À faire » et planning** → 8 → 9 → 10 → 11

## Hors 1c (rappel)

Catalogue de formations et devis (1d, le lien opportunité ↔ formations s'y ajoute) ; tableaux de bord et objectifs de CA (1e, reporté après le démarrage du TMS) ; synchronisation Outlook → TMS (sens inverse) ; campagnes d'e-mailing.
