#!/usr/bin/env bash
# Export to move to another server: ONE encrypted file holding the database, storage/app,
# APP_KEY and the version (ops/import.sh on the new server).
#
#   ops/export.sh [--output <dossier>] [--stop]
#
# 1. ops/backup.sh --tag export: a snapshot of the local repository, as every night;
# 2. a standalone restic repository, initialised with the EXPORT password and the chunker
#    parameters of the local one, receives that snapshot only (restic copy) and is checked;
# 3. it is archived as tms-export-<organisme ou hôte>-<AAAAMMJJ-HHMM>-v<version>.tar (mode 600)
#    in the output directory (default /var/backups/tms-exports, created root:root mode 700).
#    Everything inside is encrypted by restic: without the export password it is unreadable.
# Prints the SHA-256 of the file, then, as the last line of stdout, its path.
#
# --stop (move to another server): app, worker and scheduler are stopped BEFORE the backup, so
# nothing typed or processed on this server can be missing from the export; on failure they are
# started again (this server stays as it was). After a successful export, the automatic backups
# (tms-backup.timer, tms-backup-request.path) are disabled too; the commands to restart
# everything, if the move is cancelled, are printed.
#
# Export password: typed twice (at least 12 characters), or TMS_EXPORT_PASSWORD; it reaches
# restic through the environment only, never a command line nor a log.
# Holds the maintenance lock throughout (shared with backup, restore, update and import).
set -euo pipefail

# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

usage() {
    echo "Usage : ops/export.sh [--output <dossier>] [--stop]" >&2
    exit 2
}

