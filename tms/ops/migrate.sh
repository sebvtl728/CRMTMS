#!/usr/bin/env bash
# Runs the database migrations with the owner role in a one-off container.
# The owner credentials are NOT part of the long-running app/worker/scheduler
# environment (tms_owner can disable row level security).
#
#   ops/migrate.sh [extra artisan options]
set -euo pipefail

# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

load_env
require_var POSTGRES_OWNER_PASSWORD

compose up -d --wait postgres redis >&2
DB_OWNER_USERNAME=tms_owner DB_OWNER_PASSWORD="$POSTGRES_OWNER_PASSWORD" \
    compose run --rm --no-deps -T -e DB_OWNER_USERNAME -e DB_OWNER_PASSWORD \
    app php artisan migrate --database=pgsql_owner --force "$@"
