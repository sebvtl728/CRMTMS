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
docker compose exec app php artisan migrate --database=pgsql_owner   # migrations : TOUJOURS via pgsql_owner
docker compose exec app vendor/bin/pint                       # formatage PHP
docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G   # analyse statique
docker compose run --rm vite sh -c "npm run lint && npm run types && npm run build"
npm run e2e                                                    # Playwright (hôte, pile dédiée port 8081) : voir tms/docs/install.md
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

## Module Crm (lot 1b) : points d'architecture à connaître

Code dans `tms/app/Modules/Crm/` (Organisations, People, Affiliations, Timeline, Duplicates, Search, Export, Import, Gdpr, Visibility). Guide utilisateur : `tms/docs/crm.md` ; tables : `tms/docs/data-dictionary.md`.

- **Historique** : chaque source est un `TimelineProvider` taggé `Timeline::TAG` dans `CrmServiceProvider::register()` (notes, pièces jointes, audit). Un futur module (actions, devis…) ajoute son fournisseur au tag, sans toucher au Crm.
- **Tenancy hors requête** : `ForEachTenant::run()` pour tout traitement planifié ou de maintenance (une erreur sur un organisme n'arrête pas les autres) ; `TenantStorage` pour tout chemin de fichier (`tenants/<tenant_id>/<zone>/`, disque privé) ; jamais de chemin construit à la main. `PermissionSync` (`tms:permissions:sync`, lancé par `ops/migrate.sh`) accorde **une fois** les nouveaux droits par défaut de chaque rôle ; un module déclare ses permissions via `Core\Access\Permissions::registerModule()` dans `register()` (avant `AccessServiceProvider::boot`).
- **Types polymorphes** : `MorphAliases::register()` par module, `enforceMorphMap` actif : un modèle sans alias lève une exception ; on stocke l'alias (`organisation`, `person`…), jamais la classe.
- **RLS et fonctions non « leakproof »** : sous FORCE RLS, un index d'expression (`lower()`, `unaccent()`, `regexp_replace()`, `@>` jsonb) n'est pas utilisé. Les clés de recherche et de doublons sont donc des **colonnes générées stockées** (`search_vector`, `dup_*`) comparées par `=` ; `import_stamps` remplace une recherche jsonb. Ne pas réintroduire d'index d'expression sur une table RLS sans vérifier le plan (`EXPLAIN`) avec le rôle `tms_app`.
- **Fuseau** : le fuseau PostgreSQL de session suit `APP_TIMEZONE` (`Europe/Paris`, `config/database.php`) : dates, `now()` SQL et PHP concordent. Ne jamais comparer des horodatages sans fuseau.
- **Connecteurs sortants** : toute adresse appelée (SMTP, Sirene…) passe par `Connectors\Core\HostPolicy` (adresses internes/privées refusées, vérifié à l'enregistrement et à l'appel).
- **Import** : `Import/` lit CSV/XLSX en continu, simule puis exécute par tranches reprenables (files `imports` et `default`, `TenantAwareJob`). `ImportStamps` (table `import_stamps`) enregistre ce que chaque import a fait à chaque fiche : c'est ce qui permet d'annuler sans écraser le travail des utilisateurs. Un import Formevol = un enregistrement `import_profiles`, pas de code.
- **Erreurs** : `abort()` avec un message affiché à l'utilisateur lève `App\Exceptions\UserFacingHttpException` (message en français) ; toute autre `HttpException` n'affiche que le texte fixe de son code.
- **Données personnelles** : aucune valeur de champ personnel en clair dans `audit_logs` (`Auditable::$auditPersonal`, valeur `[donnée personnelle]`) ni dans les logs ; l'anonymisation (`Gdpr/Anonymiser`) purge aussi les lignes d'import et les pièces jointes.
- **Visibilité** : toute requête sur une fiche passe par `CrmVisibility` (le Responsable DAS ne voit que ses DAS suivis) ; les `Route::bind` du `CrmServiceProvider` renvoient 404 pour une fiche hors périmètre.

## Conventions

- Identifiants UUID v7 ; suppression logique sur les fiches métier.
- Commits en anglais, style conventionnel (`feat:`, `fix:`, `chore:`, `test:`), petits et fréquents.
- Branche de travail par incrément (ex. `lot1a-socle`) ; ne jamais committer directement sur `main`.
