#!/usr/bin/env bash
# TMS installer for Debian 12 / Ubuntu 24.04 (root). Also run inside the LXC
# container created by proxmox.sh.
#
#   TMS_DOMAIN=tms.example.org TMS_ADMIN_EMAIL=admin@example.org TMS_TENANT_NAME="CAP FORMATION" \
#     bash -c "$(curl -fsSL <release>/install.sh)"
#
# Self-contained on purpose: it is fetched alone with curl, before the release
# bundle (and its ops/lib.sh) exists on the machine. It embeds the few helpers it
# needs before the bundle is verified, then sources the bundle's ops/lib.sh.
#
# Settings (environment, or KEY=VALUE file named by TMS_INSTALL_ENV):
#   TMS_DOMAIN, TMS_ADMIN_EMAIL, TMS_TENANT_NAME   required
#   TMS_ADMIN_NAME      default "Administrateur"
#   TMS_TENANT_SLUG     default derived from TMS_TENANT_NAME (TMS_DEFAULT_TENANT)
#   TMS_VERSION         default latest (resolved from the release SHA256SUMS)
#   TMS_DIST_URL        folder holding the release files (default: GitHub releases)
#   TMS_IMAGE / TMS_IMAGE_OWNER   application image (default ghcr.io/<owner>/tms)
#   TMS_SEED_CAP=1      load the CAP FORMATION references (0 to skip)
#   TMS_TLS=acme        acme (Let's Encrypt) or internal (local CA, LAN only)
#   TMS_MODE=single     single (one organisme) or multi
#   TMS_PRINT_SECRETS=1 print RESTIC_PASSWORD and APP_KEY once at the end (0: only their location)
#   TMS_DIR=/opt/tms
#
# Secrets are generated here, written only to $TMS_DIR/.env (mode 600), never
# passed on a command line and never written to a log file.
set -euo pipefail

# GitHub repository publishing the releases ("owner/repo"). Set once the
# repository exists; until then TMS_DIST_URL and TMS_IMAGE_OWNER are required.
TMS_GITHUB_REPO="${TMS_GITHUB_REPO:-}"

TMS_DIR="${TMS_DIR:-/opt/tms}"
TMS_SYSTEMD_DIR="${TMS_SYSTEMD_DIR:-/etc/systemd/system}"

# --- Minimal helpers (same behaviour as ops/lib.sh) ------------------------------

log_info() {
    printf '%s [info] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >&2
}

log_error() {
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

# checksum_of <SHA256SUMS> <name>: expected hash of a file listed in SHA256SUMS.
checksum_of() {
    awk -v n="$2" '{ f = $2; sub(/^\*/, "", f); if (f == n) { print $1; exit } }' "$1"
}

# release_dist_url <version|latest>: folder holding the release files (TMS_DIST_URL wins).
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

# load_settings_file <file>: KEY=VALUE lines for the known settings only, never
# evaluated; values already present in the environment win.
load_settings_file() {
    local file="$1" line key value
    [[ -f "$file" ]] || die "Fichier de paramètres introuvable : $file"
    while IFS= read -r line || [[ -n "$line" ]]; do
        [[ "$line" =~ ^([A-Z_][A-Z0-9_]*)=(.*)$ ]] || continue
        key="${BASH_REMATCH[1]}"
        value="${BASH_REMATCH[2]}"
        case "$key" in
            TMS_DOMAIN | TMS_ADMIN_EMAIL | TMS_ADMIN_NAME | TMS_TENANT_NAME | TMS_TENANT_SLUG | TMS_VERSION | \
                TMS_DIST_URL | TMS_IMAGE | TMS_IMAGE_OWNER | TMS_SEED_CAP | TMS_TLS | TMS_MODE | TMS_PRINT_SECRETS)
                [[ -n "${!key:-}" ]] || export "$key=$value"
                ;;
        esac
    done <"$file"
}

