# Lot 1b – Fiches, import et RGPD Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre à CAP FORMATION de saisir, rechercher, dédoublonner, importer et exporter ses entreprises, particuliers et interlocuteurs, dans le respect du RGPD — première livraison utilisable au quotidien — après avoir soldé les reprises bloquantes du lot 1a (gestion des utilisateurs).

**Architecture:** Nouveau module `app/Modules/Crm` (modèles `Organisation`, `Person`, `Affiliation`, `Note`, `Attachment`, services de doublons, recherche, timeline, import, RGPD) bâti sur le socle 1a : RLS + `BelongsToTenant`, `Auditable`, référentiels (`ReferenceItem` par **id**), connecteurs (nouveau connecteur `Sirene`), journal d'audit. L'import est un moteur générique (lecteurs CSV/XLSX → correspondances → simulation → exécution en job → lot annulable). Le RGPD repose sur un exécuteur « pour chaque organisme » planifié.

**Tech Stack:** Laravel 12, Inertia/React 19/TS, PostgreSQL 17 (`pg_trgm`, `unaccent`), Redis/Horizon, `openspout/openspout` 4 (lecture/écriture XLSX/CSV en flux), Pest, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-11-tms-design.md` (§7 modèle, §8 « 1b – Fiches et import » + RGPD, §9 import/export, §10 connecteurs) ; sources `Cdc/cdc-activité-commercial.md` (« Gestion des contacts et comptes ») ; reprises `docs/superpowers/plans/2026-11-12-lot1b-carryover.md` ; règles `CLAUDE.md`.

## Global Constraints

- Toutes les règles de `CLAUDE.md` (isolation RLS + `BelongsToTenant`, aucun secret, français, ASVS L2, connecteurs isolés, TDD, référentiels stockés **par id (FK)** + `ReferenceUsage::register`, clés de cache préfixées par le tenant, tenancy des jobs via payload).
- UUID v7 ; suppression logique (`SoftDeletes`) sur `organisations`, `people`, `affiliations`, `notes`, `attachments`.
- CSV : séparateur `;` par défaut, détection `,` et tabulation ; encodages UTF-8 (avec ou sans BOM) et Windows-1252. Export : `.xlsx`.
- SIRET stocké en **chaîne de 14 chiffres** (jamais numérique) ; contrôle Luhn existant `App\Rules\Siret` (Task 7 du lot 1a — vérifier l'emplacement exact).
- Statut d'organisation : `prospect`, `client`, `inactif` (libellés « Prospect », « Client », « Inactif »).
- Conservation RGPD par défaut : **3 ans après le dernier contact** pour une personne sans relation contractuelle, puis anonymisation ; paramétrable par organisme (1–10 ans).
- Les valeurs des champs personnels (nom, prénom, e-mails, téléphones, adresse, date de naissance, projet) ne sont **jamais** stockées en clair dans `audit_logs` : l'audit enregistre le nom du champ modifié avec la valeur `"[donnée personnelle]"`.
- Saisie rapide : une fiche particulier ou entreprise se crée avec un seul champ obligatoire (nom / raison sociale) ; tout le reste est facultatif.
- Interface utilisable à 375 px de large (listes en cartes sur mobile, fiche en une colonne).
- Branche de travail : `lot1b-fiches` créée depuis `main`.

## Review Focus

1. **Fichier Excel « réel » importé en CSV Windows-1252** (accents, `;`, SIRET passé en notation scientifique `7,32829E+13` ou perdu ses zéros initiaux, lignes vides en fin, colonnes en double) → l'import détecte l'encodage, refuse la ligne au SIRET altéré avec un message clair, ignore les lignes vides, ne plante pas. Test : Task 9 `imports a windows-1252 excel export with damaged siret`.
2. **Doublons : jamais entre organismes** → la détection ne propose que des fiches du même organisme (RLS), même avec SIRET identique dans un autre. Test : Task 6 `duplicate finder ignores other tenants`.
3. **Fusion de deux personnes rattachées à la même entreprise** → un seul rattachement subsiste (fusion des champs), notes et pièces jointes déplacées, aucune contrainte unique violée. Test : Task 6 `merging people deduplicates affiliations`.
4. **Export Excel avec une cellule commençant par `=`, `+`, `-`, `@`** (injection de formule) → la valeur est préfixée d'une apostrophe dans l'export. Test : Task 7 `export neutralises formula injection`.
5. **Anonymisation d'une personne présente dans un lot d'import et dans le journal** → les traces ne contiennent plus aucune donnée personnelle (lot d'import : valeurs source purgées pour cette ligne ; audit : valeurs déjà masquées), annulation du lot toujours possible pour les autres lignes. Test : Task 11 `anonymisation scrubs import rows and keeps batch undoable`.

---

## Décisions de ce plan (à valider par Sébastien en relisant)

- **Forme juridique des organisations clientes** : référentiel `legal_form_org` (paramétrable), pas l'enum `LegalForm` (réservé aux sociétés émettrices). **[choix Claude]**
- **Visibilité** : tout utilisateur ayant `crm.view` voit toutes les fiches de l'organisme, **sauf** le rôle Responsable de DAS qui ne voit que les organisations rattachées à ses DAS et les personnes liées à celles-ci ou sans organisation mais d'un DAS suivi. Le filtre « Mes fiches » (responsable = moi) est un filtre de liste, pas une restriction. **[choix Claude]**
- **Dernier contact** (`last_contact_at`) : mis à jour par les notes en 1b, puis par les actions en 1c. Base du calcul RGPD. **[choix Claude]**
- **Personne « particulier »** : une personne sans rattachement actif à une organisation et marquée `is_individual = true` ; un interlocuteur est une personne avec ≥ 1 rattachement. Une même personne peut être les deux. **[choix Claude]**

---

## File Structure

```
tms/app/Modules/Core/
  Tenancy/ForEachTenant.php           exécuteur planifié par organisme
  Tenancy/TenantStorage.php           chemins de stockage par organisme
  Tenancy/TenantLogContext.php        contexte de log tenant_id
  Access/PermissionSync.php           ajoute les nouvelles permissions aux rôles existants
