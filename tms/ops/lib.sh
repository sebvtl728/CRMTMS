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

# wait_health [seconds]: waits until the application answers 200 on /health
# (internal HTTP listener of the caddy container, not published on the host).
wait_health() {
    local timeout="${1:-120}" waited=0
    while ((waited < timeout)); do
        if compose exec -T caddy wget -q -O - http://127.0.0.1:8081/health >/dev/null 2>&1; then
            return 0
        fi
        sleep 2
        waited=$((waited + 2))
    done
    log_error "L'application ne répond pas sur /health après ${timeout} s."
    return 1
}
