# TMS sur mesure — guide pour Claude

TMS (Training Management System) multi-organismes pour organismes de formation, inspiré de Formdev. Premier client : CAP FORMATION (SCOP). Produit destiné à être vendu à d'autres organismes.

## Documents de référence

- Spécifications sources : `Cdc/Cartographie projet – TMS sur mesure.md` (périmètre TMS complet) et `Cdc/cdc-activité-commercial.md` (gestion commerciale détaillée).
- Conception validée : `docs/superpowers/specs/2026-11-11-tms-design.md` — **autorité** en cas de doute.
- Plans d'implémentation : `docs/superpowers/plans/` (un plan par incrément : 1a socle, 1b fiches/import, 1c pipeline, 1d devis, 1e pilotage). Documentation d'exploitation et utilisateur : `tms/docs/` (`install`, `admin`, `crm`, `connectors`, `data-dictionary`, `release`).

## Stack

Laravel 12 (PHP 8.4) + Inertia + React 19 / TypeScript + Tailwind/shadcn ; PostgreSQL 17 ; Redis + Horizon ; Gotenberg (PDF) ; Caddy ; Docker Compose. Tests : Pest, Playwright, bats. Application dans `tms/`, CI à la racine (`.github/workflows/`).

Code métier par module : `tms/app/Modules/<Module>/` (`Core/…`, `Connectors/…`, puis `Crm`, `Sessions`, `Finance`, `Qualite`…). Un module ne lit pas les tables d'un autre : services publics ou évènements.

## Commandes (depuis `tms/`)

Moteur Docker local : Colima (`colima start` si `docker info` échoue).

```bash
docker compose up -d                                          # démarrer la stack
docker compose exec app php artisan test                      # tests (rôle tms_app, base tms_test)
docker compose exec app php artisan test --filter=Nom         # test ciblé
docker compose exec app php artisan test --group=slow          # tests de volume (exclus par défaut, lancés à part par la CI)
docker compose exec app php artisan migrate --database=pgsql_owner   # migrations : TOUJOURS via pgsql_owner
docker compose exec app vendor/bin/pint                       # formatage PHP
docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G   # analyse statique
docker compose run --rm vite sh -c "npm run lint && npm run types && npm run build"
npm ci && npm run e2e                                          # Playwright (hôte, pile dédiée port 8081, node_modules de l'hôte) : voir tms/docs/install.md
shellcheck -x ops/*.sh ops/install/*.sh ops/release/*.sh && bats ops/tests   # scripts d'exploitation
```

Scripts d'exploitation (installation, mise à jour, sauvegarde, publication) : `tms/ops/` (tests bats : `tms/ops/tests/`) ; publication : `.github/workflows/release.yml`.

Installation dev complète : `tms/docs/install.md`. App : http://localhost:8080, santé : `/health`.

## Règles à ne jamais enfreindre

1. **Isolation multi-organismes** : toute table métier a `tenant_id uuid not null`, le trait `BelongsToTenant` et une politique RLS (`Rls::enable`). Le test d'architecture échoue sinon. L'application se connecte en `tms_app` (non propriétaire, `NOBYPASSRLS`) ; seules les migrations utilisent `tms_owner`. Les jobs en file portent le tenant (`TenantAwareJob`).
2. **Aucun secret** ni mot de passe par défaut dans le dépôt ; `.env` est généré (installateur) et ignoré par git.
3. **Français** pour toute l'interface et les messages utilisateur ; formats français (dates, montants, CSV `;`).
4. **Sécurité** : cible OWASP ASVS niveau 2 ; mots de passe Argon2id ≥ 12 caractères ; 2FA obligatoire pour admin et direction ; journal d'audit append-only ; secrets des connecteurs chiffrés.
5. **Connecteurs** : tout logiciel externe (Formevol, Sage, SoWeSign, Outlook…) passe par un adaptateur isolé dans `app/Modules/Connectors`, jamais codé en dur dans le métier.
6. **TDD** : test d'abord, puis code ; tests verts avant chaque commit.
7. Listes métier (DAS, étapes, origines…) = **référentiels paramétrables par organisme**, jamais des constantes en dur. Un module stocke l'**id** de la valeur (clé étrangère vers `reference_items`), jamais son code, et déclare la colonne avec `ReferenceUsage::register(...)` (une valeur utilisée ne se supprime pas).
8. **Cache** : toute clé contenant des données d'un organisme est préfixée par l'id du tenant.
9. **Files d'attente** : jobs et notifications reçoivent le tenant automatiquement par leur charge utile ; ne jamais appeler `TenantContext::set` de façon globale dans un worker.
10. Tout nouveau modèle sur une table métier utilise `BelongsToTenant`.

## Module Crm (lots 1b et 1c) : points d'architecture à connaître

