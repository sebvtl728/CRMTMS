#!/usr/bin/env bash
# Creates the application roles and databases.
# Passwords come from the environment, never from the repository:
#   POSTGRES_OWNER_PASSWORD -> role tms_owner (owns the schema, runs migrations)
#   POSTGRES_APP_PASSWORD   -> role tms_app (runtime role, NOBYPASSRLS, not owner)
set -euo pipefail

: "${POSTGRES_OWNER_PASSWORD:?POSTGRES_OWNER_PASSWORD is required}"
: "${POSTGRES_APP_PASSWORD:?POSTGRES_APP_PASSWORD is required}"

SQL_FILE="${INIT_SQL:-$(dirname "${BASH_SOURCE[0]}")/init.sql}"

psql -v ON_ERROR_STOP=1 \
    --username "${POSTGRES_USER:-postgres}" --dbname postgres \
    -v owner_password="${POSTGRES_OWNER_PASSWORD}" \
    -v app_password="${POSTGRES_APP_PASSWORD}" \
    -f "${SQL_FILE}"
