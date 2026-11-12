#!/usr/bin/env bash
# Creates the application roles and databases.
# Passwords come from the environment, never from the repository:
#   POSTGRES_OWNER_PASSWORD -> role tms_owner (owns the schema, runs migrations)
#   POSTGRES_APP_PASSWORD   -> role tms_app (runtime role, NOBYPASSRLS, not owner)
set -euo pipefail

: "${POSTGRES_OWNER_PASSWORD:?POSTGRES_OWNER_PASSWORD is required}"
: "${POSTGRES_APP_PASSWORD:?POSTGRES_APP_PASSWORD is required}"

SQL_FILE="${INIT_SQL:-$(dirname "${BASH_SOURCE[0]}")/init.sql}"

# Production does not need the test database (TMS_NO_TEST_DB=1).
extra=()
[ -z "${TMS_NO_TEST_DB:-}" ] || extra=(-v no_test_db=1)

psql -v ON_ERROR_STOP=1 \
    "${extra[@]+"${extra[@]}"}" \
    --username "${POSTGRES_USER:-postgres}" --dbname postgres \
    -v owner_password="${POSTGRES_OWNER_PASSWORD}" \
    -v app_password="${POSTGRES_APP_PASSWORD}" \
    -f "${SQL_FILE}"