tms/app/Modules/Crm/
  CrmServiceProvider.php              routes, ReferenceUsage::register, policies, timeline providers
  Permissions.php                     constantes crm.*
  Visibility/CrmVisibility.php        scopes de visibilité (Responsable DAS)
  Organisations/ Organisation.php, OrganisationStatus.php, OrganisationController.php,
                 OrganisationRequest.php, OrganisationPolicy.php
  People/        Person.php, PersonController.php, PersonRequest.php, PersonPolicy.php
  Affiliations/  Affiliation.php, AffiliationController.php
  Timeline/      TimelineEntry.php, TimelineProvider.php (interface), Timeline.php,
                 Notes/Note.php, Notes/NoteController.php,
                 Attachments/Attachment.php, Attachments/AttachmentController.php
  Duplicates/    DuplicateFinder.php, DuplicateCandidate.php, Merger.php, MergeController.php
  Search/        GlobalSearch.php, SearchController.php, SavedFilter.php, SavedFilterController.php
  Export/        XlsxExporter.php, ExportController.php
  Import/        Readers/{CsvReader,XlsxReader,TabularReader}.php, EncodingDetector.php,
                 ImportProfile.php, ImportBatch.php, ImportRow.php, ImportTarget.php (enum),
                 FieldMap.php, ValueMapper.php, Simulator.php, RunImport.php (job),
                 UndoImport.php (job), ImportController.php
  Gdpr/          LegalBasis.php (enum), Consent.php, RetentionPolicy.php, Anonymiser.php,
                 AnonymiseExpired.php (job), PersonDataExport.php, GdprController.php
