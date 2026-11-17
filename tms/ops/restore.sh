#!/usr/bin/env bash
# Restore a restic snapshot: database (schema public dropped and recreated, then the dump
# replayed as the owner role, all in one transaction) and storage/app. Stops
# app/worker/scheduler during the restore, restarts them and waits for /health.
# Takes the maintenance lock (shared with update.sh and backup.sh).
#
#   ops/restore.sh [--with-app-key] <snapshot-id|latest>
#
# The snapshot carries the APP_KEY of the instance it came from (meta.env). If it differs
# from the current .env the restore is refused (connector secrets and 2FA enrolments could
# not be decrypted) unless --with-app-key is given: once the database is restored, the
# snapshot key is written into .env (the previous file is kept as .env.bak-<timestamp>, mode 600).
#
# Another repository than the one of .env (RESTIC_REPOSITORY / RESTIC_PASSWORD) can be given by
# the caller through the environment, never on the command line: TMS_RESTORE_REPOSITORY and
# TMS_RESTORE_PASSWORD (ops/import.sh restores the repository of an export file this way).
set -euo pipefail

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
ADOPT_KEY=0

tms_lock 0

on_exit() {
    local status=$?
    [[ -z "$STAGE" ]] || rm -rf "$STAGE"
    if [[ "$status" -ne 0 && "$STOPPED" -eq 1 ]]; then
        log_error "Restauration interrompue : les services app, worker et scheduler restent arrêtés (base potentiellement inchangée grâce à la transaction unique). Corrigez puis relancez."
    fi
}
trap on_exit EXIT

load_env
if [[ -n "${TMS_RESTORE_REPOSITORY:-}" ]]; then
    # Repository chosen by the caller (ops/import.sh: the repository of an export file).
    [[ -n "${TMS_RESTORE_PASSWORD:-}" ]] || die "Mot de passe du dépôt ${TMS_RESTORE_REPOSITORY} manquant (TMS_RESTORE_PASSWORD)."
    export RESTIC_REPOSITORY="$TMS_RESTORE_REPOSITORY" RESTIC_PASSWORD="$TMS_RESTORE_PASSWORD"
else
    require_var RESTIC_PASSWORD
    export RESTIC_PASSWORD
    export RESTIC_REPOSITORY="${RESTIC_REPOSITORY:-/var/backups/tms}"
fi
unset TMS_RESTORE_PASSWORD

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
    ADOPT_KEY=1
fi

log_info "Arrêt de app, worker et scheduler."
STOPPED=1
compose stop app worker scheduler >&2
compose up -d --wait postgres redis >&2

log_info "Restauration de la base de données."
# Clean schema, not pg_restore --clean: objects created after the snapshot (and their foreign
# keys) must disappear too. The SQL script is generated first, then applied in ONE transaction,
# so a failure leaves the database untouched.
# Grants and default privileges come from the dump only (never widened here).
PREFIX_SQL="DROP SCHEMA public CASCADE;
CREATE SCHEMA public AUTHORIZATION pg_database_owner;"
# shellcheck disable=SC2016  # expanded by the shell inside the container
compose exec -T -e PREFIX_SQL="$PREFIX_SQL" postgres bash -c '
    set -euo pipefail
    script="$(mktemp)"
    trap "rm -f $script" EXIT
    { printf "%s\n" "$PREFIX_SQL"; pg_restore --no-owner -f -; } >"$script"
    PGPASSWORD="$POSTGRES_OWNER_PASSWORD" psql -h 127.0.0.1 -U tms_owner -d tms -q \
        --single-transaction -v ON_ERROR_STOP=1 -f "$script" >/dev/null' \
    <"$DUMP" >&2 || die "Échec de la restauration de la base (base inchangée)."

# Only now that the database matches the snapshot: adopt its APP_KEY.
if [[ "$ADOPT_KEY" -eq 1 ]]; then
    log_info "Adoption de la clé APP_KEY de la sauvegarde."
    bak="${TMS_ENV_FILE}.bak-$(date '+%Y%m%d-%H%M%S')"
    (umask 077 && cp "$TMS_ENV_FILE" "$bak") || die "Copie de sécurité de ${TMS_ENV_FILE} impossible."
    chmod 600 "$bak"
    tmp_env="$(umask 077 && mktemp "${TMS_ENV_FILE}.XXXXXX")"
    { grep -v '^APP_KEY=' "$TMS_ENV_FILE" || true; printf 'APP_KEY=%s\n' "$SNAP_KEY"; } >"$tmp_env"
    chmod 600 "$tmp_env"
    mv "$tmp_env" "$TMS_ENV_FILE"
    export APP_KEY="$SNAP_KEY"
    log_info "Ancien fichier conservé : ${bak}."
fi

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
