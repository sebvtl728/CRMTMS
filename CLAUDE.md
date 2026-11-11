# TMS sur mesure — guide pour Claude

TMS (Training Management System) multi-organismes pour organismes de formation, inspiré de Formdev. Premier client : CAP FORMATION (SCOP). Produit destiné à être vendu à d'autres organismes.

## Documents de référence

- Spécifications sources : `Cdc/Cartographie projet – TMS sur mesure.md` (périmètre TMS complet) et `Cdc/cdc-activité-commercial.md` (gestion commerciale détaillée).
- Conception validée : `docs/superpowers/specs/2026-11-11-tms-design.md` — **autorité** en cas de doute.
- Plans d'implémentation : `docs/superpowers/plans/` (un plan par incrément : 1a socle, 1b fiches/import, 1c pipeline, 1d devis, 1e pilotage).

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
docker compose exec app vendor/bin/phpstan analyse            # analyse statique
docker compose run --rm vite sh -c "npm run lint && npm run types && npm run build"
```

Installation dev complète : `tms/docs/install.md`. App : http://localhost:8080, santé : `/health`.

## Règles à ne jamais enfreindre

1. **Isolation multi-organismes** : toute table métier a `tenant_id uuid not null`, le trait `BelongsToTenant` et une politique RLS (`Rls::enable`). Le test d'architecture échoue sinon. L'application se connecte en `tms_app` (non propriétaire, `NOBYPASSRLS`) ; seules les migrations utilisent `tms_owner`. Les jobs en file portent le tenant (`TenantAwareJob`).
2. **Aucun secret** ni mot de passe par défaut dans le dépôt ; `.env` est généré (installateur) et ignoré par git.
3. **Français** pour toute l'interface et les messages utilisateur ; formats français (dates, montants, CSV `;`).
4. **Sécurité** : cible OWASP ASVS niveau 2 ; mots de passe Argon2id ≥ 12 caractères ; 2FA obligatoire pour admin et direction ; journal d'audit append-only ; secrets des connecteurs chiffrés.
5. **Connecteurs** : tout logiciel externe (Formevol, Sage, SoWeSign, Outlook…) passe par un adaptateur isolé dans `app/Modules/Connectors`, jamais codé en dur dans le métier.
6. **TDD** : test d'abord, puis code ; tests verts avant chaque commit.
7. Listes métier (DAS, étapes, origines…) = **référentiels paramétrables par organisme**, jamais des constantes en dur.

## Conventions

- Identifiants UUID v7 ; suppression logique sur les fiches métier.
- Commits en anglais, style conventionnel (`feat:`, `fix:`, `chore:`, `test:`), petits et fréquents.
- Branche de travail par incrément (ex. `lot1a-socle`) ; ne jamais committer directement sur `main`.
