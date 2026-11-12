# Helpers for the integration tests: bring up a throwaway production-like stack
# (compose.prod.yaml, locally built image, generated secrets) and tear it down.
#
# Requires Docker. Environment knobs:
#   TMS_TEST_SKIP_BUILD=1  reuse the existing image tms-ci:bats
#   STACK_INSTALL=1        run the stack from a copy of the release bundle in
#                          $STACK_DIR/tms (like /opt/tms), not from the source tree
#   TMS_TEST_TMPDIR        base directory for the stack files (must be bind-mountable
#                          by Docker; default ~/.cache/tms-bats)

TMS_ROOT="$(cd "$BATS_TEST_DIRNAME/../.." && pwd)"
export TMS_ROOT

# shellcheck disable=SC1091
source "$TMS_ROOT/ops/lib.sh"
# bats relies on errexit; nounset from lib.sh would break its internals.
set +u

stack_up() {
    local base="${TMS_TEST_TMPDIR:-${XDG_CACHE_HOME:-$HOME/.cache}/tms-bats}"
    mkdir -p "$base"
    STACK_DIR="$(mktemp -d "$base/stack.XXXXXX")"
    export STACK_DIR

    export COMPOSE_PROJECT_NAME="tmsbats"
    if [[ -n "${STACK_INSTALL:-}" ]]; then
        export TMS_DIR="$STACK_DIR/tms"
        copy_bundle "$TMS_DIR"
        export TMS_ENV_FILE="$TMS_DIR/.env"
    else
        export TMS_DIR="$TMS_ROOT"
        export TMS_ENV_FILE="$STACK_DIR/env"
    fi
    export TMS_COMPOSE_FILE="$TMS_DIR/compose.prod.yaml"
    export TMS_LOCK_FILE="$STACK_DIR/ops.lock"
    export TMS_BACKUP_TMPDIR="$STACK_DIR/staging"
    mkdir -p "$TMS_BACKUP_TMPDIR"

    # Throwaway secrets, generated for this run only.
    cat >"$TMS_ENV_FILE" <<ENV
TMS_IMAGE=tms-ci
TMS_VERSION=bats
TMS_DOMAIN=localhost
TMS_ACME_EMAIL=ci@example.test
TMS_HTTP_PORT=18080
TMS_HTTPS_PORT=18443
APP_KEY=base64:$(openssl rand -base64 32)
POSTGRES_SUPERUSER_PASSWORD=$(gen_secret 24)
POSTGRES_OWNER_PASSWORD=$(gen_secret 24)
POSTGRES_APP_PASSWORD=$(gen_secret 24)
REDIS_PASSWORD=$(gen_secret 24)
MAIL_MAILER=log
RESTIC_PASSWORD=$(gen_secret 24)
RESTIC_REPOSITORY=$STACK_DIR/restic
TMS_BACKUP_TMPDIR=$STACK_DIR/staging
ENV
    chmod 600 "$TMS_ENV_FILE"

    if [[ -z "${TMS_TEST_SKIP_BUILD:-}" ]]; then
        docker build -f "$TMS_ROOT/docker/app.Dockerfile" --target prod \
            --build-arg APP_VERSION=bats -t tms-ci:bats "$TMS_ROOT" >&2 || return 1
    fi

    compose up -d --wait >&2 || return 1
    "$TMS_ROOT/ops/migrate.sh" >&2 || return 1
    compose exec -T app php artisan db:seed --force >&2 || return 1
    wait_health 120
}

# copy_bundle <dir>: the files of a release bundle (see docs/install.md), from the source tree.
copy_bundle() {
    local dest="$1"
    mkdir -p "$dest/ops" "$dest/docker/caddy" "$dest/docker/postgres" "$dest/docs"
    cp "$TMS_ROOT/compose.prod.yaml" "$TMS_ROOT/.env.prod.example" "$dest/"
    cp "$TMS_ROOT"/ops/*.sh "$dest/ops/"
    cp "$TMS_ROOT/docker/caddy/Caddyfile" "$dest/docker/caddy/"
    cp "$TMS_ROOT/docker/postgres/init.sh" "$TMS_ROOT/docker/postgres/init.sql" "$dest/docker/postgres/"
    cp "$TMS_ROOT/docs/install.md" "$dest/docs/"
}

stack_down() {
    [[ -n "${STACK_DIR:-}" ]] || return 0
    compose down -v --remove-orphans >&2 || true
    # The restic repository and staging files may belong to root-owned containers.
    docker run --rm -v "$STACK_DIR:/stack" alpine sh -c 'rm -rf /stack/* /stack/.[!.]*' >&2 || true
    rmdir "$STACK_DIR" 2>/dev/null || true
}

# psql_owner <sql>: runs SQL as tms_owner inside the tenant of the seeded organisme
# (tables are under FORCE ROW LEVEL SECURITY: without a tenant even the owner sees nothing).
psql_owner() {
    # shellcheck disable=SC2016  # expanded inside the container
    printf "select set_config('app.tenant_id', (select id::text from tenants limit 1), false);\n%s\n" "$1" \
        | compose exec -T postgres sh -c 'PGPASSWORD="$POSTGRES_OWNER_PASSWORD" psql -h 127.0.0.1 -U tms_owner -d tms -tA -v ON_ERROR_STOP=1' \
        | tail -n 1
}
