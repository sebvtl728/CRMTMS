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
# GitHub repository publishing the releases ("owner/repo"); same constant in
# install/install.sh and install/proxmox.sh. Until set, TMS_DIST_URL is required.
TMS_GITHUB_REPO="${TMS_GITHUB_REPO:-}"

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

download() {
    log_info "Téléchargement de $1"
    curl -fsSL --retry 3 -o "$2" "$TMS_DIST_URL/$1" || die "Téléchargement impossible : $TMS_DIST_URL/$1"
}

# fetch_bundle <work dir>: downloads SHA256SUMS and the bundle from TMS_DIST_URL, verifies
# it, extracts it into <work dir>/bundle. Sets TMS_VERSION (resolved when "latest") and BUNDLE_ROOT.
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

    # Never extract absolute paths or parent references.
    if tar -tzf "$work/$archive" | grep -Eq '(^/|(^|/)\.\.(/|$))'; then
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
        docker/caddy/Caddyfile docker/postgres/init.sh docker/postgres/init.sql; do
        [[ -f "$BUNDLE_ROOT/$f" ]] || die "Archive invalide : $f manquant."
    done
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
