#!/usr/bin/env bash
# Backup: PostgreSQL dump (pg_dump -Fc as the owner role) + storage/app, stored in an
# encrypted restic repository (local, optionally copied to a remote one).
#
#   ops/backup.sh [--tag <label>]
#
# The last line of stdout is the restic snapshot id. Any failure sends an e-mail to
# TMS_ALERT_EMAIL (php artisan tms:alert) and exits non-zero.
#
# .env: RESTIC_PASSWORD (required), RESTIC_REPOSITORY (default /var/backups/tms),
# RESTIC_REMOTE_REPOSITORY, RESTIC_REMOTE_PASSWORD (default RESTIC_PASSWORD),
# RESTIC_REMOTE_ENV_FILE (credentials of the remote backend, e.g. AWS_ACCESS_KEY_ID),
# TMS_ALERT_EMAIL, TMS_BACKUP_TMPDIR (staging directory, default /var/tmp).
set -euo pipefail

# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

TAG=""
STAGE=""
SUCCESS=0

usage() {
    echo "Usage : ops/backup.sh [--tag <label>]" >&2
    exit 2
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --tag)
            [[ $# -ge 2 && -n "$2" ]] || usage
            TAG="$2"
            shift 2
            ;;
        *) usage ;;
    esac
done

send_alert() {
    local message="$1"
    [[ -n "${TMS_ALERT_EMAIL:-}" ]] || { log_info "TMS_ALERT_EMAIL non défini : aucune alerte envoyée."; return 0; }
    compose exec -T app php artisan tms:alert "$message" \
        --subject="[TMS] Échec de la sauvegarde" >/dev/null 2>&1 \
        || log_error "Impossible d'envoyer l'alerte par e-mail à ${TMS_ALERT_EMAIL}."
}

on_exit() {
    local status=$?
    [[ -z "$STAGE" ]] || rm -rf "$STAGE"
    if [[ "$SUCCESS" -ne 1 ]]; then
        send_alert "La sauvegarde de $(hostname) a échoué le $(date '+%d/%m/%Y à %H:%M') : ${LAST_ERROR:-erreur inattendue (code ${status})}"
    fi
}
trap on_exit EXIT

load_env
require_var RESTIC_PASSWORD
export RESTIC_PASSWORD
export RESTIC_REPOSITORY="${RESTIC_REPOSITORY:-/var/backups/tms}"

# Make sure the restic repository exists (idempotent).
ensure_repo() {
    if ! restic_run -- cat config >/dev/null 2>&1; then
        log_info "Initialisation du dépôt restic ${RESTIC_REPOSITORY}."
        restic_run -- init >/dev/null || die "Impossible d'initialiser le dépôt restic ${RESTIC_REPOSITORY}."
    fi
}

STAGE="$(mktemp -d "${TMS_BACKUP_TMPDIR:-/var/tmp}/tms-backup.XXXXXX")"

log_info "Export de la base de données."
# Local superuser of the postgres container (socket, not exposed): the tables are under
# FORCE ROW LEVEL SECURITY, which even the owner role cannot read through without a tenant.
compose exec -T postgres pg_dump -U postgres -d tms -Fc \
    >"$STAGE/db.dump" || die "Échec de pg_dump."
[[ -s "$STAGE/db.dump" ]] || die "L'export de la base de données est vide."
compose exec -T postgres pg_restore -l <"$STAGE/db.dump" >/dev/null || die "L'export de la base de données est illisible."

log_info "Archivage de storage/app."
compose exec -T app tar -C /var/www/html/storage -cf - app >"$STAGE/storage-app.tar" || die "Échec de l'archivage de storage/app."
[[ -s "$STAGE/storage-app.tar" ]] || die "L'archive de storage/app est vide."

ensure_repo

log_info "Création du snapshot restic."
tag_args=(--tag tms)
[[ -z "$TAG" ]] || tag_args+=(--tag "$TAG")
summary="$(restic_run -v "$STAGE:/backup-data:ro" -- backup --host tms --json "${tag_args[@]}" /backup-data)" \
    || die "Échec de restic backup."
snapshot_id="$(printf '%s\n' "$summary" | sed -n 's/.*"message_type":"summary".*"snapshot_id":"\([0-9a-f]*\)".*/\1/p' | tail -n 1)"
[[ -n "$snapshot_id" ]] || die "Identifiant du snapshot introuvable dans la sortie de restic."

log_info "Application de la rétention (14 jours, 8 semaines, 12 mois)."
restic_run -- forget --prune --host tms --keep-daily 14 --keep-weekly 8 --keep-monthly 12 >/dev/null \
    || die "Échec de la rétention restic."

if [[ -n "${RESTIC_REMOTE_REPOSITORY:-}" ]]; then
    log_info "Copie vers le dépôt distant."
    remote_args=()
    [[ -z "${RESTIC_REMOTE_ENV_FILE:-}" ]] || remote_args+=(--env-file "$RESTIC_REMOTE_ENV_FILE")
    (
        export RESTIC_FROM_REPOSITORY="$RESTIC_REPOSITORY" RESTIC_FROM_PASSWORD="$RESTIC_PASSWORD"
        export RESTIC_REPOSITORY="$RESTIC_REMOTE_REPOSITORY" RESTIC_PASSWORD="${RESTIC_REMOTE_PASSWORD:-$RESTIC_PASSWORD}"
        if ! restic_run ${remote_args[@]+"${remote_args[@]}"} -- cat config >/dev/null 2>&1; then
            restic_run ${remote_args[@]+"${remote_args[@]}"} -- init --from-repo "$RESTIC_FROM_REPOSITORY" --copy-chunker-params >/dev/null
        fi
        restic_run ${remote_args[@]+"${remote_args[@]}"} -- copy "$snapshot_id" >/dev/null
        restic_run ${remote_args[@]+"${remote_args[@]}"} -- forget --prune --host tms --keep-daily 14 --keep-weekly 8 --keep-monthly 12 >/dev/null
    ) || die "Échec de la copie vers le dépôt distant."
fi

SUCCESS=1
log_info "Sauvegarde terminée."
printf '%s\n' "$snapshot_id"
