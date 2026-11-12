#!/usr/bin/env bash
# One-line update with automatic rollback.
#
#   ops/update.sh [--version X.Y.Z] [--force] [--allow-downgrade]
#
# Default target: the latest release (TMS_DIST_URL, or the GitHub releases of TMS_GITHUB_REPO).
#   1. SHA256SUMS + tms-<version>.tar.gz downloaded and verified; the version is resolved once.
#   2. Same version refused (exit 0) unless --force; downgrade refused unless --allow-downgrade.
#   3. Images of the new version pulled (with the new compose file): nothing changed yet.
#   4. app/worker/scheduler stopped (no write after the backup can be lost), then
#      backup.sh --tag pre-update-<old>. A failed backup restarts them: nothing changed.
#   5. Bundle files swapped (staged, then one rename per item; the previous ones are kept in
#      .bundle-previous), TMS_VERSION written in .env, ops/migrate.sh, compose up -d --wait,
#      /health within 90 s reporting the new version: « Mise à jour vers X.Y.Z réussie ».
# Any failure during step 5 rolls back (EXIT trap, so errexit cannot skip it): previous bundle
# files and TMS_VERSION, restore.sh <snapshot> (database + storage), stack restarted on the
# previous version, exit 1. If the rollback itself fails: exit 2, with the snapshot id and the
# manual restore command.
#
# The maintenance lock (lib.sh tms_lock) is shared with backup.sh and restore.sh.
# TMS_PULL_POLICY (missing|always) is passed to « compose pull --policy » when set.
set -euo pipefail

# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

usage() {
    echo "Usage : ops/update.sh [--version X.Y.Z] [--force] [--allow-downgrade]" >&2
    exit 2
}

REQUESTED="latest"
FORCE=0
ALLOW_DOWNGRADE=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --version)
            [[ $# -ge 2 && -n "$2" ]] || usage
            REQUESTED="${2#v}"
            shift 2
            ;;
        --force) FORCE=1; shift ;;
        --allow-downgrade) ALLOW_DOWNGRADE=1; shift ;;
        *) usage ;;
    esac
done

VERSION_RE='^[0-9A-Za-z][0-9A-Za-z.+_-]*$'
[[ "$REQUESTED" =~ $VERSION_RE ]] || die "Version invalide : $REQUESTED"

BUNDLE_ITEMS="compose.prod.yaml .env.prod.example ops docker/caddy docker/postgres docs"
PREV_DIR="$TMS_DIR/.bundle-previous"
NEW_DIR="$TMS_DIR/.bundle-new"

PHASE="prepare" # prepare -> stopped -> applying -> done
WORK_DIR=""
SNAPSHOT=""
SWAPPED=""
OLD_VERSION=""
TARGET=""

# version_lt <a> <b>: true when a sorts strictly before b (sort -V).
version_lt() {
    [[ "$1" != "$2" && "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -n 1)" == "$1" ]]
}

# set_env_version <version>: rewrites TMS_VERSION in .env atomically (mode 600).
set_env_version() {
    local tmp
    tmp="$(umask 077 && mktemp "${TMS_ENV_FILE}.XXXXXX")" || return 1
    sed "s|^TMS_VERSION=.*|TMS_VERSION=$1|" "$TMS_ENV_FILE" >"$tmp" || { rm -f "$tmp"; return 1; }
    if ! chmod 600 "$tmp" || ! mv -f "$tmp" "$TMS_ENV_FILE"; then
        rm -f "$tmp"
        return 1
    fi
    export TMS_VERSION="$1"
}

