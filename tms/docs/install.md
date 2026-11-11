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