DEFAULT_OUTPUT="/var/backups/tms-exports"
OUTPUT=""
STOP=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --stop)
            STOP=1
            shift
            ;;
        --output)
            [[ $# -ge 2 && -n "$2" ]] || usage
            OUTPUT="$2"
            shift 2
            ;;
        *) usage ;;
    esac
done
if [[ -z "$OUTPUT" ]]; then
    OUTPUT="$DEFAULT_OUTPUT"
    (umask 077 && mkdir -p "$OUTPUT") || die "Création de $OUTPUT impossible."
    chmod 700 "$OUTPUT" || die "Droits de $OUTPUT non modifiables."
    if [[ "$(id -u)" -eq 0 ]]; then chown root:root "$OUTPUT" || die "Propriétaire de $OUTPUT non modifiable."; fi
fi
[[ -d "$OUTPUT" && -w "$OUTPUT" ]] || die "Dossier de destination inaccessible en écriture : $OUTPUT"
OUTPUT="$(cd "$OUTPUT" && pwd)"

if [[ -n "${TMS_EXPORT_PASSWORD:-}" ]]; then
    EXPORT_PASSWORD="$TMS_EXPORT_PASSWORD"
else
    EXPORT_PASSWORD="$(read_password "Mot de passe de l'export (12 caractères minimum) : ")" \
        || die "Mot de passe de l'export non saisi."
    confirm="$(read_password "Confirmez le mot de passe de l'export : ")" || die "Confirmation du mot de passe non saisie."
    [[ "$EXPORT_PASSWORD" == "$confirm" ]] || die "Les deux mots de passe saisis diffèrent : export annulé."
    unset confirm
fi
unset TMS_EXPORT_PASSWORD
((${#EXPORT_PASSWORD} >= 12)) || die "Mot de passe de l'export trop court : 12 caractères minimum."

SERVICES=(app worker scheduler)
WORK=""
TMP_FILE=""
STOPPED=0
on_exit() {
    local status=$?
    [[ -z "$WORK" ]] || rm -rf "$WORK"
    [[ -z "$TMP_FILE" ]] || rm -f "$TMP_FILE"
    if [[ "$status" -ne 0 && "$STOPPED" -eq 1 ]]; then
        log_info "Export échoué : redémarrage de l'application sur ce serveur."
        compose up -d "${SERVICES[@]}" >&2 \
            || log_error "Redémarrage impossible : lancez « $(restart_command) »."
    fi
}
trap on_exit EXIT

# restart_command: the command that starts the application of this server again.
restart_command() {
    printf 'docker compose --project-directory %s -f %s --env-file %s up -d' "$TMS_DIR" "$TMS_COMPOSE_FILE" "$TMS_ENV_FILE"
}

tms_lock 0

if [[ "$STOP" -eq 1 ]]; then
    log_info "Arrêt de l'application (${SERVICES[*]}) : plus rien ne peut être saisi ni traité sur ce serveur pendant et après l'export."
    STOPPED=1
    compose stop "${SERVICES[@]}" >&2 || die "Arrêt de l'application impossible : export annulé."
fi

log_info "Sauvegarde de l'état actuel (étiquette export)."
SNAPSHOT="$("$TMS_OPS_DIR/backup.sh" --tag export | tail -n 1)" || die "Échec de la sauvegarde : export annulé."
[[ "$SNAPSHOT" =~ ^[0-9a-f]{8,64}$ ]] || die "Identifiant de la sauvegarde introuvable : export annulé."

load_env
require_var RESTIC_PASSWORD
WORK="$(umask 077 && mktemp -d "${TMS_BACKUP_TMPDIR:-/var/tmp}/tms-export.XXXXXX")"
mkdir "$WORK/repo"

log_info "Création du dépôt chiffré de l'export."
export RESTIC_FROM_REPOSITORY="${RESTIC_REPOSITORY:-/var/backups/tms}" RESTIC_FROM_PASSWORD="$RESTIC_PASSWORD"
export RESTIC_REPOSITORY="$WORK/repo" RESTIC_PASSWORD="$EXPORT_PASSWORD"
restic_run -- init --from-repo "$RESTIC_FROM_REPOSITORY" --copy-chunker-params >/dev/null \
    || die "Impossible de créer le dépôt de l'export."
restic_run -- copy "$SNAPSHOT" >/dev/null || die "Échec de la copie de la sauvegarde dans l'export."
unset RESTIC_FROM_REPOSITORY RESTIC_FROM_PASSWORD

log_info "Vérification du dépôt de l'export."
restic_run -- check --read-data >/dev/null || die "Le dépôt de l'export est incohérent : export annulé."
ids="$(restic_snapshot_ids)" || die "Le dépôt de l'export est illisible : export annulé."
[[ "$(grep -c . <<<"$ids")" -eq 1 ]] || die "Le dépôt de l'export doit contenir une seule sauvegarde."

# File name: organisme (single mode) or host name, date, version; nothing but [a-z0-9.+_-].
if [[ "${TMS_MODE:-single}" == "multi" ]]; then
    origin="$(hostname -s 2>/dev/null || hostname)"
else
    origin="${TMS_DEFAULT_TENANT:-default}"
fi
origin="$(printf '%s' "$origin" | LC_ALL=C tr '[:upper:]' '[:lower:]' | LC_ALL=C sed -E 's/[^a-z0-9]+/-/g; s/^-+//; s/-+$//')"
version="$(printf '%s' "${TMS_VERSION:-inconnue}" | LC_ALL=C sed -E 's/[^0-9A-Za-z.+_-]+/-/g')"
FILE="$OUTPUT/tms-export-${origin:-tms}-$(date '+%Y%m%d-%H%M')-v${version}.tar"
[[ ! -e "$FILE" ]] || die "Le fichier existe déjà : $FILE"

log_info "Archivage dans un seul fichier."
TMP_FILE="$(umask 077 && mktemp "$OUTPUT/.tms-export.XXXXXX")"
tar -cf "$TMP_FILE" -C "$WORK" repo || die "Échec de l'archivage de l'export."
chmod 600 "$TMP_FILE"
# Never replaces a file that appeared meanwhile.
ln "$TMP_FILE" "$FILE" 2>/dev/null || die "Impossible d'écrire $FILE (le fichier existe déjà ?)."
rm -f "$TMP_FILE"
TMP_FILE=""

if [[ "$STOP" -eq 1 ]]; then
    units=()
    for unit in tms-backup.timer tms-backup-request.path; do
        [[ ! -f "$TMS_SYSTEMD_DIR/$unit" ]] || units+=("$unit")
    done
    if [[ ${#units[@]} -gt 0 ]] && command -v systemctl >/dev/null 2>&1; then
        systemctl disable --now "${units[@]}" >&2 \
            || log_error "Sauvegardes automatiques non désactivées : systemctl disable --now ${units[*]}"
    fi
    STOPPED=0
    log_info "Ancien serveur arrêté : application, files d'attente et tâches planifiées arrêtées, sauvegardes automatiques désactivées. Ne le redémarrez pas après la bascule (les tâches tourneraient deux fois)."
    enable=""
    [[ ${#units[@]} -eq 0 ]] || enable=" && systemctl enable --now ${units[*]}"
    log_info "Pour annuler le déménagement et le remettre en service : $(restart_command)${enable}"
fi

log_info "Export terminé. Copiez ce fichier sur le nouveau serveur, puis : ops/import.sh <fichier> (ou install.sh --import <fichier>)."
log_info "Conservez le mot de passe de l'export : sans lui le fichier est illisible."
printf 'SHA-256 : %s\n' "$(sha256_of "$FILE")"
printf '%s\n' "$FILE"