# slugify <text>: lowercase ASCII words joined by dashes. French accents are
# transliterated explicitly (iconv //TRANSLIT depends on the locale).
slugify() {
    printf '%s' "$1" | LC_ALL=C sed \
        -e 's/à/a/g; s/á/a/g; s/â/a/g; s/ä/a/g; s/ã/a/g; s/À/a/g; s/Á/a/g; s/Â/a/g; s/Ä/a/g; s/Ã/a/g' \
        -e 's/ç/c/g; s/Ç/c/g; s/ñ/n/g; s/Ñ/n/g; s/ÿ/y/g; s/Ÿ/y/g' \
        -e 's/è/e/g; s/é/e/g; s/ê/e/g; s/ë/e/g; s/È/e/g; s/É/e/g; s/Ê/e/g; s/Ë/e/g' \
        -e 's/ì/i/g; s/í/i/g; s/î/i/g; s/ï/i/g; s/Ì/i/g; s/Í/i/g; s/Î/i/g; s/Ï/i/g' \
        -e 's/ò/o/g; s/ó/o/g; s/ô/o/g; s/ö/o/g; s/õ/o/g; s/Ò/o/g; s/Ó/o/g; s/Ô/o/g; s/Ö/o/g; s/Õ/o/g' \
        -e 's/ù/u/g; s/ú/u/g; s/û/u/g; s/ü/u/g; s/Ù/u/g; s/Ú/u/g; s/Û/u/g; s/Ü/u/g' \
        -e 's/œ/oe/g; s/Œ/oe/g; s/æ/ae/g; s/Æ/ae/g' \
        | LC_ALL=C tr '[:upper:]' '[:lower:]' | LC_ALL=C sed -E 's/[^a-z0-9]+/-/g; s/^-+//; s/-+$//'
}

# --- Steps -------------------------------------------------------------------------

