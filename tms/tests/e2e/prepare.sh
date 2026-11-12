#!/usr/bin/env bash
# Resets the end-to-end database and prints the administrator's activation URL
# on the last line of stdout. Run from tms/. Never touches the dev database.
set -euo pipefail

COMPOSE=(docker compose -f compose.yaml -f compose.e2e.yaml)
ADMIN_EMAIL="${E2E_ADMIN_EMAIL:-admin@e2e.test}"

"${COMPOSE[@]}" up -d --wait postgres redis app-e2e caddy-e2e >&2

# Fresh database owned by tms_owner, same grants as docker/postgres/init.sql.
"${COMPOSE[@]}" exec -T postgres psql -U postgres -v ON_ERROR_STOP=1 >&2 <<'SQL'
SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'tms_e2e';
DROP DATABASE IF EXISTS tms_e2e;
CREATE DATABASE tms_e2e OWNER tms_owner;
\c tms_e2e
REVOKE ALL ON DATABASE tms_e2e FROM PUBLIC;
GRANT CONNECT ON DATABASE tms_e2e TO tms_app;
GRANT USAGE ON SCHEMA public TO tms_app;
ALTER DEFAULT PRIVILEGES FOR ROLE tms_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO tms_app;
ALTER DEFAULT PRIVILEGES FOR ROLE tms_owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO tms_app;
SQL

"${COMPOSE[@]}" exec -T app-e2e php artisan migrate --force --database=pgsql_owner >&2
"${COMPOSE[@]}" exec -T app-e2e php artisan tms:install \
    --tenant-name="Organisme E2E" --slug=e2e \
    --admin-name="Admin E2E" --admin-email="$ADMIN_EMAIL" | grep -Eo 'https?://[^ ]+/activation/[A-Za-z0-9]+' | tail -n 1
