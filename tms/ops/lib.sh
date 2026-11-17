#!/usr/bin/env bash
# Shared helpers for the TMS operations scripts (backup, restore, installer, updater).
# Meant to be sourced. Logs go to stderr so that stdout stays machine-readable.
set -euo pipefail

TMS_OPS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Installation directory: holds compose.prod.yaml, .env, docker/ and ops/.
TMS_DIR="${TMS_DIR:-$(dirname "$TMS_OPS_DIR")}"
TMS_ENV_FILE="${TMS_ENV_FILE:-$TMS_DIR/.env}"
TMS_COMPOSE_FILE="${TMS_COMPOSE_FILE:-$TMS_DIR/compose.prod.yaml}"
RESTIC_IMAGE="${RESTIC_IMAGE:-restic/restic:0.18.0}"
# Lock shared by update.sh, backup.sh and restore.sh (one maintenance operation at a time).
TMS_LOCK_FILE="${TMS_LOCK_FILE:-$TMS_DIR/.ops.lock}"
# systemd units of the backups (installer, updater).
TMS_SYSTEMD_DIR="${TMS_SYSTEMD_DIR:-/etc/systemd/system}"
# GitHub repository publishing the releases ("owner/repo"); same constant in
# install/install.sh and install/proxmox.sh. Until set, TMS_DIST_URL is required.
TMS_GITHUB_REPO="${TMS_GITHUB_REPO:-sebvtl728/CRMTMS}"

export LAST_ERROR=""

log_info() {
    printf '%s [info] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >&2
}

log_error() {
    LAST_ERROR="$*"
    printf '%s [erreur] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >&2
}

die() {
    log_error "$*"
    exit 1
}

# gen_secret <bytes>: random secret, base64 url-safe without padding.
gen_secret() {
    local bytes="${1:?usage: gen_secret <bytes>}"
    openssl rand -base64 "$bytes" | tr -d '\n=' | tr '+/' '-_'
}

# verify_checksum <file> <sha256>
verify_checksum() {
    local file="${1:?usage: verify_checksum <file> <sha256>}" expected="${2:?usage: verify_checksum <file> <sha256>}" actual
    [[ -f "$file" ]] || { log_error "Fichier introuvable : $file"; return 1; }
    if command -v sha256sum >/dev/null 2>&1; then
        actual="$(sha256sum "$file" | awk '{print $1}')"
    else
        actual="$(shasum -a 256 "$file" | awk '{print $1}')"
    fi
    if [[ "$actual" != "$expected" ]]; then
        log_error "Somme de contrôle invalide pour $file"
        return 1
    fi
}

# --- Release download (embedded verbatim in install/install.sh, which is fetched alone;
# ops/tests/install.bats checks that the copies stay identical) ---------------------

# checksum_of <SHA256SUMS> <name>: expected hash of a file listed in SHA256SUMS.
checksum_of() {
    awk -v n="$2" '{ f = $2; sub(/^\*/, "", f); if (f == n) { print $1; exit } }' "$1"
}

# release_dist_url <version|latest>: folder holding the release files. TMS_DIST_URL
# wins; otherwise the GitHub release of TMS_GITHUB_REPO.
release_dist_url() {
    if [[ -n "${TMS_DIST_URL:-}" ]]; then
        printf '%s' "${TMS_DIST_URL%/}"
    elif [[ -z "${TMS_GITHUB_REPO:-}" ]]; then
        log_error "Adresse de téléchargement inconnue : définissez TMS_DIST_URL (dossier des fichiers de la version, ex. https://github.com/<propriétaire>/<dépôt>/releases/latest/download)."
        return 1
    elif [[ "$1" == "latest" ]]; then
        printf 'https://github.com/%s/releases/latest/download' "$TMS_GITHUB_REPO"
    else
        printf 'https://github.com/%s/releases/download/v%s' "$TMS_GITHUB_REPO" "$1"
    fi
}