check_settings() {
    [[ -n "${TMS_DOMAIN:-}" ]] || die "Variable TMS_DOMAIN manquante (nom de domaine, ex. tms.example.org)."
    [[ -n "${TMS_ADMIN_EMAIL:-}" ]] || die "Variable TMS_ADMIN_EMAIL manquante (e-mail de l'administrateur)."
    [[ -n "${TMS_TENANT_NAME:-}" ]] || die "Variable TMS_TENANT_NAME manquante (nom de l'organisme)."

    TMS_DOMAIN="$(printf '%s' "$TMS_DOMAIN" | tr '[:upper:]' '[:lower:]')"
    [[ "$TMS_DOMAIN" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ || "$TMS_DOMAIN" == "localhost" ]] \
        || die "TMS_DOMAIN invalide : $TMS_DOMAIN (nom d'hôte attendu, sans https:// ni port)."
    [[ "$TMS_ADMIN_EMAIL" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]] \
        || die "TMS_ADMIN_EMAIL invalide : $TMS_ADMIN_EMAIL"

    TMS_ADMIN_NAME="${TMS_ADMIN_NAME:-Administrateur}"
    TMS_SEED_CAP="${TMS_SEED_CAP:-1}"
    TMS_PRINT_SECRETS="${TMS_PRINT_SECRETS:-1}"
    TMS_MODE="${TMS_MODE:-single}"
    [[ "$TMS_MODE" == "single" || "$TMS_MODE" == "multi" ]] || die "TMS_MODE invalide : $TMS_MODE (single ou multi)."
    TMS_TLS="${TMS_TLS:-acme}"
    [[ "$TMS_TLS" == "acme" || "$TMS_TLS" == "internal" ]] \
        || die "TMS_TLS invalide : $TMS_TLS (acme = certificat Let's Encrypt, internal = autorité locale pour un réseau interne)."

    TMS_TENANT_SLUG="${TMS_TENANT_SLUG:-$(slugify "$TMS_TENANT_NAME")}"
    [[ -n "$TMS_TENANT_SLUG" ]] || TMS_TENANT_SLUG="organisme"
    [[ "$TMS_TENANT_SLUG" =~ ^[a-z0-9]+(-[a-z0-9]+)*$ ]] \
        || die "TMS_TENANT_SLUG invalide : $TMS_TENANT_SLUG (minuscules, chiffres et tirets)."

    TMS_VERSION="${TMS_VERSION:-latest}"
    TMS_VERSION="${TMS_VERSION#v}"

    local repo_owner=""
    [[ -n "$TMS_GITHUB_REPO" ]] && repo_owner="$(printf '%s' "${TMS_GITHUB_REPO%%/*}" | tr '[:upper:]' '[:lower:]')"
    TMS_DIST_URL="$(release_dist_url "$TMS_VERSION")" || exit 1
    TMS_IMAGE_OWNER="${TMS_IMAGE_OWNER:-$repo_owner}"
    [[ -n "${TMS_IMAGE:-}" || -n "$TMS_IMAGE_OWNER" ]] \
        || die "Image inconnue : définissez TMS_IMAGE_OWNER (image ghcr.io/<propriétaire>/tms) ou TMS_IMAGE."
}

ensure_packages() {
    local missing=() cmd
    for cmd in curl openssl tar; do
        command -v "$cmd" >/dev/null 2>&1 || missing+=("$cmd")
    done
    [[ ${#missing[@]} -eq 0 ]] && return 0
    command -v apt-get >/dev/null 2>&1 || die "Commandes manquantes : ${missing[*]} (apt-get introuvable)."
    log_info "Installation des paquets de base…"
    DEBIAN_FRONTEND=noninteractive apt-get update -q >&2
    DEBIAN_FRONTEND=noninteractive apt-get install -y -q ca-certificates curl openssl tar >&2
}

# Docker Engine + Compose plugin from Docker's official apt repository.
ensure_docker() {
    if ! command -v docker >/dev/null 2>&1; then
        local os_id="" codename=""
        if [[ -r /etc/os-release ]]; then
            # shellcheck disable=SC1091
            os_id="$(. /etc/os-release && printf '%s' "${ID:-}")"
            # shellcheck disable=SC1091
            codename="$(. /etc/os-release && printf '%s' "${VERSION_CODENAME:-}")"
        fi
        [[ "$os_id" == "debian" || "$os_id" == "ubuntu" ]] \
            || die "Système non pris en charge pour l'installation automatique de Docker (Debian 12 ou Ubuntu 24.04 attendu)."
        log_info "Installation de Docker (dépôt officiel)…"
        DEBIAN_FRONTEND=noninteractive apt-get update -q >&2
        DEBIAN_FRONTEND=noninteractive apt-get install -y -q ca-certificates curl gnupg >&2
        install -m 0755 -d /etc/apt/keyrings
        curl -fsSL "https://download.docker.com/linux/$os_id/gpg" -o /etc/apt/keyrings/docker.asc
        chmod a+r /etc/apt/keyrings/docker.asc
        printf 'deb [arch=%s signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/%s %s stable\n' \
            "$(dpkg --print-architecture)" "$os_id" "$codename" >/etc/apt/sources.list.d/docker.list
        DEBIAN_FRONTEND=noninteractive apt-get update -q >&2
        DEBIAN_FRONTEND=noninteractive apt-get install -y -q docker-ce docker-ce-cli containerd.io docker-compose-plugin >&2
        systemctl enable --now docker >&2
    fi
    docker compose version >/dev/null 2>&1 || die "Docker Compose v2 introuvable (paquet docker-compose-plugin)."
}

download() {
    log_info "Téléchargement de $1"
    curl -fsSL --retry 3 -o "$2" "$TMS_DIST_URL/$1" || die "Téléchargement impossible : $TMS_DIST_URL/$1"
}

# Downloads SHA256SUMS and the bundle, verifies it, extracts it into $1.
# Sets TMS_VERSION (resolved when "latest") and BUNDLE_ROOT.
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

install_bundle() {
    mkdir -p "$TMS_DIR"
    chmod 750 "$TMS_DIR"
    cp -R "$BUNDLE_ROOT"/. "$TMS_DIR"/
    chmod 755 "$TMS_DIR"/ops/*.sh
}

# env_value <KEY>: value written by the installer, or nothing to keep the template line.
env_value() {
    case "$1" in
        TMS_IMAGE) printf '%s' "${TMS_IMAGE:-}" ;;
        TMS_IMAGE_OWNER) printf '%s' "$TMS_IMAGE_OWNER" ;;
        TMS_VERSION) printf '%s' "$TMS_VERSION" ;;
        TMS_DOMAIN) printf '%s' "$TMS_DOMAIN" ;;
        TMS_ACME_EMAIL | TMS_ALERT_EMAIL) printf '%s' "$TMS_ADMIN_EMAIL" ;;
        TMS_TLS) printf '%s' "$TMS_TLS" ;;
        TMS_MODE) printf '%s' "$TMS_MODE" ;;
        TMS_DEFAULT_TENANT) printf '%s' "$TMS_TENANT_SLUG" ;;
        TMS_NO_TEST_DB) printf '1' ;;
        APP_KEY) printf '%s' "$GEN_APP_KEY" ;;
        APP_ENV) printf 'production' ;;
        APP_DEBUG) printf 'false' ;;
        APP_URL) printf 'https://%s' "$TMS_DOMAIN" ;;
        SESSION_SECURE_COOKIE) printf 'true' ;;
        POSTGRES_SUPERUSER_PASSWORD) printf '%s' "$GEN_PG_SUPERUSER" ;;
        POSTGRES_OWNER_PASSWORD) printf '%s' "$GEN_PG_OWNER" ;;
        POSTGRES_APP_PASSWORD) printf '%s' "$GEN_PG_APP" ;;
        REDIS_PASSWORD) printf '%s' "$GEN_REDIS" ;;
        RESTIC_PASSWORD) printf '%s' "$GEN_RESTIC" ;;
        RESTIC_REPOSITORY) printf '/var/backups/tms' ;;
        # No instance mail server yet: activation link printed, alerts logged.
        MAIL_MAILER) printf 'log' ;;
    esac
}

INSTALLER_KEYS="TMS_IMAGE TMS_IMAGE_OWNER TMS_VERSION TMS_DOMAIN TMS_ACME_EMAIL TMS_TLS APP_KEY APP_ENV APP_DEBUG APP_URL
SESSION_SECURE_COOKIE TMS_MODE TMS_DEFAULT_TENANT TMS_ALERT_EMAIL TMS_NO_TEST_DB POSTGRES_SUPERUSER_PASSWORD
POSTGRES_OWNER_PASSWORD POSTGRES_APP_PASSWORD REDIS_PASSWORD RESTIC_PASSWORD RESTIC_REPOSITORY MAIL_MAILER"

# Writes $TMS_DIR/.env from .env.prod.example: generated in a private temp file,
# then hard-linked into place (fails if .env appeared meanwhile; never partial).
write_env() {
    GEN_APP_KEY="base64:$(openssl rand -base64 32 | tr -d '\n')"
    GEN_PG_SUPERUSER="$(gen_secret 32)"
    GEN_PG_OWNER="$(gen_secret 32)"
    GEN_PG_APP="$(gen_secret 32)"
    GEN_REDIS="$(gen_secret 32)"
    GEN_RESTIC="$(gen_secret 32)"

    local tmp line key written=" " value
    tmp="$(umask 077 && mktemp "$TMS_DIR/.env.tmp.XXXXXX")"
    ENV_TMP="$tmp"
    {
        while IFS= read -r line || [[ -n "$line" ]]; do
            if [[ "$line" =~ ^([A-Z_][A-Z0-9_]*)= ]]; then
                key="${BASH_REMATCH[1]}"
                value="$(env_value "$key")"
                if [[ -n "$value" ]]; then
                    printf '%s=%s\n' "$key" "$value"
                    written+="$key "
                    continue
                fi
            fi
            printf '%s\n' "$line"
        done <"$TMS_DIR/.env.prod.example"

        printf '\n# --- Written by the installer -------------------------------------------------\n'
        for key in $INSTALLER_KEYS; do
            [[ "$written" == *" $key "* ]] && continue
            value="$(env_value "$key")"
            if [[ -n "$value" ]]; then
                printf '%s=%s\n' "$key" "$value"
            fi
        done
    } >"$tmp"
    chmod 600 "$tmp"

    local secret
    for secret in APP_KEY POSTGRES_SUPERUSER_PASSWORD POSTGRES_OWNER_PASSWORD POSTGRES_APP_PASSWORD REDIS_PASSWORD RESTIC_PASSWORD; do
        grep -Eq "^$secret=.+" "$tmp" || die "Secret $secret non généré."
    done

    ln "$tmp" "$TMS_DIR/.env" 2>/dev/null || die "Installation existante détectée : utilisez ops/update.sh"
    rm -f "$tmp"
    ENV_TMP=""
    ENV_WRITTEN=1
    log_info "Fichier $TMS_DIR/.env généré (mode 600)."
}

start_stack() {
    # shellcheck source=../lib.sh
    source "$TMS_DIR/ops/lib.sh"
    log_info "Téléchargement des images…"
    compose pull >&2
    log_info "Démarrage de PostgreSQL et Redis, migrations de la base…"
    "$TMS_DIR/ops/migrate.sh" >&2
    log_info "Démarrage de l'application…"
    compose up -d --wait >&2
    wait_health 180 || die "L'application ne répond pas : voir « docker compose -f $TMS_DIR/compose.prod.yaml logs »."
}

run_tms_install() {
    local -a args=(--tenant-name="$TMS_TENANT_NAME" --slug="$TMS_TENANT_SLUG" --admin-email="$TMS_ADMIN_EMAIL" --admin-name="$TMS_ADMIN_NAME")
    [[ "$TMS_SEED_CAP" == "1" ]] && args+=(--seed-cap)
    log_info "Création de l'organisme et de l'administrateur…"
    INSTALL_OUTPUT="$(compose exec -T app php artisan tms:install "${args[@]}")" \
        || { printf '%s\n' "${INSTALL_OUTPUT:-}" >&2; die "tms:install a échoué."; }
    printf '%s\n' "$INSTALL_OUTPUT" >&2
    ACTIVATION_URL="$(printf '%s\n' "$INSTALL_OUTPUT" | grep -Eo 'https?://[^[:space:]]+/activation/[^[:space:]]+' | tail -n 1 || true)"
}

install_backup_timer() {
    mkdir -p "$TMS_SYSTEMD_DIR"
    cat >"$TMS_SYSTEMD_DIR/tms-backup.service" <<UNIT
[Unit]
Description=Sauvegarde TMS (restic)
Wants=network-online.target
After=network-online.target docker.service
Requires=docker.service

[Service]
Type=oneshot
ExecStart=$TMS_DIR/ops/backup.sh
Nice=10
IOSchedulingClass=idle
UNIT
    cat >"$TMS_SYSTEMD_DIR/tms-backup.timer" <<UNIT
[Unit]
Description=Sauvegarde quotidienne TMS (02:30)

[Timer]
OnCalendar=*-*-* 02:30:00
Persistent=true

[Install]
WantedBy=timers.target
UNIT
    systemctl daemon-reload >&2
    systemctl enable --now tms-backup.timer >&2
    log_info "Sauvegarde quotidienne programmée (tms-backup.timer, 02:30)."
}

print_summary() {
    cat <<EOF

======================================================================
 TMS $TMS_VERSION installé
======================================================================
 Adresse        : https://$TMS_DOMAIN
 Activation     : ${ACTIVATION_URL:-(voir la sortie de tms:install ci-dessus)}
                  Lien à usage unique, valable 24 h : choisir le mot de passe
                  puis enrôler la double authentification.
 Configuration  : $TMS_DIR/.env (lisible par root uniquement)
 Sauvegardes    : /var/backups/tms, chaque nuit à 02:30 (tms-backup.timer)
EOF
    if [[ "$TMS_TLS" == "internal" ]]; then
        cat <<EOF
 Certificat     : autorité interne de Caddy (TMS_TLS=internal) ; les navigateurs
                  afficheront un avertissement tant que cette autorité n'est pas approuvée.
EOF
    fi
    cat <<EOF

 !!! À CONSERVER HORS DU SERVEUR (gestionnaire de mots de passe, coffre) !!!
 Sans RESTIC_PASSWORD les sauvegardes sont illisibles ; sans APP_KEY les secrets
 des connecteurs et les doubles authentifications sont perdus.
EOF
    if [[ "$TMS_PRINT_SECRETS" == "1" ]]; then
        cat <<EOF
 Ces valeurs ne sont affichées qu'une fois et ne sont écrites dans aucun journal :

   RESTIC_PASSWORD=$GEN_RESTIC
   APP_KEY=$GEN_APP_KEY

 Effacez ensuite l'historique du terminal si la session est enregistrée.
EOF
    else
        cat <<EOF
 Copiez RESTIC_PASSWORD et APP_KEY depuis $TMS_DIR/.env.
EOF
    fi
    cat <<EOF

 Messagerie : renseigner MAIL_* dans $TMS_DIR/.env (MAIL_MAILER=smtp…), puis
   docker compose -f $TMS_DIR/compose.prod.yaml --env-file $TMS_DIR/.env up -d
======================================================================
EOF
}

on_exit() {
    local status=$?
    [[ -n "${ENV_TMP:-}" ]] && rm -f "$ENV_TMP"
    [[ -n "${WORK_DIR:-}" ]] && rm -rf "$WORK_DIR"
    if [[ $status -ne 0 && "${ENV_WRITTEN:-0}" == "1" ]]; then
        log_error "Installation interrompue après la création de $TMS_DIR/.env."
        log_error "Pour recommencer : docker compose -f $TMS_DIR/compose.prod.yaml --env-file $TMS_DIR/.env down -v, puis supprimer $TMS_DIR/.env."
    fi
}

main() {
    [[ -n "${TMS_INSTALL_ENV:-}" ]] && load_settings_file "$TMS_INSTALL_ENV"
    [[ "$(id -u)" -eq 0 ]] || die "Ce script doit être exécuté en root."

    # Refuse before downloading anything; checked again when .env is written.
    [[ ! -e "$TMS_DIR/.env" ]] || die "Installation existante détectée : utilisez ops/update.sh"

    check_settings
    trap on_exit EXIT
    ensure_packages
    ensure_docker

    WORK_DIR="$(mktemp -d)"
    fetch_bundle "$WORK_DIR"
    [[ ! -e "$TMS_DIR/.env" ]] || die "Installation existante détectée : utilisez ops/update.sh"
    install_bundle
    write_env
    start_stack
    run_tms_install
    install_backup_timer
    print_summary
}

if [[ -z "${TMS_INSTALL_NO_MAIN:-}" ]]; then
    main "$@"
fi
