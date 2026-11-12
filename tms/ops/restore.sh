#!/usr/bin/env bash
# Restore a restic snapshot: database (pg_restore --clean --if-exists, as the owner
# role, in one transaction) and storage/app. Stops app/worker/scheduler during the
# restore, restarts them and waits for /health.
#
#   ops/restore.sh [--with-app-key] <snapshot-id|latest>
#
# The snapshot carries the APP_KEY of the instance it came from (meta.env). If it differs
# from the current .env the restore is refused (connector secrets and 2FA enrolments could
# not be decrypted) unless --with-app-key is given: the snapshot key is then written into .env.set -euo pipefail

# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

WITH_APP_KEY=0
if [[ "${1:-}" == "--with-app-key" ]]; then
    WITH_APP_KEY=1
    shift
fi
[[ $# -eq 1 && -n "$1" ]] || { echo "Usage : ops/restore.sh [--with-app-key] <snapshot-id|latest>" >&2; exit 2; }
SNAPSHOT="$1"
STAGE=""
STOPPED=0

on_exit() {
    local status=$?
    [[ -z "$STAGE" ]] || rm -rf "$STAGE"
    if [[ "$status" -ne 0 && "$STOPPED" -eq 1 ]]; then
        log_error "Restauration interrompue : les services app, worker et scheduler restent arrêtés (base potentiellement inchangée grâce à la transaction unique). Corrigez puis relancez."
    fi
}
trap on_exit EXIT

load_env
require_var RESTIC_PASSWORD
export RESTIC_PASSWORD
export RESTIC_REPOSITORY="${RESTIC_REPOSITORY:-/var/backups/tms}"

STAGE="$(mktemp -d "${TMS_BACKUP_TMPDIR:-/var/tmp}/tms-restore.XXXXXX")"

host_args=()
[[ "$SNAPSHOT" != "latest" ]] || host_args=(--host tms)

log_info "Extraction du snapshot ${SNAPSHOT}."
restic_run -v "$STAGE:/restore" -- restore "$SNAPSHOT" ${host_args[@]+"${host_args[@]}"} --target /restore >/dev/null \
    || die "Snapshot introuvable ou illisible : ${SNAPSHOT}."

DUMP="$(find "$STAGE" -name db.dump -type f | head -n 1)"
STORAGE_TAR="$(find "$STAGE" -name storage-app.tar -type f | head -n 1)"
[[ -n "$DUMP" && -n "$STORAGE_TAR" ]] || die "Le snapshot ${SNAPSHOT} ne contient pas db.dump et storage-app.tar."

META="$(find "$STAGE" -name meta.env -type f | head -n 1)"
SNAP_KEY=""
[[ -z "$META" ]] || SNAP_KEY="$(sed -n 's/^APP_KEY=//p' "$META" | head -n 1)"
if [[ -n "$SNAP_KEY" && "$SNAP_KEY" != "${APP_KEY:-}" ]]; then
    if [[ "$WITH_APP_KEY" -ne 1 ]]; then
        die "La clé APP_KEY de cette sauvegarde diffère de celle de ce serveur. Sans la clé d'origine, les secrets des connecteurs et les doubles authentifications ne peuvent plus être déchiffrés. Relancez avec --with-app-key pour adopter la clé de la sauvegarde (elle sera écrite dans ${TMS_ENV_FILE})."
    fi
    log_info "Adoption de la clé APP_KEY de la sauvegarde."
    tmp_env="$(mktemp "${TMS_ENV_FILE}.XXXXXX")"
    chmod 600 "$tmp_env"
    { grep -v '^APP_KEY=' "$TMS_ENV_FILE" || true; printf 'APP_KEY=%s\n' "$SNAP_KEY"; } >"$tmp_env"
    mv "$tmp_env" "$TMS_ENV_FILE"
    chmod 600 "$TMS_ENV_FILE"
    export APP_KEY="$SNAP_KEY"
fi

log_info "Arrêt de app, worker et scheduler."
STOPPED=1
compose stop app worker scheduler >&2
compose up -d --wait postgres redis >&2

log_info "Restauration de la base de données."
# Clean schema, not pg_restore --clean: objects created after the snapshot (and their foreign
# keys) must disappear too. The SQL script is generated first, then applied in ONE transaction,
# so a failure leaves the database untouched.
PREFIX_SQL="DROP SCHEMA public CASCADE;
CREATE SCHEMA public AUTHORIZATION tms_owner;
GRANT USAGE ON SCHEMA public TO tms_app;
ALTER DEFAULT PRIVILEGES FOR ROLE tms_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO tms_app;
ALTER DEFAULT PRIVILEGES FOR ROLE tms_owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO tms_app;"
# shellcheck disable=SC2016  # expanded by the shell inside the container
compose exec -T -e PREFIX_SQL="$PREFIX_SQL" postgres bash -c '
    set -euo pipefail
    script="$(mktemp)"
    trap "rm -f $script" EXIT
    { printf "%s\n" "$PREFIX_SQL"; pg_restore --no-owner -f -; } >"$script"
    PGPASSWORD="$POSTGRES_OWNER_PASSWORD" psql -h 127.0.0.1 -U tms_owner -d tms -q \
        --single-transaction -v ON_ERROR_STOP=1 -f "$script" >/dev/null' \
    <"$DUMP" >&2 || die "Échec de la restauration de la base (base inchangée)."

log_info "Restauration de storage/app."
# Extract beside, then swap: the current files survive an extraction failure.
compose run --rm --no-deps -T app sh -c '
    set -e
    rm -rf storage/.restore-tmp storage/app.old
    mkdir storage/.restore-tmp
    tar -xf - -C storage/.restore-tmp
    mv storage/app storage/app.old
    mv storage/.restore-tmp/app storage/app
    rm -rf storage/app.old storage/.restore-tmp' \
    <"$STORAGE_TAR" >&2 || die "Échec de la restauration de storage/app."

log_info "Redémarrage des services."
compose up -d --wait >&2
STOPPED=0
compose exec -T app php artisan cache:clear >/dev/null 2>&1 || log_info "Cache applicatif non vidé (non bloquant)."
wait_health 120

log_info "Restauration terminée."
