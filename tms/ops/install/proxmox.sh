#!/usr/bin/env bash
# TMS on Proxmox VE: creates an unprivileged Debian 12 LXC container (nesting,
# keyctl: needed by Docker) and runs install.sh inside it.
#
#   bash -c "$(curl -fsSL <release>/proxmox.sh)"                       # questions (whiptail)
#   TMS_NONINTERACTIVE=1 TMS_DOMAIN=… TMS_ADMIN_EMAIL=… TMS_TENANT_NAME=… bash proxmox.sh
#   proxmox.sh update <CTID> [--version X.Y.Z] [--force] [--allow-downgrade]
#       pct snapshot pre-update-<date> (3 most recent kept), then ops/update.sh in the CT;
#       its exit code is returned (1: update rolled back, 2: rollback failed).
#
# Settings (environment; asked interactively unless TMS_NONINTERACTIVE=1):
#   CTID (next free id), CT_HOSTNAME=tms, CT_CORES=2, CT_RAM=4096 (Mo), CT_DISK=20 (Go),
#   CT_STORAGE (first active storage for containers), CT_BRIDGE=vmbr0,
#   CT_IP=dhcp or CIDR (then CT_GW), TEMPLATE_STORAGE=local,
#   TMS_DOMAIN, TMS_ADMIN_EMAIL, TMS_TENANT_NAME (required), TMS_VERSION=latest,
#   plus the install.sh settings passed through (TMS_ADMIN_NAME, TMS_TLS, TMS_SEED_CAP…).
#
# Self-contained (fetched alone with curl). No secret is handled here: install.sh
# generates them inside the container. Settings reach the container in a file
# pushed with `pct push` (mode 600), never on a command line.
set -euo pipefail

# GitHub repository publishing the releases ("owner/repo"); see install.sh.
TMS_GITHUB_REPO="${TMS_GITHUB_REPO:-sebvtl728/CRMTMS}"

PASSTHROUGH_KEYS="TMS_DOMAIN TMS_ADMIN_EMAIL TMS_ADMIN_NAME TMS_TENANT_NAME TMS_TENANT_SLUG TMS_VERSION TMS_DIST_URL
TMS_IMAGE TMS_IMAGE_OWNER TMS_SEED_CAP TMS_TLS TMS_MODE TMS_PRINT_SECRETS"

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

# Directory of the installation inside the container (install.sh default).
CT_TMS_DIR="${CT_TMS_DIR:-/opt/tms}"
# Number of pre-update-* snapshots kept per container.
KEEP_SNAPSHOTS=3

