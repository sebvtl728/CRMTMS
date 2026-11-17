#!/usr/bin/env bash
# Import of an export file (ops/export.sh) into this installation, new or not: database,
# storage/app and APP_KEY of the exported server replace the current ones.
#
#   ops/import.sh <fichier>
#
# 1. Checks, before touching anything: the file is a tar holding one restic repository only,
#    the export password opens it, restic check --read-data passes, it holds exactly one
#    snapshot with the APP_KEY and a version not newer than the installed one (TMS_VERSION of
#    .env; otherwise « mettez d'abord à jour en <version> »).
# 2. Safety backup of the current state (ops/backup.sh --tag pre-import).
# 3. ops/restore.sh --with-app-key on the export repository (TMS_RESTORE_REPOSITORY), then
#    ops/migrate.sh (the export may come from an older version; it also runs
#    tms:permissions:sync), organisme by default of single mode taken from the export when the
#    current one does not exist there, /health.
# 4. Control summary on stdout (organismes, utilisateurs, entreprises, personnes, sessions,
#    fichiers), counted as the PostgreSQL superuser (row level security does not apply).
#
# Export password: TMS_IMPORT_PASSWORD, or typed once; it reaches restic through the
# environment only. Refused while another maintenance operation holds the lock.
# Values of .env are read with env_get, never exported: the scripts started here read their
# own .env (restore.sh changes APP_KEY in it).
set -euo pipefail

# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

[[ $# -eq 1 && -n "$1" ]] || { echo "Usage : ops/import.sh <fichier>" >&2; exit 2; }
FILE="$1"
[[ -f "$FILE" && -r "$FILE" ]] || die "Fichier d'export introuvable ou illisible : $FILE"
[[ -f "$TMS_ENV_FILE" ]] || die "Fichier d'environnement introuvable : $TMS_ENV_FILE"

if [[ -n "${TMS_IMPORT_PASSWORD:-}" ]]; then
    IMPORT_PASSWORD="$TMS_IMPORT_PASSWORD"
else
    IMPORT_PASSWORD="$(read_password "Mot de passe de l'export : ")" || die "Mot de passe de l'export non saisi."
fi
unset TMS_IMPORT_PASSWORD
[[ -n "$IMPORT_PASSWORD" ]] || die "Mot de passe de l'export vide."

# Typing done: the lock is not held while waiting for the keyboard.
tms_lock 0

WORK=""
SAFETY=""
PHASE="checking" # checking -> importing -> done
on_exit() {
    local status=$?
    [[ -z "$WORK" ]] || rm -rf "$WORK"
    if [[ "$status" -ne 0 && "$PHASE" == "checking" ]]; then
        log_error "Import refusé : rien n'a été modifié."
    elif [[ "$status" -ne 0 && "$PHASE" == "importing" ]]; then
        log_error "Import interrompu. Pour revenir à l'état d'avant l'import : ops/restore.sh --with-app-key ${SAFETY}"
    fi
}
trap on_exit EXIT

# in_export <command...>: runs restic_run or restic_snapshot_ids against the repository of the
# export file (variables given to that command only, never exported here).
in_export() {
    RESTIC_REPOSITORY="$WORK/repo" RESTIC_PASSWORD="$IMPORT_PASSWORD" RESTIC_FROM_REPOSITORY="" RESTIC_FROM_PASSWORD="" "$@"
}

# psql_super <sql>: one query as the local superuser of the postgres container (socket).
psql_super() {
    compose exec -T postgres psql -U postgres -d tms -tA -v ON_ERROR_STOP=1 -c "$1"
}

# --- 1. Checks (nothing modified) -----------------------------------------------------

log_info "Vérification de ${FILE} (SHA-256 $(sha256_of "$FILE"))."
rc=0
export_archive_check "$FILE" || rc=$?
case "$rc" in
    0) ;;
    1) die "Fichier d'export illisible ou incomplet (archive tar invalide ou tronquée) : $FILE" ;;
    *) die "Ce fichier n'est pas un export TMS (contenu inattendu) : $FILE" ;;