# swap_bundle: stages the verified bundle inside TMS_DIR (same file system), then moves each
# current item to PREV_DIR and the new one into place (one rename each).
swap_bundle() {
    local item
    rm -rf "$PREV_DIR" "$NEW_DIR"
    mkdir -p "$PREV_DIR/docker" "$NEW_DIR"
    chmod 750 "$PREV_DIR" "$NEW_DIR"
    cp -R "$BUNDLE_ROOT"/. "$NEW_DIR"/
    chmod 755 "$NEW_DIR"/ops/*.sh
    printf '%s\n' "$OLD_VERSION" >"$PREV_DIR/VERSION"
    for item in $BUNDLE_ITEMS; do
        [[ -e "$NEW_DIR/$item" ]] || continue
        mkdir -p "$(dirname "$TMS_DIR/$item")"
        if [[ -e "$TMS_DIR/$item" ]]; then
            mv "$TMS_DIR/$item" "$PREV_DIR/$item"
        fi
        SWAPPED="$item $SWAPPED"
        mv "$NEW_DIR/$item" "$TMS_DIR/$item"
    done
    rm -rf "$NEW_DIR"
}

# swap_back: puts the previous items back (reverse order).
swap_back() {
    local item ok=0
    for item in $SWAPPED; do
        rm -rf "${TMS_DIR:?}/$item" || ok=1
        if [[ -e "$PREV_DIR/$item" ]]; then
            mv "$PREV_DIR/$item" "$TMS_DIR/$item" || ok=1
        fi
    done
    SWAPPED=""
    return "$ok"
}

rollback() {
    local ok=1
    log_error "Échec de la mise à jour vers $TARGET : retour arrière vers $OLD_VERSION (snapshot $SNAPSHOT)."
    swap_back || { ok=0; log_error "Fichiers de la version $OLD_VERSION non rétablis (copie dans $PREV_DIR)."; }
    set_env_version "$OLD_VERSION" || { ok=0; log_error "TMS_VERSION=$OLD_VERSION non rétabli dans $TMS_ENV_FILE."; }
    export TMS_VERSION="$OLD_VERSION"
    "$TMS_DIR/ops/restore.sh" "$SNAPSHOT" >&2 || ok=0
    compose up -d --wait --wait-timeout 300 --force-recreate >&2 || ok=0
    wait_health 90 "$OLD_VERSION" || ok=0

    if [[ "$ok" -eq 1 ]]; then
        log_error "Échec de la mise à jour : retour à $OLD_VERSION"
        exit 1
    fi
    log_error "Le retour arrière vers $OLD_VERSION a échoué : intervention manuelle nécessaire."
    log_error "La base et les fichiers d'avant la mise à jour sont dans le snapshot $SNAPSHOT (dépôt restic, étiquette pre-update-$OLD_VERSION)."
    log_error "Vérifiez TMS_VERSION=$OLD_VERSION dans $TMS_ENV_FILE et les fichiers de $TMS_DIR (version précédente dans $PREV_DIR), puis lancez : $TMS_DIR/ops/restore.sh $SNAPSHOT"
    exit 2
}

on_exit() {
    local status=$?
    trap '' INT TERM
    set +e
    [[ -z "$WORK_DIR" ]] || rm -rf "$WORK_DIR"
    case "$PHASE" in
        stopped)
            log_info "Redémarrage de app, worker et scheduler (version $OLD_VERSION inchangée)."
            compose up -d --wait --wait-timeout 300 >&2 || log_error "Redémarrage impossible : vérifiez « docker compose ps »."
            ;;
        applying)
            rollback
            ;;
    esac
    exit "$status"
}

# The current version comes from .env, never from an inherited environment.
unset TMS_VERSION
load_env
OLD_VERSION="${TMS_VERSION:-}"
[[ -n "$OLD_VERSION" ]] || die "TMS_VERSION absent de $TMS_ENV_FILE : installation incomplète ?"

tms_lock 0
trap on_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# 1. Release: resolved once, downloaded and verified.
WORK_DIR="$(mktemp -d "${TMS_BACKUP_TMPDIR:-/var/tmp}/tms-update.XXXXXX")"
TMS_DIST_URL="$(release_dist_url "$REQUESTED")" || exit 1
TMS_VERSION="$REQUESTED"
fetch_bundle "$WORK_DIR"
TARGET="$TMS_VERSION"
export TMS_VERSION="$OLD_VERSION"
[[ "$TARGET" =~ $VERSION_RE ]] || die "Version invalide dans la publication : $TARGET"

# 2. Same version, downgrade.
if [[ "$TARGET" == "$OLD_VERSION" && "$FORCE" -ne 1 ]]; then
    log_info "TMS est déjà en version $TARGET : aucune mise à jour nécessaire (--force pour la réinstaller)."
    exit 0
fi
if version_lt "$TARGET" "$OLD_VERSION" && [[ "$ALLOW_DOWNGRADE" -ne 1 ]]; then
    die "Retour à une version antérieure refusé ($OLD_VERSION → $TARGET) : les migrations ne se défont pas. Préférez ops/restore.sh avec le snapshot étiqueté pre-update-$TARGET ; sinon relancez avec --allow-downgrade."
fi

if [[ -z "${TMS_PCT_SNAPSHOT:-}" ]] && { command -v pct >/dev/null 2>&1 \
    || [[ "$(systemd-detect-virt -c 2>/dev/null || true)" == "lxc" ]]; }; then
    log_info "Conseil : sur Proxmox, lancez plutôt « proxmox.sh update <CTID> » depuis le nœud (instantané du conteneur avant la mise à jour)."
fi
log_info "Mise à jour de $OLD_VERSION vers $TARGET."

# 3. Images of the new version, before anything changes.
log_info "Téléchargement des images de la version $TARGET."
pull_args=()
[[ -z "${TMS_PULL_POLICY:-}" ]] || pull_args=(--policy "$TMS_PULL_POLICY")
TMS_VERSION="$TARGET" docker compose --project-directory "$TMS_DIR" -f "$BUNDLE_ROOT/compose.prod.yaml" \
    --env-file "$TMS_ENV_FILE" pull ${pull_args[@]+"${pull_args[@]}"} >&2 \
    || die "Téléchargement des images impossible : mise à jour annulée, rien n'a été modifié."

# 4. No writer during the backup and the switch, then the backup.
PHASE="stopped"
log_info "Arrêt de app, worker et scheduler."
compose stop app worker scheduler >&2
log_info "Sauvegarde avant mise à jour (étiquette pre-update-$OLD_VERSION)."
backup_out="$("$TMS_OPS_DIR/backup.sh" --tag "pre-update-$OLD_VERSION")" \
    || die "Sauvegarde impossible : mise à jour annulée, rien n'a été modifié."
SNAPSHOT="$(printf '%s\n' "$backup_out" | tail -n 1)"
[[ "$SNAPSHOT" =~ ^[0-9a-f]{8,64}$ ]] || die "Identifiant de sauvegarde illisible : mise à jour annulée, rien n'a été modifié."
log_info "Sauvegarde $SNAPSHOT créée."

# 5. Switch. From here any failure rolls back.
PHASE="applying"
swap_bundle
set_env_version "$TARGET"
log_info "Migrations de la base."
"$TMS_DIR/ops/migrate.sh" >&2
log_info "Démarrage de la version $TARGET."
compose up -d --wait --wait-timeout 300 --force-recreate >&2
wait_health 90 "$TARGET"
PHASE="done"

log_info "Sauvegarde d'avant mise à jour : snapshot $SNAPSHOT ; fichiers de la version $OLD_VERSION dans $PREV_DIR."
log_info "Mise à jour vers $TARGET réussie"
