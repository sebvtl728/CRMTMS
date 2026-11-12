#!/usr/bin/env bash
# One-line update with automatic rollback.
#
#   ops/update.sh [--version X.Y.Z] [--force] [--allow-downgrade]
#
# Default target: the latest release (TMS_DIST_URL, or the GitHub releases of TMS_GITHUB_REPO).
#   1. SHA256SUMS + tms-<version>.tar.gz downloaded and verified (version resolved once), then
#      the VERIFIED update.sh of the new release is executed in place of this one (re-exec,
#      once), so that fixes to the updater apply to the update that brings them.
#   2. Same version refused (exit 0) unless --force; older version refused unless
#      --allow-downgrade (semantic versioning order, lib.sh semver_cmp).
#   3. New .env prepared on a private copy: keys of the new .env.prod.example missing from
#      .env are added (default value, or a generated secret for KEY=__GENERATE__).
#      Images of the new version pulled with it: nothing changed yet if it fails.
#   4. app/worker/scheduler stopped (no write after the backup can be lost), then
#      backup.sh --tag pre-update-<old>. A failed backup restarts them: nothing changed.
#   5. State file .update-in-progress written (old version, target, snapshot), previous .env
#      and bundle files kept in .bundle-previous, new files swapped in (one rename each),
#      new .env, ops/migrate.sh, compose up -d --wait, /health within 90 s reporting the new
#      version: « Mise à jour vers X.Y.Z réussie », state file removed.
# Any failure during step 5 rolls back (EXIT trap, errexit cannot skip it; INT, TERM and HUP
# end in the trap too): previous files and .env, restore.sh <snapshot> (database + storage),
# stack restarted on the previous version, exit 1. If the rollback fails: exit 2, the state
# file is kept, with the snapshot id and the manual restore command.
# After a hard kill (SIGKILL, power loss, pct stop) the state file remains: the next run of
# this script performs the rollback from it before anything else.
#
# The maintenance lock (lib.sh tms_lock) is shared with backup.sh and restore.sh and kept
# across the re-exec. Each run appends its outcome to $TMS_DIR/logs/update.log.
# TMS_PULL_POLICY (missing|always) is passed to « compose pull --policy » when set.
# Re-exec contract (stable across releases): TMS_UPDATE_REEXEC=1 update.sh --version <resolved>
#   --bundle-dir <work dir holding bundle/> [--force] [--allow-downgrade]; TMS_DIR,
#   TMS_ENV_FILE, TMS_COMPOSE_FILE, TMS_LOCK_FILE exported; the child owns the work dir.
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
BUNDLE_DIR=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --version)
            [[ $# -ge 2 && -n "$2" ]] || usage
            REQUESTED="${2#v}"
            shift 2
            ;;
        --bundle-dir)
            [[ $# -ge 2 && -n "$2" ]] || usage
            BUNDLE_DIR="$2"
            shift 2
            ;;
        --force) FORCE=1; shift ;;
        --allow-downgrade) ALLOW_DOWNGRADE=1; shift ;;
        *) usage ;;
    esac
done
REEXEC="${TMS_UPDATE_REEXEC:-0}"
unset TMS_UPDATE_REEXEC
# --bundle-dir is internal: only a verified bundle handed over by the re-exec is accepted.
[[ -z "$BUNDLE_DIR" || "$REEXEC" == "1" ]] || die "Option --bundle-dir réservée au programme de mise à jour."

VERSION_RE='^[0-9A-Za-z][0-9A-Za-z.+_-]*$'
[[ "$REQUESTED" =~ $VERSION_RE ]] || die "Version invalide : $REQUESTED"

BUNDLE_ITEMS="compose.prod.yaml .env.prod.example ops docker/caddy docker/postgres docs"
PREV_DIR="$TMS_DIR/.bundle-previous"
NEW_DIR="$TMS_DIR/.bundle-new"
STATE_FILE="$TMS_DIR/.update-in-progress"
# Image digests (release.env of the bundles; empty = tag only).
OLD_DIGEST=""
NEW_DIGEST=""
LOG_FILE="$TMS_DIR/logs/update.log"

PHASE="prepare" # prepare -> stopped -> applying -> done
WORK_DIR=""
NEW_ENV=""
SNAPSHOT=""
OLD_VERSION=""
TARGET=""

# --- helpers -------------------------------------------------------------------------

