#!/usr/bin/env bash
# Restore a restic snapshot: database (pg_restore --clean --if-exists, as the owner
# role, in one transaction) and storage/app. Stops app/worker/scheduler during the
# restore, restarts them and waits for /health.
#
#   ops/restore.sh <snapshot-id|latest>
set -euo pipefail

# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

[[ $# -eq 1 && -n "$1" ]] || { echo "Usage : ops/restore.sh <snapshot-id|latest>" >&2; exit 2; }
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

log_info "Arrêt de app, worker et scheduler."
STOPPED=1
compose stop app worker scheduler >&2
compose up -d --wait postgres redis >&2

log_info "Restauration de la base de données."
# shellcheck disable=SC2016  # expanded by the shell inside the container
compose exec -T postgres sh -c \
    'PGPASSWORD="$POSTGRES_OWNER_PASSWORD" exec pg_restore -h 127.0.0.1 -U tms_owner -d tms --clean --if-exists --no-owner --single-transaction --exit-on-error' \
    <"$DUMP" >&2 || die "Échec de pg_restore."

log_info "Restauration de storage/app."
compose run --rm --no-deps -T app sh -c 'find storage/app -mindepth 1 -delete && tar -xf - -C storage' \
    <"$STORAGE_TAR" >&2 || die "Échec de la restauration de storage/app."

log_info "Redémarrage des services."
compose up -d --wait >&2
STOPPED=0
compose exec -T app php artisan cache:clear >/dev/null 2>&1 || log_info "Cache applicatif non vidé (non bloquant)."
wait_health 120

log_info "Restauration terminée."