Code dans `tms/app/Modules/Crm/` (Organisations, People, Affiliations, Opportunities, Activities, Dashboard, Timeline, Duplicates, Search, Export, Import, Gdpr, Visibility, Support). Guide utilisateur : `tms/docs/crm.md` ; tables : `tms/docs/data-dictionary.md`.

- **Historique** : chaque source est un `TimelineProvider` taggé `Timeline::TAG` dans `CrmServiceProvider::register()` (notes, pièces jointes, audit, étapes d'opportunités, actions). Un futur module (devis…) ajoute son fournisseur au tag, sans toucher au Crm. Pagination par **curseur composite opaque** (`TimelineCursor` : instant µs, type, id) : un fournisseur filtre avec `Timeline::after($query, $before, 'type', 'colonne_date')` ; un curseur mal formé renvoie la première page.
- **Données liées** : `Support\LinkedData::register('module.cle', fn ($fiche, $exceptIds) => bool)` déclare ce qui rattache une fiche (rattachements, notes, opportunités, actions…) ; l'annulation d'un import la conserve si `LinkedData::any()` est vrai. Même principe pour le dernier contact : `Timeline\LastContact::registerSource()` (les actions faites y contribuent) et `LastContact::recompute()` après suppression.
- **Opportunités** (`Opportunities/`) : compte = entreprise **ou** particulier (CHECK, une personne non particulier est refusée) ; tout changement d'étape passe par `OpportunityStage::move()` (motif obligatoire pour perdu, gagné/perdu forcent 100/0, historique `opportunity_stage_changes`, `probability_overridden` gardé un seul déplacement) ; issue d'une étape = `meta.outcome` (`won`/`lost`) du référentiel, jamais un code. `OpportunityListQuery::build()` est l'unique requête (liste, kanban, export, accueil) : visibilité DAS et fiches en corbeille exclues.
- **Actions** (`Activities/`) : visibilité (`CrmVisibility::activities`) = celle de l'opportunité si `opportunity_id`, sinon celle d'une fiche liée. `TodoQuery` (jours de Paris, badge en cache 60 s préfixé tenant, `onLiveFiches()`), `WeekPlanning::types()` lit le drapeau `meta.planning` (`meeting`/`call`) des types d'action — jamais de code de type en dur côté serveur. Création/complétion via `ActivityRecorder` (relance suivante incluse) ; `QuickReport` fait tout dans une transaction. L'évènement `Activities\Events\ActivityChanged` (après commit) est la seule porte vers Outlook ; le module Connectors lit les actions par `CalendarEvents` (service public, sans compte rendu).
- **Accueil** : `Dashboard\CommercialSummary` (relances, pipeline pondéré du mois de Paris, prochains RDV) réutilise `TodoQuery`, `OpportunityListQuery` et `WeekPlanning` ; rien sans `crm.view`.
- **Tenancy hors requête** : `ForEachTenant::run()` pour tout traitement planifié ou de maintenance (une erreur sur un organisme n'arrête pas les autres) ; `TenantStorage` pour tout chemin de fichier (`tenants/<tenant_id>/<zone>/`, disque privé) ; jamais de chemin construit à la main. `PermissionSync` (`tms:permissions:sync`, lancé par `ops/migrate.sh`) accorde **une fois** les nouveaux droits par défaut de chaque rôle ; un module déclare ses permissions via `Core\Access\Permissions::registerModule()` dans `register()` (avant `AccessServiceProvider::boot`).
- **Types polymorphes** : `MorphAliases::register()` par module, `enforceMorphMap` actif : un modèle sans alias lève une exception ; on stocke l'alias (`organisation`, `person`…), jamais la classe.
- **RLS et fonctions non « leakproof »** : sous FORCE RLS, un index d'expression (`lower()`, `unaccent()`, `regexp_replace()`, `@>` jsonb) n'est pas utilisé. Les clés de recherche et de doublons sont donc des **colonnes générées stockées** (`search_vector`, `dup_*`) comparées par `=` ; `import_stamps` remplace une recherche jsonb. Ne pas réintroduire d'index d'expression sur une table RLS sans vérifier le plan (`EXPLAIN`) avec le rôle `tms_app`.
- **Fuseau** : le fuseau PostgreSQL de session suit `APP_TIMEZONE` (`Europe/Paris`, `config/database.php`) : dates, `now()` SQL et PHP concordent. Ne jamais comparer des horodatages sans fuseau.
- **Connecteurs sortants** : toute adresse appelée (SMTP, Sirene…) passe par `Connectors\Core\HostPolicy` (adresses internes/privées refusées, vérifié à l'enregistrement et à l'appel).
- **Import** : `Import/` lit CSV/XLSX en continu, simule puis exécute par tranches reprenables (files `imports` et `default`, `TenantAwareJob`). `ImportStamps` (table `import_stamps`) enregistre ce que chaque import a fait à chaque fiche : c'est ce qui permet d'annuler sans écraser le travail des utilisateurs. Un import Formevol = un enregistrement `import_profiles`, pas de code.
- **Erreurs** : `abort()` avec un message affiché à l'utilisateur lève `App\Exceptions\UserFacingHttpException` (message en français) ; toute autre `HttpException` n'affiche que le texte fixe de son code.
- **Données personnelles** : aucune valeur de champ personnel en clair dans `audit_logs` (`Auditable::$auditPersonal`, valeur `[donnée personnelle]`) ni dans les logs ; l'anonymisation (`Gdpr/Anonymiser`) purge aussi les lignes d'import et les pièces jointes.
- **Visibilité** : toute requête sur une fiche passe par `CrmVisibility` (le Responsable DAS ne voit que ses DAS suivis) ; les `Route::bind` du `CrmServiceProvider` renvoient 404 pour une fiche hors périmètre.

## Modules Catalogue et Devis (lot 1d) : points d'architecture à connaître

Guide utilisateur : `tms/docs/crm.md` (§ 4 catalogue, § 5 devis) ; réglages : `tms/docs/admin.md` (§ 3 sociétés, § 14 devis).

- **Catalogue** (`tms/app/Modules/Catalogue/`, tables `trainings`, `training_funding_modes`, permissions `catalogue.view` / `catalogue.manage`) : module à part. Le Crm n'en utilise que `Trainings` (service public : `find`, `options`, `search`, import), `TrainingData` (tarifs en centimes entiers) et `TrainingUsage`, et ne nomme jamais ses tables (`ModuleBoundariesArchTest`, motif `CATALOGUE_TABLES` : pas de clé `'trainings'` nue dans le Crm, d'où `training_lines`, `options.training`, `training_titles`). Catalogue ne dépend pas du Crm (exports partagés dans `Core\Exports`).
- **TrainingUsage** : registre de garde-fous de suppression. Tout module qui référence une formation s'y déclare (`TrainingUsage::register('nom', fn ($id) => bool)`, comme `opportunities` et `quotes` dans `CrmServiceProvider`) ; la suppression d'une formation et l'annulation d'un import le consultent.
- **Devis** (`tms/app/Modules/Crm/Quotes/`) : `QuoteTotals` calcule tout en centimes entiers avec `brick/math` (jamais de float ; TVA par taux sur la somme des lignes, arrondi demi supérieur) ; `QuoteWriter` crée et modifie un brouillon dans une transaction ; `QuoteNumbering::next()` exige une transaction et incrémente `quote_sequences` clé (`tenant_id`, `prefix`, année de Paris) ; `quote_prefix` unique par organisme. Un devis non brouillon ne se modifie plus (409).
- **Transitions** : `QuoteTransitions` (verrou `lockForUpdate` sur chaque transition) : envoi = `QuoteFreezer::freeze` (destinataire figé, PDF archivé, régime de TVA figé) puis `SendQuote` (`TenantAwareJob`, `afterCommit`, `TenantMailer`, `email_status`) ; l'opportunité avance vers l'étape `meta.quote_stage` (`OpportunityStage::quoteStage()` / `advanceTo()`, jamais un code d'étape en dur) ; « Gagné » reprend le montant du devis accepté lu côté serveur (`quote_id` sur l'endpoint d'étape). Expiration : `tms:crm:expire-quotes` (0 h 05 Paris, `ForEachTenant`).
- **PDF** : `Core\Documents\PdfRenderer` (Gotenberg, `config/documents.php`, `GOTENBERG_URL`) est le seul moteur ; `QuotePdf` + `resources/views/documents/quote*.blade.php` (CSP sans ressource externe, logo en data URI, `{{ }}` seulement). Un devis envoyé sert toujours son archive (`TenantStorage` zone `quotes`) ; sans archive : 410, jamais de nouveau rendu. Échec Gotenberg = `PdfRenderingFailed` (503, message français).
- **Données personnelles** : `recipient_snapshot`, `notes`, `refusal_reason` dans `$auditPersonal` ; `email_*` hors audit ; échecs SMTP sans texte du serveur (`QuoteMailFailed`) ; `QuoteAnonymisation` (appelé par l'Anonymiser) efface aussi les PDF archivés.
- **Taux de réussite** : `QuoteSuccessRate` passe par `QuoteListQuery` (même source que la liste et l'export : filtres et visibilité DAS).

## Conventions

- Identifiants UUID v7 ; suppression logique sur les fiches métier.
- Commits en anglais, style conventionnel (`feat:`, `fix:`, `chore:`, `test:`), petits et fréquents.
- Branche de travail par incrément (ex. `lot1a-socle`) ; ne jamais committer directement sur `main`.