# image_digest_of <bundle root>: TMS_IMAGE_DIGEST of the bundle's release.env (empty when the
# version does not pin its image); fails on anything but sha256:<64 hex>.
image_digest_of() {
    local digest
    digest="$(sed -n 's/^TMS_IMAGE_DIGEST=//p' "$1/release.env" 2>/dev/null | head -n 1)"
    if [[ -n "$digest" && ! "$digest" =~ ^sha256:[0-9a-f]{64}$ ]]; then
        log_error "Empreinte d'image illisible dans release.env : $digest"
        return 1
    fi
    printf '%s' "$digest"
}

download() {
    log_info "Téléchargement de $1"
    curl -fsSL --retry 3 -o "$2" "$TMS_DIST_URL/$1" || die "Téléchargement impossible : $TMS_DIST_URL/$1"
}

# fetch_bundle <work dir>: downloads SHA256SUMS and the bundle from TMS_DIST_URL, verifies
# it, extracts it into <work dir>/bundle. Sets TMS_VERSION (resolved when "latest"), BUNDLE_ROOT
# and BUNDLE_IMAGE_DIGEST.
fetch_bundle() {
    local work="$1" sums="$1/SHA256SUMS" archive expected
    download SHA256SUMS "$sums"
    if [[ "$TMS_VERSION" == "latest" ]]; then
        archive="$(awk '{ f = $2; sub(/^\*/, "", f); print f }' "$sums" | grep -E '^tms-[0-9A-Za-z.+-]+\.tar\.gz$' || true)"
        [[ -n "$archive" && "$(printf '%s\n' "$archive" | wc -l | tr -d ' ')" -eq 1 ]] \
            || die "SHA256SUMS ne désigne pas une archive tms-<version>.tar.gz unique."
        TMS_VERSION="${archive#tms-}"
        TMS_VERSION="${TMS_VERSION%.tar.gz}"
    else
        archive="tms-$TMS_VERSION.tar.gz"
    fi
    expected="$(checksum_of "$sums" "$archive")"
    [[ -n "$expected" ]] || die "$archive absent de SHA256SUMS."
    download "$archive" "$work/$archive"
    verify_checksum "$work/$archive" "$expected" || die "Archive refusée : somme de contrôle invalide ($archive)."
    log_info "Archive $archive vérifiée (version $TMS_VERSION)."

    # Never extract absolute paths or parent references. (A here-string, not a pipe into
    # grep -q: under pipefail an early match would end in SIGPIPE and read as « no match ».)
    local listing
    listing="$(tar -tzf "$work/$archive")" || die "Archive illisible : $archive."
    if grep -Eq '(^/|(^|/)\.\.(/|$))' <<<"$listing"; then
        die "Archive refusée : chemins non sûrs."
    fi
    mkdir -p "$work/bundle"
    tar -xzf "$work/$archive" -C "$work/bundle" --no-same-owner

    BUNDLE_ROOT="$work/bundle"
    if [[ ! -f "$BUNDLE_ROOT/compose.prod.yaml" ]]; then
        local sub
        sub="$(find "$work/bundle" -mindepth 2 -maxdepth 2 -name compose.prod.yaml -print | head -n 1)"
        [[ -n "$sub" ]] || die "Archive invalide : compose.prod.yaml introuvable."
        BUNDLE_ROOT="$(dirname "$sub")"
    fi
    local f
    for f in compose.prod.yaml .env.prod.example ops/lib.sh ops/migrate.sh ops/backup.sh ops/restore.sh ops/update.sh \
        ops/export.sh ops/import.sh docker/caddy/Caddyfile docker/postgres/init.sh docker/postgres/init.sql release.env; do
        [[ -f "$BUNDLE_ROOT/$f" ]] || die "Archive invalide : $f manquant."
    done
    # Image of this version, pinned by digest (verified bundle -> .env -> compose).
    # shellcheck disable=SC2034  # read by the installer (env_value) and the tests
    BUNDLE_IMAGE_DIGEST="$(image_digest_of "$BUNDLE_ROOT")" || die "Archive invalide : release.env illisible."
}