esac
tmp_base="${TMS_BACKUP_TMPDIR:-$(env_get TMS_BACKUP_TMPDIR)}"
WORK="$(umask 077 && mktemp -d "${tmp_base:-/var/tmp}/tms-import.XXXXXX")"
tar -xf "$FILE" -C "$WORK" --no-same-owner 2>/dev/null || die "Extraction du fichier d'export impossible : $FILE"

rc=0
in_export restic_run -- cat config >/dev/null 2>&1 || rc=$?
case "$rc" in
    0) ;;
    12) die "Mot de passe incorrect pour cet export." ;;
    *) die "Fichier d'export illisible (dépôt chiffré introuvable ou endommagé) : $FILE" ;;
esac
log_info "Vérification de l'intégrité de l'export (lecture complète)."
in_export restic_run -- check --read-data >/dev/null 2>&1 || die "Fichier d'export endommagé ou incomplet (vérification restic échouée) : $FILE"
ids="$(in_export restic_snapshot_ids 2>/dev/null)" \
    || die "Fichier d'export illisible : liste des sauvegardes inaccessible."
[[ "$(grep -c . <<<"$ids")" -eq 1 ]] || die "Le fichier d'export doit contenir exactement une sauvegarde."
SNAPSHOT="$ids"

# meta.env (APP_KEY, TMS_VERSION) read into memory only, never written nor displayed.
meta="$(in_export restic_run -- dump "$SNAPSHOT" /backup-data/meta.env 2>/dev/null)" \
    || die "Le fichier d'export ne contient pas la version ni la clé APP_KEY (meta.env absent)."
grep -Eq '^APP_KEY=.+' <<<"$meta" \
    || die "Le fichier d'export ne contient pas la clé APP_KEY : les secrets des connecteurs et les doubles authentifications seraient perdus."
meta_value() { sed -n "s/^$1=//p" <<<"$meta" | head -n 1; }
EXPORT_VERSION="$(meta_value TMS_VERSION)"
# Exports made before 0.8.2 carry no mode: they come from a single installation.
EXPORT_MODE="$(meta_value TMS_MODE)"
EXPORT_MODE="${EXPORT_MODE:-single}"
EXPORT_TENANTS="$(meta_value TMS_TENANTS)"
unset meta
INSTALLED_VERSION="$(env_get TMS_VERSION)"
[[ -n "$EXPORT_VERSION" ]] || die "Version de l'export inconnue (TMS_VERSION absent de l'export)."
[[ -n "$INSTALLED_VERSION" ]] || die "Version installée inconnue (TMS_VERSION absent de ${TMS_ENV_FILE})."
if version_lt "$INSTALLED_VERSION" "$EXPORT_VERSION"; then
    die "Cet export vient de la version ${EXPORT_VERSION}, plus récente que la version installée (${INSTALLED_VERSION}) : mettez d'abord à jour en ${EXPORT_VERSION} (ops/update.sh --version ${EXPORT_VERSION}), puis relancez l'import."
fi
INSTALLED_MODE="$(env_get TMS_MODE)"
INSTALLED_MODE="${INSTALLED_MODE:-single}"
if [[ "$EXPORT_MODE" == "multi" && "$INSTALLED_MODE" != "multi" ]]; then
    [[ "$EXPORT_TENANTS" =~ ^[0-9]+$ && "$EXPORT_TENANTS" -le 1 ]] \
        || die "Cet export contient plusieurs organismes : installez en mode multi-organismes."
    log_error "Attention : cet export vient d'une installation multi-organismes et ce serveur est en mode mono-organisme ; son unique organisme deviendra l'organisme par défaut."
elif [[ "$EXPORT_MODE" != "multi" && "$INSTALLED_MODE" == "multi" ]]; then
    log_error "Attention : cet export vient d'une installation mono-organisme et ce serveur est en mode multi-organismes : après l'import, associez un nom de domaine à l'organisme (sinon il reste inaccessible)."