# prune_snapshots <CTID>: deletes all but the KEEP_SNAPSHOTS most recent pre-update-* snapshots
# (names sort chronologically); other snapshots are never touched.
prune_snapshots() {
    local ctid="$1" name
    local -a names=()
    while read -r name; do
        [[ -n "$name" ]] && names+=("$name")
    done < <(pct listsnapshot "$ctid" 2>/dev/null | grep -Eo 'pre-update-[0-9]{8}-[0-9]{6}' | sort -u)
    local excess=$((${#names[@]} - KEEP_SNAPSHOTS)) i
    for ((i = 0; i < excess; i++)); do
        log_info "Suppression de l'ancien instantané ${names[$i]}."
        pct delsnapshot "$ctid" "${names[$i]}" >&2 || log_error "Impossible de supprimer l'instantané ${names[$i]} (non bloquant)."
    done
}

cmd_update() {
    local ctid="${1:-}"
    [[ "$ctid" =~ ^[0-9]+$ ]] || die "Usage : proxmox.sh update <CTID> [--version X.Y.Z] [--force] [--allow-downgrade] (CTID numérique)."
    shift

    local snap
    snap="pre-update-$(date '+%Y%m%d-%H%M%S')"
    log_info "Instantané $snap du conteneur $ctid…"
    if pct snapshot "$ctid" "$snap" --description "Avant mise à jour TMS" >&2; then
        prune_snapshots "$ctid"
    else
        log_error "Attention : instantané impossible (le stockage du conteneur ne gère peut-être pas les instantanés). La mise à jour continue, protégée par la sauvegarde restic qu'elle réalise."
        snap=""
    fi

    local -a env_args=(TMS_PCT_SNAPSHOT="${snap:-none}")
    [[ -z "${TMS_DIST_URL:-}" ]] || env_args+=(TMS_DIST_URL="$TMS_DIST_URL")
    log_info "Mise à jour dans le conteneur $ctid…"
    local status=0
    pct exec "$ctid" -- env "${env_args[@]}" "$CT_TMS_DIR/ops/update.sh" "$@" || status=$?

    if [[ "$status" -ne 0 ]]; then
        if [[ -n "$snap" ]]; then
            log_error "La mise à jour a échoué (code $status). En dernier recours, l'état complet du conteneur d'avant la mise à jour peut être rétabli : pct rollback $ctid $snap (puis pct start $ctid)."
        else
            log_error "La mise à jour a échoué (code $status). Aucun instantané n'a pu être pris : voir les messages ci-dessus pour la restauration restic."
        fi
    fi
    exit "$status"
}

# ask <VAR> <question>: whiptail input box prefilled with the current value.
ask() {
    local var="$1" question="$2" answer
    answer="$(whiptail --title "Installation TMS" --inputbox "$question" 10 72 "${!var:-}" 3>&1 1>&2 2>&3)" \
        || die "Installation annulée."
    printf -v "$var" '%s' "$answer"
}

ask_storage() {
    local -a items=()
    local name
    while read -r name; do
        items+=("$name" "")
    done < <(pvesm status -content rootdir 2>/dev/null | awk 'NR > 1 && $3 == "active" { print $1 }')
    [[ ${#items[@]} -gt 0 ]] || die "Aucun stockage actif pour les conteneurs (pvesm status -content rootdir)."
    CT_STORAGE="$(whiptail --title "Installation TMS" --menu "Stockage du disque du conteneur" 15 60 6 "${items[@]}" 3>&1 1>&2 2>&3)" \
        || die "Installation annulée."
}

collect_settings() {
    CTID="${CTID:-$(pvesh get /cluster/nextid)}"
    CT_HOSTNAME="${CT_HOSTNAME:-tms}"
    CT_CORES="${CT_CORES:-2}"
    CT_RAM="${CT_RAM:-4096}"
    CT_DISK="${CT_DISK:-20}"
    CT_BRIDGE="${CT_BRIDGE:-vmbr0}"
    CT_IP="${CT_IP:-dhcp}"
    CT_GW="${CT_GW:-}"
    TEMPLATE_STORAGE="${TEMPLATE_STORAGE:-local}"
    TMS_VERSION="${TMS_VERSION:-latest}"

    if [[ "${TMS_NONINTERACTIVE:-0}" != "1" ]]; then
        command -v whiptail >/dev/null 2>&1 || die "whiptail introuvable : relancez avec TMS_NONINTERACTIVE=1 et les variables d'environnement."
        ask TMS_TENANT_NAME "Nom de l'organisme (ex. CAP FORMATION)"
        ask TMS_DOMAIN "Nom de domaine de l'application (ex. tms.example.org)"
        ask TMS_ADMIN_EMAIL "E-mail de l'administrateur"
        ask CTID "Identifiant du conteneur (CTID)"
        ask CT_HOSTNAME "Nom d'hôte du conteneur"
        ask CT_CORES "Nombre de cœurs"
        ask CT_RAM "Mémoire (Mo)"
        ask CT_DISK "Taille du disque (Go)"
        [[ -n "${CT_STORAGE:-}" ]] || ask_storage
        ask CT_BRIDGE "Pont réseau"
        ask CT_IP "Adresse IP : dhcp ou CIDR (ex. 192.168.1.50/24)"
        if [[ "$CT_IP" != "dhcp" ]]; then
            ask CT_GW "Passerelle (ex. 192.168.1.1)"
        fi
        ask TMS_VERSION "Version à installer (latest = dernière)"
    fi

    CT_STORAGE="${CT_STORAGE:-$(pvesm status -content rootdir 2>/dev/null | awk 'NR > 1 && $3 == "active" { print $1; exit }')}"

    local var
    for var in TMS_DOMAIN TMS_ADMIN_EMAIL TMS_TENANT_NAME; do
        [[ -n "${!var:-}" ]] || die "Variable $var manquante (mode non interactif : TMS_NONINTERACTIVE=1 $var=…)."
    done
    [[ -n "$CT_STORAGE" ]] || die "Variable CT_STORAGE manquante : aucun stockage actif pour les conteneurs."
    [[ "$CTID" =~ ^[0-9]+$ ]] || die "CTID invalide : $CTID"
    for var in CT_CORES CT_RAM CT_DISK; do
        [[ "${!var}" =~ ^[0-9]+$ ]] || die "$var invalide : ${!var} (nombre attendu)."
    done
    [[ "$CT_HOSTNAME" =~ ^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?$ ]] || die "CT_HOSTNAME invalide : $CT_HOSTNAME"
    if [[ "$CT_IP" != "dhcp" ]]; then
        [[ "$CT_IP" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}/[0-9]{1,2}$ ]] || die "CT_IP invalide : $CT_IP (dhcp ou CIDR, ex. 192.168.1.50/24)."
        [[ -n "$CT_GW" ]] || die "Variable CT_GW manquante : une adresse fixe (CT_IP) demande une passerelle."
        [[ "$CT_GW" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || die "CT_GW invalide : $CT_GW"
    fi
    for var in $PASSTHROUGH_KEYS; do
        [[ "${!var:-}" != *$'\n'* ]] || die "$var ne doit pas contenir de retour à la ligne."
    done

    TMS_VERSION="${TMS_VERSION#v}"
    local repo_owner=""
    [[ -n "$TMS_GITHUB_REPO" ]] && repo_owner="$(printf '%s' "${TMS_GITHUB_REPO%%/*}" | tr '[:upper:]' '[:lower:]')"
    TMS_DIST_URL="$(release_dist_url "$TMS_VERSION")" || exit 1
    TMS_IMAGE_OWNER="${TMS_IMAGE_OWNER:-$repo_owner}"
}

# Downloads install.sh and checks it against the release SHA256SUMS.
fetch_installer() {
    local sums="$WORK_DIR/SHA256SUMS" expected
    log_info "Téléchargement de l'installateur ($TMS_DIST_URL)"
    curl -fsSL --retry 3 -o "$sums" "$TMS_DIST_URL/SHA256SUMS" || die "Téléchargement impossible : $TMS_DIST_URL/SHA256SUMS"
    curl -fsSL --retry 3 -o "$WORK_DIR/install.sh" "$TMS_DIST_URL/install.sh" || die "Téléchargement impossible : $TMS_DIST_URL/install.sh"
    expected="$(checksum_of "$sums" install.sh)"
    [[ -n "$expected" ]] || die "install.sh absent de SHA256SUMS."
    verify_checksum "$WORK_DIR/install.sh" "$expected" || die "Installateur refusé : somme de contrôle invalide."
}

ensure_template() {
    log_info "Recherche du modèle Debian 12…"
    pveam update >&2 || log_error "pveam update a échoué : liste des modèles peut-être ancienne."
    TEMPLATE="$(pveam available --section system | awk '{ print $2 }' | grep -E '^debian-12-standard_.*\.tar\.(zst|gz|xz)$' | sort -V | tail -n 1 || true)"
    [[ -n "$TEMPLATE" ]] || die "Modèle Debian 12 introuvable (pveam available --section system)."
    if ! pveam list "$TEMPLATE_STORAGE" | grep -q "$TEMPLATE"; then
        log_info "Téléchargement du modèle $TEMPLATE sur $TEMPLATE_STORAGE…"
        pveam download "$TEMPLATE_STORAGE" "$TEMPLATE" >&2
    fi
}

create_container() {
    local net0="name=eth0,bridge=$CT_BRIDGE,ip=$CT_IP"
    [[ "$CT_IP" != "dhcp" ]] && net0+=",gw=$CT_GW"
    log_info "Création du conteneur $CTID ($CT_HOSTNAME)…"
    pct create "$CTID" "$TEMPLATE_STORAGE:vztmpl/$TEMPLATE" \
        --hostname "$CT_HOSTNAME" \
        --cores "$CT_CORES" \
        --memory "$CT_RAM" \
        --swap 512 \
        --rootfs "$CT_STORAGE:$CT_DISK" \
        --net0 "$net0" \
        --ostype debian \
        --unprivileged 1 \
        --features nesting=1,keyctl=1 \
        --onboot 1 >&2
    pct start "$CTID" >&2

    log_info "Attente du réseau dans le conteneur…"
    local tries=0
    until pct exec "$CTID" -- sh -c 'getent hosts deb.debian.org >/dev/null 2>&1'; do
        tries=$((tries + 1))
        ((tries < 60)) || die "Pas d'accès réseau dans le conteneur $CTID après 120 s (DNS, passerelle, pont $CT_BRIDGE ?)."
        sleep 2
    done
}

run_installer() {
    local settings="$WORK_DIR/tms-install.env" key
    (
        umask 077
        for key in $PASSTHROUGH_KEYS; do
            if [[ -n "${!key:-}" ]]; then
                printf '%s=%s\n' "$key" "${!key}"
            fi
        done
    ) >"$settings"

    pct push "$CTID" "$WORK_DIR/install.sh" /root/tms-install.sh --perms 700 >&2
    pct push "$CTID" "$settings" /root/tms-install.env --perms 600 >&2
    log_info "Installation dans le conteneur $CTID…"
    if ! pct exec "$CTID" -- env TMS_INSTALL_ENV=/root/tms-install.env bash /root/tms-install.sh; then
        pct exec "$CTID" -- rm -f /root/tms-install.env >&2 || true
        die "L'installation a échoué dans le conteneur $CTID. Pour recommencer : pct stop $CTID && pct destroy $CTID, puis relancer ce script."
    fi
    pct exec "$CTID" -- rm -f /root/tms-install.env /root/tms-install.sh >&2 || true
}

cmd_install() {
    collect_settings
    WORK_DIR="$(mktemp -d)"
    trap 'rm -rf "$WORK_DIR"' EXIT
    fetch_installer
    ensure_template
    create_container
    run_installer

    local ip
    ip="$(pct exec "$CTID" -- hostname -I 2>/dev/null | awk '{ print $1 }' || true)"
    log_info "Conteneur $CTID prêt${ip:+ (adresse $ip)}. Le nom $TMS_DOMAIN doit pointer vers cette adresse (ports 80 et 443)."
}

main() {
    command -v pveversion >/dev/null 2>&1 \
        || die "Proxmox VE introuvable : lancez ce script sur un nœud Proxmox (commande pveversion absente). Sur Debian/Ubuntu, utilisez install.sh."
    [[ "$(id -u)" -eq 0 ]] || die "Ce script doit être exécuté en root."
    log_info "$(pveversion | head -n 1)"

    case "${1:-install}" in
        install) cmd_install ;;
        update)
            shift
            cmd_update "$@"
            ;;
        *) die "Commande inconnue : $1 (install ou update <CTID>)." ;;
    esac
}

if [[ -z "${TMS_PROXMOX_NO_MAIN:-}" ]]; then
    main "$@"
fi