# record_outcome <text>: one line per run in logs/update.log (600). Never fails.
record_outcome() {
    {
        mkdir -p "$TMS_DIR/logs" && chmod 750 "$TMS_DIR/logs" \
            && (umask 077 && printf '%s %s -> %s : %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" \
                "${OLD_VERSION:-?}" "${TARGET:-?}" "$1" >>"$LOG_FILE")
    } 2>/dev/null || true
}

# write_private <dest> < content: atomic replace, mode 600.
write_private() {
    local tmp
    tmp="$(umask 077 && mktemp "$1.XXXXXX")" || return 1
    if ! cat >"$tmp" || ! chmod 600 "$tmp" || ! mv -f "$tmp" "$1"; then
        rm -f "$tmp"
        return 1
    fi
}

# state_value <KEY>: value recorded in the state file.
state_value() {
    sed -n "s/^$1=//p" "$STATE_FILE" | head -n 1
}

# prepare_env: $WORK_DIR/env.new = current .env + keys of the new .env.prod.example that
# .env lacks (default copied, __GENERATE__ -> random secret, empty -> skipped), TMS_VERSION
# set to the target and TMS_IMAGE_DIGEST to the digest of the verified bundle (release.env).
# Values never reach a command line or the log.
prepare_env() {
    local line key value added=""
    NEW_ENV="$WORK_DIR/env.new"
    {
        sed -e "s|^TMS_VERSION=.*|TMS_VERSION=${TARGET}|" -e '/^TMS_IMAGE_DIGEST=/d' "$TMS_ENV_FILE"
        printf 'TMS_IMAGE_DIGEST=%s\n' "$NEW_DIGEST"
        while IFS= read -r line || [[ -n "$line" ]]; do
            [[ "$line" =~ ^([A-Z_][A-Z0-9_]*)=(.*)$ ]] || continue
            key="${BASH_REMATCH[1]}"
            value="${BASH_REMATCH[2]}"
            grep -q "^${key}=" "$TMS_ENV_FILE" && continue
            [[ "$key" != TMS_IMAGE_DIGEST ]] || continue
            [[ "$value" != "__GENERATE__" ]] || value="$(gen_secret 32)"
            [[ -n "$value" ]] || continue
            [[ -n "$added" ]] || printf '\n# --- Added by the update to %s --------------------------------------------\n' "$TARGET"
            printf '%s=%s\n' "$key" "$value"
            added=1
        done <"$BUNDLE_ROOT/.env.prod.example"
    } | write_private "$NEW_ENV"
    # (the pipeline runs in a subshell: list the added keys, names only)
    added="$(sed -n 's/^\([A-Z_][A-Z0-9_]*\)=.*/\1/p' "$NEW_ENV" | while read -r key; do
        grep -q "^${key}=" "$TMS_ENV_FILE" || printf '%s ' "$key"
    done)"
    [[ -z "$added" ]] || log_info "Paramètres ajoutés à .env par cette version : ${added% }."
}

