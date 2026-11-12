# Installation (développement)

Prérequis : Docker avec Compose v2.

```bash
cd tms
cp .env.example .env
# Renseigner dans .env : DB_PASSWORD, DB_OWNER_PASSWORD, POSTGRES_SUPERUSER_PASSWORD,
# POSTGRES_APP_PASSWORD (= DB_PASSWORD), POSTGRES_OWNER_PASSWORD (= DB_OWNER_PASSWORD),
# HOST_UID / HOST_GID (id -u / id -g). Générer par exemple avec `openssl rand -hex 16`.
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose run --rm vite sh -c "npm ci && npm run build"
docker compose exec app php artisan migrate --database=pgsql_owner
```

Organisme par défaut (mode `TMS_MODE=single`, slug `TMS_DEFAULT_TENANT`) : `docker compose exec app php artisan db:seed`.
En mode `multi`, l'organisme est trouvé par le nom d'hôte (table `tenant_domains`).

Comptes : pas d'inscription publique ni de compte par défaut. Un utilisateur est créé par invitation
(`/admin/users`, lien d'activation à usage unique valable 24 h) ou par l'installateur (`tms:install`).
Un même e-mail peut exister dans plusieurs organismes ; chaque compte ne se connecte que sur l'hôte de son organisme.
Sessions : `SESSION_DRIVER=database` (révocation à la désactivation d'un compte) et `SESSION_DOMAIN` vide
(cookie limité à l'hôte, jamais partagé entre sous-domaines d'organismes).

Application : http://localhost:8080 — santé : http://localhost:8080/health

## Rôles PostgreSQL

| Rôle        | Usage                                            |
|-------------|--------------------------------------------------|
| `tms_owner` | propriétaire du schéma, migrations uniquement    |
| `tms_app`   | rôle applicatif (LOGIN, NOBYPASSRLS, non propriétaire) |

Les migrations se lancent toujours avec la connexion propriétaire :

```bash
docker compose exec app php artisan migrate --database=pgsql_owner
```

Les rôles et les bases `tms` / `tms_test` sont créés au premier démarrage du conteneur
`postgres` par `docker/postgres/init.sh` (mots de passe lus dans `POSTGRES_OWNER_PASSWORD`
et `POSTGRES_APP_PASSWORD`). Pour rejouer l'initialisation : `docker compose down -v`.

## Tests

```bash
docker compose exec app php artisan test
```

Les tests s'exécutent en tant que `tms_app` sur la base `tms_test` ; le schéma est recréé via
`pgsql_owner` (voir `tests/RefreshDatabaseAsOwner.php`). Les assets doivent avoir été
compilés (`npm run build`).

## Production (Docker Compose)

L'image de production est construite par la cible `prod` de `docker/app.Dockerfile`
(assets Node 22, dépendances PHP sans outils de développement, utilisateur non root,
`APP_VERSION` fixé à la construction). Les caches Laravel (config, routes, événements,
vues) sont générés au démarrage du conteneur par `docker/entrypoint.sh`.

```bash
docker build -f docker/app.Dockerfile --target prod --build-arg APP_VERSION=1.0.0 -t ghcr.io/<owner>/tms:1.0.0 .
cp .env.prod.example .env      # puis renseigner les variables (voir le fichier)
docker compose -f compose.prod.yaml --env-file .env up -d --wait
docker compose -f compose.prod.yaml --env-file .env exec app php artisan migrate --force --database=pgsql_owner
```

Seul Caddy publie des ports (80/443) ; PostgreSQL et Redis ne sont pas exposés. Caddy est la seule
adresse de confiance pour `X-Forwarded-*` (`TRUSTED_PROXIES`), ce qui active HTTPS/HSTS et l'IP cliente
correcte. `/health` est aussi servi en HTTP interne (conteneur `caddy`, port 8081, non publié).

### Sauvegardes et restauration

`ops/backup.sh [--tag <libellé>]` exporte la base (`pg_dump -Fc`) et `storage/app`, puis les enregistre dans un
dépôt **restic** chiffré (`RESTIC_REPOSITORY`, par défaut `/var/backups/tms`, copie distante optionnelle
`RESTIC_REMOTE_REPOSITORY`). Rétention : 14 jours, 8 semaines, 12 mois. La dernière ligne affichée est l'identifiant du
snapshot ; en cas d'échec un e-mail est envoyé à `TMS_ALERT_EMAIL` (`php artisan tms:alert`). Seul Docker est requis
sur l'hôte (restic s'exécute via l'image officielle `restic/restic`).

`ops/restore.sh <snapshot|latest>` arrête app/worker/scheduler, restaure la base (`pg_restore --clean --if-exists`, en
une transaction) et les fichiers, relance la pile et attend `/health`.

Tests d'intégration (pile jetable, secrets générés) : `bats ops/tests` depuis `tms/` (Docker requis).