fi
log_info "Export valide : version ${EXPORT_VERSION} (installée : ${INSTALLED_VERSION})."

# --- 2. Safety backup --------------------------------------------------------------------

log_info "Sauvegarde de sécurité de l'état actuel (étiquette pre-import)."
SAFETY="$("$TMS_OPS_DIR/backup.sh" --tag pre-import | tail -n 1)" || die "Sauvegarde de sécurité impossible : import annulé."
[[ "$SAFETY" =~ ^[0-9a-f]{8,64}$ ]] || die "Identifiant de la sauvegarde de sécurité introuvable : import annulé."
log_info "Sauvegarde de sécurité : ${SAFETY}."

# --- 3. Restore, migrations, organisme by default -----------------------------------------

PHASE="importing"
log_info "Restauration de l'export."
TMS_RESTORE_REPOSITORY="$WORK/repo" TMS_RESTORE_PASSWORD="$IMPORT_PASSWORD" \
    "$TMS_OPS_DIR/restore.sh" --with-app-key "$SNAPSHOT" >&2 || die "Échec de la restauration de l'export."
unset IMPORT_PASSWORD
rm -rf "$WORK"
WORK=""

log_info "Migrations de la base (export d'une version antérieure) et droits par défaut."
"$TMS_OPS_DIR/migrate.sh" >&2 || die "Échec des migrations après l'import."

# Single mode: the application shows the organisme TMS_DEFAULT_TENANT. When it is not among the
# imported ones, the only imported organisme becomes the default one.
if [[ "$(env_get TMS_MODE)" != "multi" ]]; then
    current="$(env_get TMS_DEFAULT_TENANT)"
    current="${current:-default}"
    slugs="$(psql_super "select slug from tenants order by slug")" || die "Lecture des organismes importés impossible."
    if ! grep -qxF -- "$current" <<<"$slugs"; then
        [[ "$(grep -c . <<<"$slugs")" -eq 1 ]] \
            || die "Organisme par défaut « ${current} » absent de l'export : renseignez TMS_DEFAULT_TENANT dans ${TMS_ENV_FILE} (parmi : $(printf '%s' "$slugs" | tr '\n' ' ')), puis relancez « docker compose up -d »."
        log_info "Organisme par défaut : ${slugs} (TMS_DEFAULT_TENANT, valeur précédente : ${current} ; à remettre en cas de retour à l'état d'avant l'import)."
        env_set TMS_DEFAULT_TENANT "$slugs" || die "Écriture de TMS_DEFAULT_TENANT dans ${TMS_ENV_FILE} impossible."
    fi
fi

compose up -d --wait >&2 || die "Redémarrage des services impossible."
compose exec -T app php artisan cache:clear >/dev/null 2>&1 || log_info "Cache applicatif non vidé (non bloquant)."
wait_health 120 || die "L'application ne répond pas après l'import."
PHASE="done"

# --- 4. Control summary -----------------------------------------------------------------

counts="$(psql_super "select (select count(*) from tenants), (select count(*) from users), (select count(*) from organisations), (select count(*) from people), (select count(*) from training_sessions)")" \
    || die "Lecture du récapitulatif impossible."
files="$(compose exec -T app sh -c 'find storage/app -type f | wc -l' | tr -d '[:space:]')" || files="?"
IFS='|' read -r n_tenants n_users n_organisations n_people n_sessions <<<"$counts"

log_info "Import terminé."
cat <<EOF
Import de ${FILE##*/} terminé (version ${EXPORT_VERSION}). Récapitulatif de contrôle :
  Organismes   : ${n_tenants}
  Utilisateurs : ${n_users}
  Entreprises  : ${n_organisations}
  Personnes    : ${n_people}
  Sessions     : ${n_sessions}
  Fichiers     : ${files}
Comptes : ceux du serveur exporté (mêmes identifiants, même double authentification).
État d'avant l'import : sauvegarde ${SAFETY} (ops/restore.sh --with-app-key ${SAFETY}).
EOF