tms/app/Modules/Connectors/Sirene/  SireneConnector.php, SireneClient.php, CompanyLookupResult.php
tms/routes/crm.php, tms/routes/crm-import.php
tms/resources/js/pages/crm/{organisations,people,import,duplicates,gdpr}/…
tms/resources/js/components/crm/{timeline,filters,duplicate-warning,quick-create}.tsx
tms/tests/Feature/Crm/…, tests/Feature/Connectors/SireneConnectorTest.php, tests/Arch/ModelsArchTest.php
tms/tests/Fixtures/imports/*.csv|*.xlsx
tms/tests/e2e/crm.spec.ts
tms/docs/crm.md
```

---

### Task 1: Gestion des utilisateurs et reprises bloquantes du lot 1a

**Files:**
- Modify: `app/Modules/Core/Users/{User.php,InvitationController.php}`, `resources/js/pages/admin/users/index.tsx`, `resources/views/errors/layout.blade.php`, `app/Modules/Connectors/Core/ConnectorController.php`, `app/Console/Commands/Concerns/ResolvesTenantOption.php`, `app/Modules/Connectors/Smtp/TenantMailer.php`
- Create: `app/Modules/Core/Users/UserController.php`, `routes/users.php` (étendre)
- Test: `tests/Feature/Core/UserLifecycleTest.php`, ajouts dans `ConnectorsTest.php`, `UserRecoveryCommandsTest.php`, `InvitationDeliveryTest.php`, `ErrorPagesTest.php`

**Interfaces:**
- Produces: routes `PATCH /admin/users/{user}/role` (`role`), `POST /admin/users/{user}/deactivate`, `POST /admin/users/{user}/reactivate`, `POST /admin/invitations/{invitation}/resend`, `DELETE /admin/invitations/{invitation}` (toutes `can:users.manage`) ; `User::reactivate(): void` ; audit `users.deactivated`, `users.reactivated`, `access.role_changed` (existant), `users.invitation_resent`, `users.invitation_revoked`.
- Règles : on ne peut ni se désactiver soi-même, ni retirer le **dernier** `admin_organisme` actif (rôle ou désactivation) → 422 « Il doit rester au moins un administrateur actif. ».

- [ ] **Step 1: Tests**

```php
it('admin deactivates a user and their sessions end', fn () => /* deactivate → deactivated_at set, sessions rows gone, audit users.deactivated */);
it('cannot deactivate or demote the last active admin', fn () => /* 422 message exact */);
it('cannot deactivate oneself', fn () => /* 422 « Vous ne pouvez pas désactiver votre propre compte. » */);
it('changes a user role and records old and new', fn () => /* access.role_changed old=commercial new=direction */);
it('resends an invitation with a new token and expires the old one', fn () => /* old token 404, new token works */);
it('revokes a pending invitation', fn () => /* activation → 404 */);
it('assistant cannot manage users', fn () => /* 403 on each route */);
it('error pages never show framework english messages', function () {
    // 429 ThrottleRequestsException, 405, 413 → page contient « Trop de tentatives », « Méthode non autorisée », « Fichier trop volumineux »
    // et ne contient ni 'Too Many Attempts' ni 'Supported methods' ni un chemin de route
});
it('changing smtp encryption requires re-entering the password', fn () => /* tls→none sans secret → 422 */);
it('recovery commands require --tenant in multi mode', fn () => /* config tms.mode=multi, sans --tenant → échec + message */);
it('failover mailer ending in log is treated as delivering nowhere', fn () => /* TenantMailer::deliversNowhere true → lien affiché */);
```

- [ ] **Step 2: Lancer — échec attendu** — `docker compose exec -T app php artisan test --filter='UserLifecycle|ErrorPages|ConnectorsTest|UserRecovery|InvitationDelivery'`
- [ ] **Step 3: Implémenter** contrôleur, règles (dans une transaction avec `lockForUpdate` sur les admins pour le « dernier admin »), page `admin/users/index.tsx` (colonnes Nom, E-mail, Rôle [select], Statut, 2FA, Dernière connexion ; actions ; section « Invitations en attente » avec Renvoyer/Révoquer ; lien d'activation affiché si l'envoi a échoué — comportement existant), pages d'erreur : n'afficher `getMessage()` que pour une `HttpException` levée par `abort()` de l'application (marqueur : message commençant par une majuscule française ou exception de classe exacte `Symfony\Component\HttpKernel\Exception\HttpException`), sinon texte français par code ; `encryption` ajouté à `SERVER_SETTINGS` ; `ResolvesTenantOption` exige `--tenant` si `tms.mode=multi` ; `deliversNowhere` déroule `failover`/`roundrobin`.
- [ ] **Step 4: Lancer — succès** (mêmes filtres + suite complète)
- [ ] **Step 5: Commit** — `feat(users): manage users, roles and invitations; close lot 1a carry-overs`

---

### Task 2: Fondations du module CRM

**Files:**
- Create: `app/Modules/Core/Tenancy/{ForEachTenant.php,TenantStorage.php,TenantLogContext.php}`, `app/Modules/Core/Access/PermissionSync.php`, `app/Console/Commands/PermissionsSync.php`, `app/Modules/Crm/{CrmServiceProvider.php,Permissions.php}`, `tests/Arch/ModelsArchTest.php`, migration `enable_search_extensions` (owner : `CREATE EXTENSION IF NOT EXISTS pg_trgm; CREATE EXTENSION IF NOT EXISTS unaccent;` + fonction `immutable_unaccent(text)` IMMUTABLE pour index)
- Modify: `app/Modules/Core/Access/Permissions.php` (agrège les permissions des modules), `Role` defaults, `ResolveTenant` + `QueueTenancy` (contexte de log), `ops/migrate.sh` (appelle `tms:permissions:sync` après migration), `bootstrap/providers.php`
- Test: `tests/Feature/Core/ForEachTenantTest.php`, `TenantStorageTest.php`, `PermissionSyncTest.php`, `tests/Arch/ModelsArchTest.php`

**Interfaces:**
- Produces:
  - `ForEachTenant::run(callable $fn, bool $includeSuspended = false): void` — itère les organismes actifs, `TenantContext::run` pour chacun, isole les exceptions (log + continue), ne laisse aucun contexte derrière.
  - `TenantStorage::path(string $area, string $relative = ''): string` → `tenants/<tenant_id>/<area>/<relative>` sur le disque `local` ; lève `MissingTenantContext` hors contexte ; `area` ∈ `imports`, `attachments`, `exports`, `gdpr`.
  - `Log::shareContext(['tenant_id' => …])` posé par `ResolveTenant` et le listener `JobProcessing`, retiré au `clear()`.
  - `App\Modules\Crm\Permissions` : `VIEW='crm.view'`, `CREATE='crm.create'`, `UPDATE='crm.update'`, `DELETE='crm.delete'`, `MERGE='crm.merge'`, `IMPORT='crm.import'`, `EXPORT='crm.export'`, `GDPR='crm.gdpr'`. Matrice par défaut : admin = tout ; direction = view, export, gdpr ; commercial & charge_relations = view, create, update, delete, merge, import, export ; assistant = view, create, update ; responsable_das = view, export.
  - `PermissionSync::run(): void` — crée les permissions manquantes et ajoute aux rôles **non modifiés depuis leur création par défaut** les permissions par défaut nouvelles (une table `role_permission_defaults_applied(tenant_id, role, permission)` mémorise ce qui a déjà été proposé, pour ne jamais ré-ajouter une permission retirée par l'admin). Commande `php artisan tms:permissions:sync` (idempotente, tous organismes via `ForEachTenant`).
  - Arch test : toute classe Eloquent dont la table a `tenant_id` utilise `BelongsToTenant` ; toute classe sous `App\Modules\Crm` de type Model utilise `Auditable`.

- [ ] **Step 1: Tests** — `runs the callback once per active tenant with its context`; `continues after one tenant fails`; `storage path is prefixed by tenant and refuses without context`; `sync adds new default permissions to existing tenants once`; `sync never re-adds a permission an admin removed`; `log context carries tenant id during a request and a job`; arch test qui échoue sur un modèle fixture sans le trait.
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès** — `docker compose exec -T app php artisan test --filter='ForEachTenant|TenantStorage|PermissionSync|ModelsArch'`
- [ ] **Step 5: Commit** — `feat(core): per-tenant runner, storage paths, log context, permission sync and CRM permissions`

---

### Task 3: Organisations

**Files:**
- Create: migrations `create_organisations_table`, `create_organisation_das_table` (pivot, tenant_id + RLS), `app/Modules/Crm/Organisations/*`, `app/Modules/Crm/Visibility/CrmVisibility.php`, `routes/crm.php`, pages `crm/organisations/{index,show,form}.tsx`
- Test: `tests/Feature/Crm/OrganisationsTest.php`, `CrmVisibilityTest.php`

**Interfaces:**
- Produces:
  - Table `organisations` : `id`, `tenant_id`, `name` (requis, 255), `siret` (char 14 null, unique partiel `(tenant_id, siret) where siret is not null and deleted_at is null`), `naf_code` (null, 6), `legal_form_id`, `sector_id`, `establishment_type_id`, `company_size_id`, `origin_id` (FK `reference_items`, null), `status` (`prospect` défaut), `responsible_user_id` (FK users null), `email`, `phone`, `website`, `address_line1`, `address_line2`, `postal_code`, `city`, `country` (défaut `FR`), `notes_summary` text null, `last_contact_at` timestamptz null, `source_system` + `source_id` (null, unique partiel `(tenant_id, source_system, source_id)`), `search_vector` (colonne générée `immutable_unaccent(lower(name || ' ' || coalesce(city,'') || ' ' || coalesce(siret,'')))` + index GIN trgm), timestamps, soft deletes, created_by/updated_by.
  - `OrganisationStatus` enum `Prospect|Client|Inactif` avec `label()`.
  - `ReferenceUsage::register(ReferenceList::LegalFormOrg, 'organisations', 'legal_form_id')` et idem `Sector`, `EstablishmentType`, `CompanySize`, `ContactOrigin`, et `Das` sur `organisation_das.das_id`.
  - `CrmVisibility::organisations(Builder $q, User $u): Builder` et `::people(Builder $q, User $u): Builder` — no-op sauf rôle `responsable_das` (filtre par `user_das` : table pivot `user_das(tenant_id, user_id, das_id)` créée ici, gérée depuis la page utilisateurs : sélecteur « DAS suivis » visible pour ce rôle).
  - Routes : `GET /crm/organisations` (liste paginée 25, filtres : texte, statut, DAS, secteur, responsable, « mes fiches », origine ; tri nom/ville/dernier contact/création), `GET /crm/organisations/create`, `POST /crm/organisations`, `GET /crm/organisations/{organisation}`, `PUT …`, `DELETE …` (soft), `POST …/restore`. Policies sur `crm.*` + visibilité. Création possible avec seulement `name`.
  - Audit : `Organisation` utilise `Auditable` (pas de PII sur une personne morale, sauf `email`/`phone` génériques — non masqués).

- [ ] **Step 1: Tests** — création minimale (`name` seul) ; SIRET invalide (Luhn) → 422 ; SIRET déjà présent → 422 « Une organisation avec ce SIRET existe déjà » avec lien vers la fiche ; SIRET identique dans un autre organisme accepté ; filtres et tri ; « mes fiches » ; responsable de DAS ne voit que ses DAS (liste, fiche → 404, recherche) ; assistant ne peut pas supprimer ; suppression logique puis restauration ; référentiel utilisé par une organisation → suppression du référentiel refusée (via `ReferenceUsage`) ; `search_vector` trouve « hotel » pour « Hôtel ».
- [ ] **Step 2: échec ; Step 3: implémenter** (React : liste en tableau ≥ 768 px, cartes en dessous ; fiche avec en-tête, onglets « Informations », « Interlocuteurs », « Historique » (Task 5) ; formulaire avec sélecteurs alimentés par `ReferenceItem::options`) **; Step 4: succès**
- [ ] **Step 5: Commit** — `feat(crm): organisations with filters, DAS visibility and soft delete`

---

### Task 4: Personnes et rattachements

**Files:**
- Create: migrations `create_people_table`, `create_affiliations_table`, `app/Modules/Crm/People/*`, `app/Modules/Crm/Affiliations/*`, pages `crm/people/{index,show,form}.tsx`, composant onglet « Interlocuteurs » de la fiche organisation
- Test: `tests/Feature/Crm/PeopleTest.php`, `AffiliationsTest.php`, `PersonalDataAuditTest.php`

**Interfaces:**
- Produces:
  - Table `people` : `id`, `tenant_id`, `civility` (`M.`, `Mme`, null), `first_name`, `last_name` (requis), `email`, `email_secondary`, `phone`, `mobile`, `address_*` (comme organisations), `birth_date` date null, `is_individual` bool (défaut false), `project` text null, `professional_situation_id`, `funding_mode_id`, `origin_id` (FK reference_items null), `status` (même enum que organisations), `responsible_user_id`, `last_contact_at`, `source_system`, `source_id`, `anonymised_at` null, `search_vector` (nom + prénom + e-mail + téléphones normalisés), timestamps, soft deletes, created_by/updated_by.
  - Table `affiliations` : `id`, `tenant_id`, `person_id`, `organisation_id`, `job_title`, `decision_role_id` (FK reference_items null), `work_email`, `work_phone`, `is_referent` bool, `started_on`, `ended_on` null, unique partiel `(tenant_id, person_id, organisation_id) where ended_on is null and deleted_at is null`.
  - `Person::PERSONAL_FIELDS = ['first_name','last_name','email','email_secondary','phone','mobile','address_line1','address_line2','postal_code','city','birth_date','project']` ; `Affiliation::PERSONAL_FIELDS = ['work_email','work_phone']` ; ces champs sont audités en `"[donnée personnelle]"` (étendre `Auditable` avec `protected array $auditPersonal`, valeur de masque distincte de `[masqué]`).
  - Téléphones normalisés E.164 FR à l'enregistrement (`PhoneNumber::normalise(string): ?string`, `0612345678` → `+33612345678`, conserve la saisie si non reconnue).
  - Routes `crm/people` (CRUD comme organisations ; filtres : texte, type particulier/interlocuteur, statut, situation, financement, origine, responsable, « mes fiches ») ; `POST /crm/organisations/{organisation}/affiliations`, `PUT/DELETE /crm/affiliations/{affiliation}` ; création d'un interlocuteur directement depuis la fiche organisation (personne + rattachement en une saisie).
  - `ReferenceUsage::register` pour `ProfessionalSituation`, `FundingMode`, `ContactOrigin` (people) et `DecisionRole` (affiliations).

- [ ] **Step 1: Tests** — particulier minimal (nom seul + `is_individual`) ; interlocuteur créé depuis une organisation ; un seul rattachement actif par couple personne/organisation ; fin de rattachement (`ended_on`) puis nouveau rattachement autorisé ; téléphone normalisé ; audit d'une modification de nom contient `"[donnée personnelle]"` et jamais la valeur ; responsable de DAS voit les personnes liées à ses organisations ; suppression d'une organisation n'efface pas les personnes (rattachements soft-deleted).
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès**
- [ ] **Step 5: Commit** — `feat(crm): people, individuals and affiliations with personal-data-safe audit`

---

### Task 5: Fil chronologique, notes et pièces jointes

**Files:**
- Create: `app/Modules/Crm/Timeline/*`, migrations `create_notes_table`, `create_attachments_table`, composant `components/crm/timeline.tsx`
- Test: `tests/Feature/Crm/TimelineTest.php`, `AttachmentsTest.php`

**Interfaces:**
- Produces:
  - `interface TimelineProvider { public function entriesFor(Model $subject, ?CarbonImmutable $before, int $limit): Collection; }` retournant des `TimelineEntry(string $type, CarbonImmutable $at, string $title, ?string $body, ?string $authorName, array $meta)` ; `Timeline::for(Model $subject, ?CarbonImmutable $before = null, int $limit = 30): Collection` fusionne et trie les fournisseurs enregistrés par `CrmServiceProvider` (`NotesProvider`, `AttachmentsProvider`, `AuditProvider` — création/modification/statut, sans valeurs personnelles). 1c et 1d ajouteront les leurs.
  - `notes` : `id`, `tenant_id`, `notable_type`/`notable_id` (morph Organisation|Person), `body` text (requis, 10 000 max), `author_id`, timestamps, soft deletes. Créer une note met à jour `last_contact_at` du sujet (et de l'organisation si le sujet est une personne avec rattachement actif).
  - `attachments` : `id`, `tenant_id`, morph, `original_name`, `mime`, `size`, `path` (via `TenantStorage::path('attachments', uuid)`), `uploaded_by`, timestamps, soft deletes. Upload : max 20 Mo, types `pdf, jpg, jpeg, png, webp, docx, xlsx, odt, ods, txt, csv`, vérification MIME réelle (`finfo`), nom stocké = UUID. Téléchargement via `GET /crm/attachments/{attachment}` (policy + `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`). Jamais servi depuis `public/`.
  - Pagination du fil par curseur temporel (« Voir plus »).

- [ ] **Step 1: Tests** — fil trié du plus récent, mélange notes/pièces/audit ; note met à jour `last_contact_at` ; upload d'un `.php` renommé `.pdf` refusé ; fichier d'un autre organisme → 404 ; téléchargement sans `crm.view` → 403 ; fichier stocké sous `tenants/<id>/attachments/` ; l'entrée d'audit d'une personne n'expose aucune valeur personnelle dans le fil.
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès**
- [ ] **Step 5: Commit** — `feat(crm): timeline with notes, attachments and audit entries`

---

### Task 6: Doublons et fusion

**Files:**
- Create: `app/Modules/Crm/Duplicates/*`, pages `crm/duplicates/{merge}.tsx`, composant `components/crm/duplicate-warning.tsx`
- Test: `tests/Feature/Crm/DuplicateFinderTest.php`, `MergeTest.php`

**Interfaces:**
- Produces:
  - `DuplicateFinder::forOrganisation(array $attributes, ?string $ignoreId = null): Collection<DuplicateCandidate>` et `::forPerson(array $attributes, ?string $ignoreId = null)` ; `DuplicateCandidate(string $id, string $label, string $reason, float $score)` ; raisons et scores : SIRET identique → `siret`, 1.0 ; e-mail identique (insensible à la casse) → `email`, 0.95 ; téléphone normalisé identique → `phone`, 0.9 ; similarité `similarity(immutable_unaccent(lower(name)), …) ≥ 0.6` **et** même code postal (ou même ville) → `name`, score = similarité. Maximum 5 candidats, triés par score. Requêtes sous RLS (jamais d'autre organisme).
  - Création/édition : si candidats et pas `confirm_duplicate=1` → 409 avec la liste ; l'UI affiche « Fiches similaires » avec « Ouvrir », « Fusionner », « Créer quand même ».
  - `Merger::organisations(Organisation $keep, Organisation $drop, array $fieldChoices): Organisation` et `::people(Person $keep, Person $drop, array $fieldChoices): Person` — dans une transaction : copie des champs choisis (`field => 'keep'|'drop'`), déplacement des rattachements (fusion si même couple : garde le plus ancien `started_on`, complète les champs vides), notes, pièces jointes, DAS (union), `source_*` conservés dans `merged_sources` jsonb sur `keep` ; `drop` soft-deleted avec `merged_into_id` ; audit `crm.merged` (ids seulement). Permission `crm.merge`.
  - `GET /crm/merge/{type}/{keep}/{drop}` (écran comparatif champ par champ), `POST /crm/merge/{type}`.

- [ ] **Step 1: Tests** — chaque règle de détection ; `duplicate finder ignores other tenants` (Review Focus 2) ; 409 puis création confirmée ; `merging people deduplicates affiliations` (Review Focus 3) ; notes et pièces déplacées ; `drop` porte `merged_into_id` et l'ouverture de sa fiche redirige vers `keep` (fusion irréversible) ; assistant sans `crm.merge` → 403.
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès**
- [ ] **Step 5: Commit** — `feat(crm): duplicate detection and field-by-field merge`

---

### Task 7: Recherche globale, filtres enregistrés, colonnes et export Excel

**Files:**
- Create: `app/Modules/Crm/Search/*`, migration `create_saved_filters_table`, `app/Modules/Crm/Export/*`, composants `components/crm/filters.tsx`, barre de recherche dans `app-header.tsx`
- Modify: `composer.json` (`openspout/openspout:^4`)
- Test: `tests/Feature/Crm/GlobalSearchTest.php`, `SavedFiltersTest.php`, `ExportTest.php`

**Interfaces:**
- Produces:
  - `GlobalSearch::query(string $term, User $u, int $limit = 10): array{organisations: list, people: list}` — recherche par `search_vector` (trigram) + égalité exacte SIRET / e-mail / téléphone normalisé ; respecte `CrmVisibility` ; `GET /crm/search?q=` (JSON, min 2 caractères, throttle 60/min) ; raccourci clavier `/` ou `Ctrl+K` dans l'en-tête.
  - `saved_filters` : `id`, `tenant_id`, `user_id`, `resource` (`organisations`|`people`), `name`, `filters` jsonb, `columns` jsonb, `is_shared` bool ; CRUD `crm/saved-filters` (un filtre partagé est visible par tout l'organisme, modifiable par son auteur seulement).
  - Colonnes choisies par liste (persistées dans le filtre ou en préférence utilisateur).
  - `XlsxExporter::export(Builder $query, array $columns, string $filename): StreamedResponse` — flux OpenSpout, en-têtes français, dates `JJ/MM/AAAA`, **neutralisation des formules** (valeur commençant par `=`, `+`, `-`, `@`, tabulation ou CR → préfixe `'`), max 50 000 lignes (au-delà : 422 « Affinez les filtres »). `GET /crm/{resource}/export?…filtres` avec `crm.export` ; audit `AuditRecorder::export('crm.'.$resource, $filters)` (+ nombre de lignes).

- [ ] **Step 1: Tests** — recherche « hotel » trouve « Hôtel du Lac » ; recherche par SIRET exact et par `06 12 34 56 78` ; responsable de DAS ne trouve pas hors DAS ; filtre partagé visible par un autre utilisateur, non modifiable par lui ; `export neutralises formula injection` (Review Focus 4) ; export audité avec les filtres ; assistant sans `crm.export` → 403 ; export > 50 000 → 422.
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès**
- [ ] **Step 5: Commit** — `feat(crm): global search, saved filters, column choice and audited xlsx export`

---

### Task 8: Préremplissage par SIRET (connecteur Sirene)

**Files:**
- Create: `app/Modules/Connectors/Sirene/*`, route `GET /crm/sirene/{siret}`
- Modify: `ConnectorsServiceProvider` (enregistrement), formulaire organisation (bouton « Compléter depuis le SIRET »)
- Test: `tests/Feature/Connectors/SireneConnectorTest.php`

**Interfaces:**
- Consumes: `Connector` interface (lot 1a), `ConnectorRun::track`.
- Produces: `SireneConnector` (`key='sirene'`, `kind=Import`, aucun secret, réglage `base_url` défaut `https://recherche-entreprises.api.gouv.fr`, activé par défaut — **vérifier l'API publique « Recherche d'entreprises » et son format au moment de l'implémentation ; si elle a changé, adapter le client et le documenter**) ; `SireneClient::lookup(string $siret): ?CompanyLookupResult` (timeout 5 s, cache 24 h clé `tenant:<id>:sirene:<siret>`) ; `CompanyLookupResult(name, siret, nafCode, legalFormLabel, addressLine1, postalCode, city, employeeRangeLabel)` ; correspondance du libellé de forme juridique et de la tranche d'effectif vers les référentiels par libellé approché (sinon vide). Le formulaire ne remplace que les champs vides, l'utilisateur valide.
- Hôte appelé fixe (pas de SSRF : `base_url` modifiable uniquement par `connectors.manage` et validé `https://` + hôte public via `SmtpHostPolicy`-like générique — réutiliser/généraliser la politique d'hôte en `HostPolicy`).

- [ ] **Step 1: Tests** (`Http::fake`) — SIRET connu → champs ; SIRET inconnu → 404 « Aucune entreprise trouvée » ; API indisponible → 503 français, saisie manuelle possible ; résultat mis en cache ; exécution journalisée dans `connector_runs` sans données personnelles.
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès**
- [ ] **Step 5: Commit** — `feat(connectors): sirene lookup to prefill organisations by siret`

---

### Task 9: Moteur d'import (backend)

**Files:**
- Create: `app/Modules/Crm/Import/*` (sauf contrôleur UI), migrations `create_import_profiles_table`, `create_import_batches_table`, `create_import_rows_table`, `tests/Fixtures/imports/{utf8_semicolon.csv,windows1252_excel.csv,comma_bom.csv,tab.tsv,contacts.xlsx}`
- Test: `tests/Feature/Crm/Import/{ReadersTest,SimulatorTest,RunImportTest,UndoImportTest}.php`

**Interfaces:**
- Produces:
  - `TabularReader` interface : `headers(): list<string>`, `rows(): iterable<int, array<string,string>>` (flux, sans tout charger) ; `CsvReader::open(string $path)` détecte délimiteur (`;`, `,`, `\t` par comptage sur les 5 premières lignes) et encodage (`EncodingDetector::detect(string $sample): 'UTF-8'|'Windows-1252'`, BOM retiré) ; `XlsxReader::open(string $path)` (1re feuille, cellules lues comme texte — un SIRET numérique est reconstitué en chaîne 14 chiffres avec zéros, une notation scientifique est signalée comme altérée).
  - `ImportTarget` enum : `Organisations`, `People`, `Affiliations` (personne + organisation sur la même ligne), `Notes` (historique d'échanges : sujet identifié par SIRET / e-mail / `source_id`).
  - `FieldMap` : `array<string $column, string $field>` ; champs cibles listés par `ImportTarget::fields(): array<string,string>` (code → libellé français) ; correspondance proposée automatiquement par libellés normalisés (sans accents, casse, espaces ; synonymes : « raison sociale » → name, « cp » → postal_code, « tél » → phone, « mail » → email…).
  - `ValueMapper` : correspondances de valeurs vers référentiels (`'Salon' → origin_id`) ; valeurs inconnues → option « créer la valeur dans le référentiel » ou « laisser vide ».
  - `import_profiles` : `id`, `tenant_id`, `name`, `target`, `field_map` jsonb, `value_map` jsonb, `options` jsonb (délimiteur/encodage forcés, `source_system`) — le futur profil « Formevol » sera un enregistrement de cette table.
  - `import_batches` : `id`, `tenant_id`, `user_id`, `target`, `profile_id` null, `original_name`, `stored_path` (`TenantStorage::path('imports', …)`), `status` (`uploaded|simulated|running|done|failed|undone`), `options` jsonb (stratégie doublons `skip|merge|create`, `source_system`), compteurs (`total, created, updated, skipped, errors`), `report_path`, timestamps.
  - `import_rows` : `id`, `tenant_id`, `batch_id`, `line`, `source` jsonb (valeurs brutes, purgées à l'anonymisation), `status` (`created|updated|skipped|error`), `messages` jsonb, `subject_type`/`subject_id`, `created_subject` bool.
  - `Simulator::run(ImportBatch $b, FieldMap $m, ValueMapper $v): SimulationReport` (aucune écriture métier ; détection doublons via `DuplicateFinder` ; erreurs par ligne : champ requis manquant, SIRET invalide/altéré, e-mail invalide, valeur de référentiel inconnue non mappée).
  - Job `RunImport(string $batchId)` (`TenantAwareJob`, par paquets de 500 lignes en transactions, progression en base) ; un `source_id` déjà importé (`source_system` + `source_id`) → mise à jour au lieu de doublon ; rapport `.xlsx` téléchargeable (ligne, statut, messages).
  - Job `UndoImport(string $batchId)` : supprime (soft) les fiches `created_subject=true` **non modifiées depuis l'import** (`updated_at <= batch.finished_at`) et restaure rien d'autre ; signale les lignes ignorées ; statut `undone` ; audit `crm.import_undone`.
  - Audit `crm.import_run` (compteurs, nom de fichier — pas de contenu).

- [ ] **Step 1: Tests** — chaque lecteur sur sa fixture (en-têtes, accents « Hôtel », délimiteur, BOM) ; `imports a windows-1252 excel export with damaged siret` (Review Focus 1 : ligne SIRET `7,32829E+13` en erreur claire, lignes vides ignorées, le reste importé) ; correspondance automatique des colonnes ; simulation sans écriture ; stratégie `skip` / `merge` / `create` ; réimport du même fichier avec `source_system` → mises à jour, pas de doublons ; annulation ; annulation qui ignore une fiche modifiée depuis ; import d'un autre organisme invisible ; 10 000 lignes importées en < 60 s dans le conteneur de test (test marqué `@group slow`).
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès**
- [ ] **Step 5: Commit** — `feat(crm): generic csv/xlsx import engine with simulation, profiles and undo`

---

### Task 10: Assistant d'import (interface)

**Files:**
- Create: `app/Modules/Crm/Import/ImportController.php`, `routes/crm-import.php`, pages `crm/import/{index,upload,mapping,simulation,progress,report}.tsx`
- Test: `tests/Feature/Crm/Import/ImportControllerTest.php`, ajout e2e dans `tests/e2e/crm.spec.ts`

**Interfaces:**
- Consumes: Task 9.
- Produces: parcours `crm/import` (permission `crm.import`) : 1) dépôt (CSV/XLSX ≤ 20 Mo, cible, profil existant optionnel) → 2) correspondance des colonnes (aperçu des 5 premières lignes, colonnes non mappées ignorées, enregistrer comme profil) → 3) correspondance des valeurs (par référentiel) → 4) simulation (compteurs, erreurs par ligne, doublons, choix de stratégie) → 5) lancement (barre de progression par polling 2 s) → 6) rapport (téléchargement) ; historique des imports avec bouton « Annuler l'import » (confirmation, nombre de fiches concernées). Lien « Importer » depuis les listes organisations/personnes.

- [ ] **Step 1: Tests** — chaque étape et ses validations ; profil enregistré puis réutilisé (étape 2 préremplie) ; utilisateur sans `crm.import` → 403 ; lot d'un autre utilisateur visible par l'admin, pas par un commercial (sauf si son auteur) ; e2e : import d'un CSV de 3 lignes, une fiche visible dans la liste, annulation.
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès** (inclure `npm run e2e`)
- [ ] **Step 5: Commit** — `feat(crm): import wizard with mapping, simulation, progress and undo`

---

### Task 11: RGPD

**Files:**
- Create: `app/Modules/Crm/Gdpr/*`, migrations `create_consents_table`, `add_gdpr_columns_to_people` (`legal_basis`, `prospecting_opt_out_at`), page `crm/gdpr/index.tsx`, section RGPD de la fiche personne, réglage « Durée de conservation » dans `admin/security.tsx` (ou nouvelle page `admin/gdpr`)
- Modify: `routes/console.php` (planification quotidienne 03:30 de `AnonymiseExpired` via `ForEachTenant`)
- Test: `tests/Feature/Crm/Gdpr/{ConsentsTest,AnonymiserTest,PersonDataExportTest,RetentionTest}.php`

**Interfaces:**
- Produces:
  - `LegalBasis` enum : `LegitimateInterest='interet_legitime'` (« Intérêt légitime »), `Consent='consentement'`, `Contract='contrat'`, `LegalObligation='obligation_legale'` ; défaut : `interet_legitime` pour un interlocuteur, `consentement` pour un particulier.
  - `consents` : `id`, `tenant_id`, `person_id`, `channel` (`email|sms|phone|prospecting`), `granted` bool, `source` (texte : « Formulaire salon », « Import … »), `recorded_by`, `recorded_at` ; historique (on ajoute, on ne modifie pas).
  - `RetentionPolicy::for(Tenant): int` années (réglage `gdpr.retention_years`, défaut 3, bornes 1–10) ; `RetentionPolicy::expiredPeople(): Builder` — personnes sans rattachement à une organisation `client`, `last_contact_at` (ou `created_at` si nul) < maintenant − N ans, non anonymisées.
  - `Anonymiser::anonymise(Person $p, string $reason): void` — remplace les `PERSONAL_FIELDS` par `null` (nom → « Anonyme », prénom → null), supprime les e-mails/téléphones des rattachements, purge `import_rows.source` des lignes liées, supprime les pièces jointes (fichiers + lignes), supprime les notes **attachées à la personne** (les notes sur l'organisation restent), `anonymised_at`, conserve l'id et les liens statistiques ; audit `gdpr.anonymised` (id + raison) ; irréversible.
  - Job `AnonymiseExpired` : chaque nuit, pour chaque organisme, anonymise les personnes expirées **listées depuis au moins 30 jours** dans l'écran « À anonymiser » (table `gdpr_pending(tenant_id, person_id, listed_at)`), sauf exclusion manuelle (« Conserver » avec motif, audité) ; l'écran `crm/gdpr` (permission `crm.gdpr`) liste les personnes concernées et la date prévue.
  - `PersonDataExport::for(Person $p): array` + `GET /crm/people/{person}/gdpr-export` (JSON téléchargeable : identité, coordonnées, rattachements, notes, consentements, pièces jointes listées) ; audit `gdpr.exported`.
  - Actions sur la fiche personne : « Exporter ses données », « Opposition à la prospection » (pose `prospecting_opt_out_at` + consentement `prospecting=false`), « Anonymiser maintenant » (confirmation en tapant le nom).

- [ ] **Step 1: Tests** — base légale par défaut selon le type ; historique des consentements ; liste des expirés selon `last_contact_at` et la durée paramétrée ; un client (organisation `client`) n'est jamais listé ; anonymisation effective après 30 jours, pas avant ; « Conserver » retire de la liste ; `anonymisation scrubs import rows and keeps batch undoable` (Review Focus 5) ; export JSON complet, audité, 404 pour un autre organisme ; anonymiser exige `crm.gdpr`.
- [ ] **Step 2: échec ; Step 3: implémenter ; Step 4: succès**
- [ ] **Step 5: Commit** — `feat(crm): gdpr legal basis, consents, retention, anonymisation and data export`

---

### Task 12: Parcours, documentation et finitions

**Files:**
- Create: `tms/docs/crm.md` (guide utilisateur en français : fiches, recherche, doublons, import pas à pas, export, RGPD), `tests/e2e/crm.spec.ts` (si non créé en Task 10, le compléter)
- Modify: `app-sidebar.tsx` (section « Commercial » : Organisations, Personnes, Import ; « RGPD » si `crm.gdpr`), `dashboard.tsx` (raccourcis : « Nouvelle organisation », « Nouveau particulier », recherche, 5 dernières fiches consultées), `tms/docs/data-dictionary.md`, `tms/docs/admin.md` (DAS suivis, permissions CRM), `CLAUDE.md` (module Crm, `TimelineProvider`, `ForEachTenant`, `TenantStorage`, `PermissionSync`), `CHANGELOG.md` (0.2.0), `VERSION`
- Test: `tests/e2e/crm.spec.ts`

**Interfaces:**
- Consumes: Tasks 1–11.

- [ ] **Step 1: e2e** — `crm.spec.ts` : créer une entreprise par SIRET (Sirene simulé via le réglage `base_url` pointant vers un faux serveur du stack e2e, ou désactivé avec saisie manuelle), ajouter un interlocuteur, une note, voir le fil ; créer un doublon → avertissement → fusion ; recherche globale ; export Excel téléchargé ; sur mobile (viewport 375×812) la fiche est lisible et le bouton « Ajouter une note » accessible.
- [ ] **Step 2: échec ; Step 3: implémenter les finitions ; Step 4: succès** — suite Pest complète, pint, phpstan, lint/types/build, `npm run e2e`.
- [ ] **Step 5: Commit** — `docs(crm): user guide, navigation and lot 1b finishing touches`

---

## Ordre d'exécution **[décidé par Sébastien : saisie de données d'abord]**

2 → 3 → 4 → 5 → **point de livraison : Sébastien saisit des entreprises, personnes et notes** → 6 → 7 → 8 → 9 → 10 → 11 → 1 → 12

- Task 2 reste en tête uniquement pour ce dont les fiches ont besoin (permissions CRM + `PermissionSync`, `TenantStorage`, extensions de recherche, arch test) ; `ForEachTenant` et le contexte de log peuvent glisser avec la Task 11 si besoin.
- Vocabulaire de l'interface : le modèle `Organisation` s'affiche **« Entreprises »** (menu, titres, boutons) pour ne pas confondre avec l'« organisme » qui utilise l'application ; l'écran dit « Entreprise » même pour une association ou une collectivité.

## Hors 1b (rappel)

Pipeline, actions, relances, Outlook (1c) ; catalogue et devis (1d) ; tableaux de bord et fidélisation (1e) ; profil d'import Formevol (dès réception d'un export Formevol — un enregistrement `import_profiles`, aucun code). Les points « Durcissement avant production » de `2026-11-12-lot1b-carryover.md` non repris ici restent à planifier avant la première mise en production.