# --- Versions --------------------------------------------------------------------

# semver_cmp <a> <b>: prints -1, 0 or 1 (semantic versioning precedence; build metadata
# after "+" ignored). Core fields: numeric when both are numbers, else ASCII; missing
# fields count as 0. A version without pre-release is greater than the same core with
# one; pre-release identifiers: numeric < alphanumeric, numbers numerically, fewer
# identifiers lower when all others are equal.
semver_cmp() {
    local a="${1%%+*}" b="${2%%+*}" a_pre="" b_pre="" r
    [[ "$a" != *-* ]] || a_pre="${a#*-}"
    [[ "$b" != *-* ]] || b_pre="${b#*-}"
    r="$(semver_ids_cmp "${a%%-*}" "${b%%-*}" core)"
    if [[ "$r" != 0 ]]; then
        printf '%s' "$r"
    elif [[ -z "$a_pre" && -z "$b_pre" ]]; then
        printf '0'
    elif [[ -z "$a_pre" ]]; then
        printf '1'
    elif [[ -z "$b_pre" ]]; then
        printf -- '-1'
    else
        semver_ids_cmp "$a_pre" "$b_pre" pre
    fi
}

# semver_ids_cmp <dotted a> <dotted b> <core|pre>
semver_ids_cmp() {
    local LC_ALL=C mode="$3" x y i=0
    local -a xs ys
    IFS=. read -r -a xs <<<"$1"
    IFS=. read -r -a ys <<<"$2"
    while ((i < ${#xs[@]} || i < ${#ys[@]})); do
        x="${xs[$i]-}"
        y="${ys[$i]-}"
        if [[ "$mode" == "core" ]]; then
            x="${x:-0}"
            y="${y:-0}"
        elif [[ -z "$x" ]]; then
            printf -- '-1'
            return 0
        elif [[ -z "$y" ]]; then
            printf '1'
            return 0
        fi
        if [[ "$x" =~ ^[0-9]+$ && "$y" =~ ^[0-9]+$ ]]; then
            x="$(printf '%s' "$x" | sed 's/^0*//')"
            y="$(printf '%s' "$y" | sed 's/^0*//')"
            # Compare as digit strings: length first, then lexically (no overflow).
            if ((${#x} != ${#y})); then
                ((${#x} < ${#y})) && printf -- '-1' || printf '1'
                return 0
            fi
        elif [[ "$x" =~ ^[0-9]+$ ]]; then
            printf -- '-1'
            return 0
        elif [[ "$y" =~ ^[0-9]+$ ]]; then
            printf '1'
            return 0
        fi
        if [[ "$x" < "$y" ]]; then
            printf -- '-1'
            return 0
        elif [[ "$x" > "$y" ]]; then
            printf '1'
            return 0
        fi
        i=$((i + 1))
    done
    printf '0'
}

# version_lt <a> <b>: true when a precedes b (semver_cmp).
version_lt() {
    [[ "$(semver_cmp "$1" "$2")" == "-1" ]]
}

# --- Locking -------------------------------------------------------------------------

# tms_lock [wait seconds]: takes the maintenance lock (fd 9) until the process exits, or
# dies after the wait. Child scripts started while it is held (backup.sh and restore.sh
# called by update.sh) inherit it through TMS_LOCK_HELD. flock(1), or flock(2) via perl.
tms_lock() {
    local wait="${1:-0}" waited=0
    [[ "${TMS_LOCK_HELD:-0}" != "1" ]] || return 0
    exec 9>>"$TMS_LOCK_FILE" || die "Verrou inaccessible : $TMS_LOCK_FILE"
    until lock_fd9; do
        if ((waited >= wait)); then
            exec 9>&-
            die "Une autre opération de maintenance (mise à jour, sauvegarde ou restauration) est en cours (verrou $TMS_LOCK_FILE). Réessayez une fois qu'elle est terminée."
        fi
        sleep 1
        waited=$((waited + 1))
    done
    export TMS_LOCK_HELD=1
}

lock_fd9() {
    if command -v flock >/dev/null 2>&1; then
        flock -n 9
    else
        perl -MFcntl=:flock -e 'open(my $fh, ">&=", 9) or exit 2; flock($fh, LOCK_EX | LOCK_NB) or exit 1'
    fi
}

require_root() {
    [[ "$(id -u)" -eq 0 ]] || die "Ce script doit être exécuté en root."
}

# compose <args...>: docker compose against the production stack.
compose() {
    docker compose --project-directory "$TMS_DIR" -f "$TMS_COMPOSE_FILE" --env-file "$TMS_ENV_FILE" "$@"
}

# load_env: exports the KEY=VALUE pairs of the env file that are not already set
# in the environment. No shell evaluation (values are never expanded or executed).
load_env() {
    [[ -f "$TMS_ENV_FILE" ]] || die "Fichier d'environnement introuvable : $TMS_ENV_FILE"
    local line key value
    while IFS= read -r line || [[ -n "$line" ]]; do
        [[ "$line" =~ ^[[:space:]]*([A-Za-z_][A-Za-z0-9_]*)=(.*)$ ]] || continue
        key="${BASH_REMATCH[1]}"
        value="${BASH_REMATCH[2]}"
        if [[ "$value" =~ ^\"(.*)\"$ ]] || [[ "$value" =~ ^\'(.*)\'$ ]]; then
            value="${BASH_REMATCH[1]}"
        fi
        [[ -n "${!key:-}" ]] || export "$key=$value"
    done <"$TMS_ENV_FILE"
}

# env_get <KEY>: value of KEY in the env file (first occurrence, like load_env; quotes removed,
# never evaluated), empty when absent. Exports nothing: a script that starts other ops scripts
# (import.sh) must not hand them values that would win over their own .env (APP_KEY adopted
# by restore.sh, for instance).
env_get() {
    local line value
    [[ -f "$TMS_ENV_FILE" ]] || return 0
    while IFS= read -r line || [[ -n "$line" ]]; do
        [[ "$line" =~ ^[[:space:]]*([A-Za-z_][A-Za-z0-9_]*)=(.*)$ && "${BASH_REMATCH[1]}" == "$1" ]] || continue
        value="${BASH_REMATCH[2]}"
        if [[ "$value" =~ ^\"(.*)\"$ ]] || [[ "$value" =~ ^\'(.*)\'$ ]]; then
            value="${BASH_REMATCH[1]}"
        fi
        printf '%s' "$value"
        return 0
    done <"$TMS_ENV_FILE"
}

# env_set <KEY> <VALUE>: replaces (or appends) KEY in the env file; atomic, mode 600.
env_set() {
    local tmp
    tmp="$(umask 077 && mktemp "${TMS_ENV_FILE}.XXXXXX")" || return 1
    if ! { grep -v "^$1=" "$TMS_ENV_FILE" || true; printf '%s=%s\n' "$1" "$2"; } >"$tmp" \
        || ! chmod 600 "$tmp" || ! mv -f "$tmp" "$TMS_ENV_FILE"; then
        rm -f "$tmp"
        return 1
    fi
}

# read_password <prompt>: one line typed without echo (stdin), printed on stdout for a command
# substitution (never on a command line nor in a log). Fails at end of input.
read_password() {
    local value
    printf '%s' "$1" >&2
    if ! IFS= read -rs value; then
        printf '\n' >&2
        return 1
    fi
    printf '\n' >&2
    printf '%s' "$value"
}

# sha256_of <file>: hexadecimal SHA-256 of the file.
sha256_of() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
    else
        shasum -a 256 "$1" | awk '{print $1}'
    fi
}

# require_var <NAME>: dies naming the variable when it is empty.
require_var() {
    [[ -n "${!1:-}" ]] || die "Variable $1 manquante dans $TMS_ENV_FILE"
}

# restic_run <docker args...> -- <restic args...>
# Runs restic from its official image: the host only needs Docker. Reads
# RESTIC_REPOSITORY, RESTIC_PASSWORD and, for copy, RESTIC_FROM_REPOSITORY /
# RESTIC_FROM_PASSWORD from the environment (passed by name, never on the command line).
# Local repositories (absolute paths) are bind-mounted at the same path.
restic_run() {
    local -a docker_args=()
    while [[ $# -gt 0 && "$1" != "--" ]]; do
        docker_args+=("$1")
        shift
    done
    [[ "${1:-}" == "--" ]] && shift

    local repo
    for repo in "${RESTIC_REPOSITORY:-}" "${RESTIC_FROM_REPOSITORY:-}"; do
        if [[ "$repo" == /* ]]; then
            mkdir -p "$repo"
            docker_args+=(-v "$repo:$repo")
        fi
    done

    docker run --rm --user "$(id -u):$(id -g)" \
        -e RESTIC_REPOSITORY -e RESTIC_PASSWORD -e RESTIC_FROM_REPOSITORY -e RESTIC_FROM_PASSWORD \
        ${docker_args[@]+"${docker_args[@]}"} \
        "$RESTIC_IMAGE" --no-cache "$@"
}

# export_archive_check <file>: the file is a tar holding a restic repository only (ops/export.sh):
# regular files and directories under repo/, no absolute path, no parent reference, repo/config
# present. Returns 1 when tar cannot read it (truncated, not a tar), 2 when the content is
# unexpected. Listings are matched through here-strings (no pipe into grep -q: see fetch_bundle).
export_archive_check() {
    local listing names
    if ! listing="$(tar -tvf "$1" 2>/dev/null)" || ! names="$(tar -tf "$1" 2>/dev/null)"; then
        return 1
    fi
    if grep -q '^[^-d]' <<<"$listing" \
        || grep -Evq '^repo(/|$)' <<<"$names" \
        || grep -Eq '(^|/)\.\.(/|$)' <<<"$names" \
        || ! grep -qx 'repo/config' <<<"$names"; then
        return 2
    fi
}

# restic_snapshot_ids: full ids of the snapshots of RESTIC_REPOSITORY, one per line (fails when
# the repository cannot be read).
restic_snapshot_ids() {
    local json
    json="$(restic_run -- snapshots --json)" || return 1
    printf '%s' "$json" | { grep -o '"id":"[0-9a-f]\{64\}"' || true; } | sed 's/^"id":"\(.*\)"$/\1/'
}

# --- Backup status and requests (exchange with the application) ---------------------
# Both live in the storage volume under ops/, outside storage/app: never inside a snapshot,
# never restored. The application reads ops/backup-status.json and writes
# ops/backup-request.json; it has no Docker access and no restic secret.

# storage_volume: name of the storage Docker volume of the stack (compose labels).
storage_volume() {
    local project volume
    project="$(compose config 2>/dev/null | sed -n 's/^name: *//p' | head -n 1)"
    [[ -n "$project" ]] || return 1
    volume="$(docker volume ls -q --filter "label=com.docker.compose.project=${project}" --filter "label=com.docker.compose.volume=storage" | head -n 1)"
    [[ -n "$volume" ]] || return 1
    printf '%s\n' "$volume"
}

# Shell run as root in a throwaway container with the volume at /s, before any write in /s/ops
# (a directory the app container user owns): refuses a symlinked ops/, then creates it or
# re-applies its owner and mode. Owner = the app container user, whatever its uid: owner of the
# volume root, or of storage/app when the root is still root-owned.
# shellcheck disable=SC2016  # expanded inside the container
TMS_OPS_DIR_SH='set -e
if [ -L /s/ops ]; then echo "storage/ops est un lien symbolique : opération refusée." >&2; exit 1; fi
owner="$(stat -c %u:%g /s)"
if [ "${owner%%:*}" = 0 ] && [ -d /s/app ] && [ ! -L /s/app ]; then owner="$(stat -c %u:%g /s/app)"; fi
if [ "${owner%%:*}" = 0 ]; then echo "Utilisateur de l application introuvable dans le volume storage." >&2; exit 1; fi
mkdir -p /s/ops
chown -h "$owner" /s/ops
chmod 770 /s/ops'

# ensure_ops_dir <volume>: storage/ops exists, readable and writable by the app user.
ensure_ops_dir() {
    docker run --rm -v "$1:/s" --entrypoint sh "$RESTIC_IMAGE" -c "$TMS_OPS_DIR_SH" >&2
}

# ops_json_write <volume> <file name> <jq program> [ENV NAME...]: writes ops/<file name>
# atomically (mktemp in ops/, then rename; the temporary file never survives a failure), mode
# 640, owned by the app user. The program (jq -n) reads the named environment variables
# ($ENV.NAME) and the JSON on stdin (input). Values never travel on a command line. jq of the
# restic image: the host only needs Docker.
ops_json_write() {
    local volume="$1" name="$2" var
    local -a env_args=(-e S_PROGRAM -e S_NAME)
    for var in "${@:4}"; do env_args+=(-e "$var"); done
    # shellcheck disable=SC2016  # expanded inside the container
    S_PROGRAM="$3" S_NAME="$name" docker run --rm -i -v "$volume:/s" "${env_args[@]}" \
        --entrypoint sh "$RESTIC_IMAGE" -c "$TMS_OPS_DIR_SH"'
tmp="$(mktemp /s/ops/.tmp.XXXXXX)"
trap '\''rm -f "$tmp"'\'' EXIT
jq -n "$S_PROGRAM" >"$tmp"
chown "$owner" "$tmp"
chmod 640 "$tmp"
mv -f "$tmp" "/s/ops/$S_NAME"' >&2
}

# ops_remove <volume> <file name>: removes ops/<file name> (never through a symlinked ops/).
ops_remove() {
    # shellcheck disable=SC2016  # expanded inside the container
    S_NAME="$2" docker run --rm -v "$1:/s" -e S_NAME --entrypoint sh "$RESTIC_IMAGE" -c '
if [ -L /s/ops ]; then echo "storage/ops est un lien symbolique : opération refusée." >&2; exit 1; fi
rm -f "/s/ops/$S_NAME"' >&2
}

# write_backup_status <volume> <success true|false> <snapshot id> <error> <size bytes>
#   <remote configured true|false> <remote success true|false|"">
# ops/backup-status.json. stdin: output of « restic snapshots --json » (empty or unreadable: no
# list); kept newest first, at most 60, id/time/tags only. Empty strings become null.
write_backup_status() {
    # shellcheck disable=SC2016  # jq program
    local program='(try (input | if type == "array" then . else [] end) catch []) as $snaps
        | def nullable: if . == "" then null else . end;
        {
            finished_at: $ENV.S_FINISHED_AT,
            success: ($ENV.S_SUCCESS == "true"),
            snapshot_id: ($ENV.S_SNAPSHOT_ID | nullable),
            error: ($ENV.S_ERROR | nullable),
            version: ($ENV.S_VERSION | nullable),
            size_bytes: ($ENV.S_SIZE | nullable | if . == null then null else tonumber end),
            remote: {
                configured: ($ENV.S_REMOTE_CONFIGURED == "true"),
                success: ($ENV.S_REMOTE_SUCCESS | nullable | if . == null then null else . == "true" end)
            },
            snapshots: ($snaps | reverse | .[:60] | map({id, time, tags: (.tags // [])}))
        }'
    S_FINISHED_AT="$(date -u '+%Y-%m-%dT%H:%M:%SZ')" S_SUCCESS="$2" S_SNAPSHOT_ID="$3" S_ERROR="$4" \
        S_VERSION="${TMS_VERSION:-}" S_SIZE="$5" S_REMOTE_CONFIGURED="$6" S_REMOTE_SUCCESS="$7" \
        ops_json_write "$1" backup-status.json "$program" \
        S_FINISHED_AT S_SUCCESS S_SNAPSHOT_ID S_ERROR S_VERSION S_SIZE S_REMOTE_CONFIGURED S_REMOTE_SUCCESS
}

# write_backup_running <volume> <tag>: ops/backup-running.json {started_at, tag} while a backup
# runs (« Sauvegarde en cours… »); removed by backup.sh when it ends (remove_backup_running).
write_backup_running() {
    # shellcheck disable=SC2016  # jq program
    S_STARTED_AT="$(date -u '+%Y-%m-%dT%H:%M:%SZ')" S_TAG="$2" \
        ops_json_write "$1" backup-running.json '{started_at: $ENV.S_STARTED_AT, tag: $ENV.S_TAG}' S_STARTED_AT S_TAG </dev/null
}

remove_backup_running() {
    ops_remove "$1" backup-running.json
}

# consume_backup_request <volume>: removes ops/backup-request.json (the backup it asked for starts).
consume_backup_request() {
    ops_remove "$1" backup-request.json
}

# enable_backup_requests: « Sauvegarder maintenant » of the application. Creates storage/ops and,
# on a systemd host where the backup timer is installed, tms-backup-request.path (watches the
# request file through the volume mount point on the host) and tms-backup-request.service
# (ops/backup.sh --tag manuel). Idempotent: run by the installer and by every update.
enable_backup_requests() {
    local volume mountpoint
    volume="$(storage_volume)" || { log_error "Volume storage introuvable : sauvegarde à la demande non activée."; return 1; }
    ensure_ops_dir "$volume" || { log_error "Impossible de créer storage/ops dans le volume ${volume}."; return 1; }
    if [[ ! -f "$TMS_SYSTEMD_DIR/tms-backup.service" ]] || ! command -v systemctl >/dev/null 2>&1; then
        return 0
    fi
    mountpoint="$(docker volume inspect -f '{{.Mountpoint}}' "$volume")" && [[ "$mountpoint" == /* ]] \
        || { log_error "Point de montage du volume ${volume} introuvable : sauvegarde à la demande non activée."; return 1; }
    # Called in an « || » list (errexit off): every step checks its own result.
    cat >"$TMS_SYSTEMD_DIR/tms-backup-request.service" <<UNIT || return 1
[Unit]
Description=Sauvegarde TMS demandée depuis l'application (restic)
After=docker.service
Requires=docker.service

[Service]
Type=oneshot
# Consumed first, on the host: a failing run never re-triggers itself in a loop. ops/ belongs
# to the app user: find (-P) never follows a symlinked ops/ nor deletes anything but a file.
ExecStartPre=-/usr/bin/find ${mountpoint}/ops -maxdepth 1 -name backup-request.json -type f -delete
ExecStart=$TMS_DIR/ops/backup.sh --tag manuel
Nice=10
IOSchedulingClass=idle
UNIT
    cat >"$TMS_SYSTEMD_DIR/tms-backup-request.path" <<UNIT || return 1
[Unit]
Description=Demandes de sauvegarde TMS (bouton « Sauvegarder maintenant »)

[Path]
PathExists=${mountpoint}/ops/backup-request.json
Unit=tms-backup-request.service

[Install]
WantedBy=paths.target
UNIT
    { systemctl daemon-reload && systemctl enable tms-backup-request.path && systemctl restart tms-backup-request.path; } >&2 \
        || { log_error "Activation de tms-backup-request.path impossible."; return 1; }
    log_info "Sauvegarde à la demande activée (tms-backup-request.path)."
}

# wait_health [seconds] [version]: waits until the application answers 200 on /health
# (internal HTTP listener of the caddy container, not published on the host) and, when
# given, reports that version.
wait_health() {
    local timeout="${1:-120}" version="${2:-}" waited=0 body
    while ((waited < timeout)); do
        if body="$(compose exec -T caddy wget -q -O - http://127.0.0.1:8081/health 2>/dev/null)" \
            && [[ -z "$version" || "$body" == *"\"version\":\"${version}\""* ]]; then
            return 0
        fi
        sleep 2
        waited=$((waited + 2))
    done
    log_error "L'application ne répond pas sur /health${version:+ en version ${version}} après ${timeout} s."
    return 1
}