# swap_bundle: stages the verified bundle inside TMS_DIR (same file system), then for each
# item: current one moved to PREV_DIR, item listed in PREV_DIR/SWAPPED, new one moved in.
swap_bundle() {
    local item
    rm -rf "$NEW_DIR"
    mkdir -p "$NEW_DIR"
    chmod 750 "$NEW_DIR"
    cp -R "$BUNDLE_ROOT"/. "$NEW_DIR"/
    chmod 755 "$NEW_DIR"/ops/*.sh
    for item in $BUNDLE_ITEMS; do
        [[ -e "$NEW_DIR/$item" ]] || continue
        mkdir -p "$(dirname "$TMS_DIR/$item")"
        if [[ -e "$TMS_DIR/$item" ]]; then
            mv "$TMS_DIR/$item" "$PREV_DIR/$item"
        fi
        printf '%s\n' "$item" >>"$PREV_DIR/SWAPPED"
        mv "$NEW_DIR/$item" "$TMS_DIR/$item"
    done
    rm -rf "$NEW_DIR"
}

# restore_previous_files: puts back what PREV_DIR holds, whatever the point the swap reached
# (also after a hard kill): listed items, and items moved away but not yet replaced.
restore_previous_files() {
    local item ok=0
    for item in $BUNDLE_ITEMS; do
        if grep -qx "$item" "$PREV_DIR/SWAPPED" 2>/dev/null \
            || [[ -e "$PREV_DIR/$item" && ! -e "$TMS_DIR/$item" ]]; then
            rm -rf "${TMS_DIR:?}/$item" || ok=1
            if [[ -e "$PREV_DIR/$item" ]]; then
                mv "$PREV_DIR/$item" "$TMS_DIR/$item" || ok=1
            fi
        fi
    done
    rm -f "$PREV_DIR/SWAPPED"
    if [[ -f "$PREV_DIR/.env" ]]; then
        write_private "$TMS_ENV_FILE" <"$PREV_DIR/.env" || ok=1
    fi
    return "$ok"
}

# roll_back: previous files, .env, data (snapshot), stack. Exits 1, or 2 when it fails.
roll_back() {
    local ok=1
    log_error "Retour arrière vers $OLD_VERSION (snapshot $SNAPSHOT)."
    restore_previous_files || { ok=0; log_error "Fichiers ou .env de la version $OLD_VERSION non rétablis (copie dans $PREV_DIR)."; }
    export TMS_VERSION="$OLD_VERSION" TMS_IMAGE_DIGEST="$OLD_DIGEST"
    if [[ -n "$SNAPSHOT" ]]; then
        "$TMS_DIR/ops/restore.sh" "$SNAPSHOT" >&2 || ok=0
    else
        ok=0
        log_error "Aucun snapshot enregistré : base non restaurée."
    fi
    compose up -d --wait --wait-timeout 300 --force-recreate >&2 || ok=0
    wait_health 90 "$OLD_VERSION" || ok=0

    if [[ "$ok" -eq 1 ]]; then
        rm -f "$STATE_FILE"
        [[ -z "${WORK_DIR:-}" ]] || rm -rf "$WORK_DIR"
        record_outcome "échec, retour à $OLD_VERSION effectué (code 1)"
        log_error "Échec de la mise à jour : retour à $OLD_VERSION"
        exit 1
    fi
    record_outcome "échec, retour arrière en échec (code 2, snapshot $SNAPSHOT)"
    log_error "Le retour arrière vers $OLD_VERSION a échoué : intervention manuelle nécessaire."
    log_error "La base et les fichiers d'avant la mise à jour sont dans le snapshot $SNAPSHOT (dépôt restic, étiquette pre-update-$OLD_VERSION)."
    log_error "Vérifiez TMS_VERSION=$OLD_VERSION dans $TMS_ENV_FILE et les fichiers de $TMS_DIR (version précédente et .env dans $PREV_DIR), puis lancez : $TMS_DIR/ops/restore.sh $SNAPSHOT"
    log_error "Ensuite seulement, supprimez $STATE_FILE (sinon la prochaine mise à jour retentera ce retour arrière)."
    exit 2
}

on_exit() {
    local status=$?
    trap '' INT TERM HUP
    set +e
    case "$PHASE" in
        stopped)
            log_info "Redémarrage de app, worker et scheduler (version $OLD_VERSION inchangée)."
            compose up -d --wait --wait-timeout 300 >&2 || log_error "Redémarrage impossible : vérifiez « docker compose ps »."
            record_outcome "annulée avant tout changement (code $status)"
            ;;
        applying)
            log_error "Échec de la mise à jour vers $TARGET."
            roll_back
            ;;
        done) record_outcome "réussie" ;;
        *) [[ "$status" -eq 0 ]] || record_outcome "refusée ou impossible, rien n'a été modifié (code $status)" ;;
    esac
    [[ -z "$WORK_DIR" ]] || rm -rf "$WORK_DIR"
    exit "$status"
}

# recover_interrupted: an earlier update died during step 5 without cleaning up (hard kill):
# roll back from the recorded state before anything else.
recover_interrupted() {
    trap '' INT TERM HUP
    set +e
    OLD_VERSION="$(state_value OLD_VERSION)"
    OLD_DIGEST="$(state_value OLD_DIGEST)"
    TARGET="$(state_value TARGET)"
    SNAPSHOT="$(state_value SNAPSHOT)"
    WORK_DIR="$(state_value WORK_DIR)"
    log_error "Mise à jour interrompue détectée ($OLD_VERSION vers $TARGET, $STATE_FILE) : retour arrière avant toute nouvelle opération."
    [[ -n "$OLD_VERSION" && -d "$PREV_DIR" ]] \
        || { log_error "État incomplet : restaurez manuellement avec $TMS_DIR/ops/restore.sh ${SNAPSHOT:-<snapshot pre-update>}."; exit 2; }
    roll_back
}

# --- main ------------------------------------------------------------------------------

# The current version (and image digest) come from .env, never from an inherited environment.
unset TMS_VERSION TMS_IMAGE_DIGEST
load_env
OLD_VERSION="${TMS_VERSION:-}"
OLD_DIGEST="${TMS_IMAGE_DIGEST:-}"
[[ -n "$OLD_VERSION" ]] || die "TMS_VERSION absent de $TMS_ENV_FILE : installation incomplète ?"

tms_lock 0
[[ ! -f "$STATE_FILE" ]] || recover_interrupted

trap on_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

# 1. Release: resolved once, downloaded and verified, then its own updater takes over.
if [[ "$REEXEC" != "1" ]]; then
    WORK_DIR="$(mktemp -d "${TMS_BACKUP_TMPDIR:-/var/tmp}/tms-update.XXXXXX")"
    TMS_DIST_URL="$(release_dist_url "$REQUESTED")" || exit 1
    TMS_VERSION="$REQUESTED"
    fetch_bundle "$WORK_DIR"
    TARGET="$TMS_VERSION"
    export TMS_VERSION="$OLD_VERSION"
    [[ "$TARGET" =~ $VERSION_RE ]] || die "Version invalide dans la publication : $TARGET"

    args=(--version "$TARGET" --bundle-dir "$WORK_DIR")
    [[ "$FORCE" -ne 1 ]] || args+=(--force)
    [[ "$ALLOW_DOWNGRADE" -ne 1 ]] || args+=(--allow-downgrade)
    export TMS_DIR TMS_ENV_FILE TMS_COMPOSE_FILE TMS_LOCK_FILE
    log_info "Exécution du programme de mise à jour vérifié de la version $TARGET."
    trap - EXIT INT TERM HUP
    # The lock (fd 9, TMS_LOCK_HELD) and the work dir pass to the new process.
    TMS_UPDATE_REEXEC=1 exec bash "$BUNDLE_ROOT/ops/update.sh" "${args[@]}"
fi

WORK_DIR="$BUNDLE_DIR"
TARGET="$REQUESTED"
BUNDLE_ROOT="$WORK_DIR/bundle"
if [[ ! -f "$BUNDLE_ROOT/compose.prod.yaml" ]]; then
    BUNDLE_ROOT="$(dirname "$(find "$WORK_DIR/bundle" -mindepth 2 -maxdepth 2 -name compose.prod.yaml -print | head -n 1)")"
fi
[[ -f "$BUNDLE_ROOT/compose.prod.yaml" && -f "$BUNDLE_ROOT/.env.prod.example" ]] || die "Dossier de la version introuvable : $WORK_DIR"
# Image digest published with the verified bundle (empty: tag only).
NEW_DIGEST="$(image_digest_of "$BUNDLE_ROOT")" || die "Empreinte d'image de la version $TARGET illisible : mise à jour annulée."

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

# 3. New .env (private copy) and images of the new version, before anything changes.
prepare_env
log_info "Téléchargement des images de la version $TARGET."
pull_args=()
[[ -z "${TMS_PULL_POLICY:-}" ]] || pull_args=(--policy "$TMS_PULL_POLICY")
TMS_VERSION="$TARGET" TMS_IMAGE_DIGEST="$NEW_DIGEST" docker compose --project-directory "$TMS_DIR" -f "$BUNDLE_ROOT/compose.prod.yaml" \
    --env-file "$NEW_ENV" pull ${pull_args[@]+"${pull_args[@]}"} >&2 \
    || die "Téléchargement des images (ou lecture de la configuration de la version $TARGET) impossible : mise à jour annulée, rien n'a été modifié."

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

# 5. Switch. Previous .env kept, state recorded, then any failure rolls back.
rm -rf "$PREV_DIR"
mkdir -p "$PREV_DIR/docker"
chmod 750 "$PREV_DIR"
printf '%s\n' "$OLD_VERSION" >"$PREV_DIR/VERSION"
write_private "$PREV_DIR/.env" <"$TMS_ENV_FILE"
printf 'OLD_VERSION=%s\nOLD_DIGEST=%s\nTARGET=%s\nSNAPSHOT=%s\nWORK_DIR=%s\n' "$OLD_VERSION" "$OLD_DIGEST" "$TARGET" "$SNAPSHOT" "$WORK_DIR" \
    | write_private "$STATE_FILE"
PHASE="applying"
swap_bundle
write_private "$TMS_ENV_FILE" <"$NEW_ENV"
export TMS_VERSION="$TARGET" TMS_IMAGE_DIGEST="$NEW_DIGEST"
# Test hook (bats): simulates a hard kill at this point.
[[ "${TMS_UPDATE_TEST_KILL:-}" != "after-env" ]] || kill -9 $$
log_info "Migrations de la base."
"$TMS_DIR/ops/migrate.sh" >&2
log_info "Démarrage de la version $TARGET."
compose up -d --wait --wait-timeout 300 --force-recreate >&2
wait_health 90 "$TARGET"
PHASE="done"
rm -f "$STATE_FILE" "$PREV_DIR/SWAPPED"

log_info "Sauvegarde d'avant mise à jour : snapshot $SNAPSHOT ; fichiers et .env de la version $OLD_VERSION dans $PREV_DIR."
log_info "Mise à jour vers $TARGET réussie"
