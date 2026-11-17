#!/usr/bin/env bats
# Installer tests (ops/install/install.sh, ops/install/proxmox.sh).
# No Proxmox, no Docker, no network: pct, pveversion, pvesh, pvesm, pveam, curl,
# docker, systemctl and `id -u` are replaced by mocks on PATH that log their
# arguments. curl serves files from a fake release directory built from the
# real bundle files (compose.prod.yaml, .env.prod.example, ops/, docker/...).

TMS_ROOT="$(cd "$BATS_TEST_DIRNAME/../.." && pwd)"
INSTALL_SH="$TMS_ROOT/ops/install/install.sh"
PROXMOX_SH="$TMS_ROOT/ops/install/proxmox.sh"
FIXTURE_VERSION="9.9.9"
FIXTURE_DIGEST="sha256:$(printf 'b%.0s' {1..64})"
# shellcheck source=bundle.bash
source "$BATS_TEST_DIRNAME/bundle.bash"

setup() {
    MOCK_BIN="$BATS_TEST_TMPDIR/bin"
    MOCK_LOG="$BATS_TEST_TMPDIR/log"
    MOCK_DIST="$BATS_TEST_TMPDIR/dist"
    mkdir -p "$MOCK_BIN" "$MOCK_LOG/push" "$MOCK_DIST"
    export MOCK_LOG MOCK_DIST

    build_release
    write_mocks

    export PATH="$MOCK_BIN:$PATH"
    export TMS_DIR="$BATS_TEST_TMPDIR/opt/tms"
    export TMS_SYSTEMD_DIR="$BATS_TEST_TMPDIR/systemd"
    export TMS_DIST_URL="https://dist.example.test/releases/latest/download"
    export TMS_IMAGE_OWNER="example"
    export TMS_DOMAIN="tms.example.test"
    export TMS_ADMIN_EMAIL="admin@example.test"
    export TMS_ADMIN_NAME="Admin Test"
    export TMS_TENANT_NAME="CAP FORMATION"
    unset TMS_VERSION TMS_SEED_CAP TMS_TLS TMS_PRINT_SECRETS TMS_NONINTERACTIVE CTID CT_IP CT_GW
}

# Fake release built by the real make-bundle.sh: tms-<version>.tar.gz, install.sh, proxmox.sh, SHA256SUMS.
build_release() {
    local stage="$BATS_TEST_TMPDIR/stage"
    stage_src "$stage"
    echo "BATS_GENERATED_SECRET=__GENERATE__" >>"$stage/.env.prod.example"
    bundle_src "$stage" "$FIXTURE_VERSION" "$MOCK_DIST" "$FIXTURE_DIGEST"
}

mock() {
    printf '#!/usr/bin/env bash\n%s\n' "$2" >"$MOCK_BIN/$1"
    chmod +x "$MOCK_BIN/$1"
}

write_mocks() {
    # shellcheck disable=SC2016  # bodies are expanded by the mocks themselves
    {
        mock curl '
echo "curl $*" >>"$MOCK_LOG/curl.log"
out=""; url=""; prev=""
for a in "$@"; do
  [ "$prev" = "-o" ] && out="$a"
  case "$a" in http://*|https://*) url="$a" ;; esac
  prev="$a"
done
src="$MOCK_DIST/${url##*/}"
[ -f "$src" ] || exit 22
if [ -n "$out" ]; then cp "$src" "$out"; else cat "$src"; fi'

        mock docker '
echo "docker $*" >>"$MOCK_LOG/docker.log"
case "$*" in
  *"tms:install"*)
    echo "Organisme installé."
    echo "Lien d activation de l administrateur (valable 24 h, usage unique) :"
    echo "https://tms.example.test/activation/token123" ;;
  *" config") echo "name: tms" ;;
  "volume ls -q --filter label=com.docker.compose.project=tms --filter label=com.docker.compose.volume=storage") echo "tms_storage" ;;
  "volume inspect -f {{.Mountpoint}} tms_storage") echo "/var/lib/docker/volumes/tms_storage/_data" ;;
esac
exit 0'

        mock systemctl 'echo "systemctl $*" >>"$MOCK_LOG/systemctl.log"'

        mock id '
if [ "$1" = "-u" ]; then echo 0; else /usr/bin/id "$@"; fi'

        mock pveversion 'echo "pve-manager/8.2.4/faa83925c9641325 (running kernel: 6.8.12-1-pve)"'

        mock pvesh '
echo "pvesh $*" >>"$MOCK_LOG/pvesh.log"
[ "$*" = "get /cluster/nextid" ] && echo 123'

        mock pvesm '
echo "Name             Type     Status           Total            Used       Available        %"
echo "local-lvm     lvmthin     active        98000000        1000000        97000000    1.02%"'

        mock pveam '
echo "pveam $*" >>"$MOCK_LOG/pveam.log"
case "$1" in
  available) echo "system          debian-11-standard_11.7-1_amd64.tar.zst"
             echo "system          debian-12-standard_12.7-1_amd64.tar.zst" ;;
  list) echo "NAME                                                SIZE" ;;
esac
exit 0'

        # Snapshots live in $MOCK_LOG/snapshots (one name per line, oldest first).
        mock pct '
echo "pct $*" >>"$MOCK_LOG/pct.log"
case "$1" in
  push) cp "$3" "$MOCK_LOG/push/${4##*/}" ;;
  snapshot)
    if [ -n "${MOCK_PCT_SNAPSHOT_FAIL:-}" ]; then echo "snapshot feature is not available" >&2; exit 1; fi
    echo "$3" >>"$MOCK_LOG/snapshots" ;;
  listsnapshot)
    if [ -f "$MOCK_LOG/snapshots" ]; then
      while read -r s; do printf "%s-> %s   2026-11-01 10:00:00   no-description\n" "\`" "$s"; done <"$MOCK_LOG/snapshots"
    fi
    printf " %s-> current   You are here!\n" "\`" ;;
  delsnapshot)
    grep -vx "$3" "$MOCK_LOG/snapshots" >"$MOCK_LOG/snapshots.tmp" || true
    mv "$MOCK_LOG/snapshots.tmp" "$MOCK_LOG/snapshots" ;;
  exec)
    case "$*" in *ops/update.sh*) exit "${MOCK_PCT_EXEC_RC:-0}" ;; esac ;;
esac
exit 0'
    }
}

# `[[ ]]` checks end with `|| false`: bash 3.2 does not apply errexit to a failing `[[ ]]`.
# refute <cmd...>: fails when the command succeeds (a bare `! cmd` never fails a bats test).
refute() {
    if "$@"; then return 1; fi
}

file_mode() {
    stat -c %a "$1" 2>/dev/null || stat -f %Lp "$1"
}

env_value() {
    grep -E "^$1=" "$TMS_DIR/.env" | head -n 1 | cut -d= -f2-
}

# --- install.sh ----------------------------------------------------------------

@test "install refuses to overwrite existing env" {
    mkdir -p "$TMS_DIR"
    touch "$TMS_DIR/.env"
    run bash "$INSTALL_SH"
    [ "$status" -ne 0 ]
    [[ "$output" == *"Installation existante détectée : utilisez ops/update.sh"* ]] || false
    [ ! -s "$TMS_DIR/.env" ]
    [ ! -f "$MOCK_LOG/curl.log" ]
}

@test "verify_checksum rejects a wrong hash" {
    printf 'contenu' >"$BATS_TEST_TMPDIR/f"
    # shellcheck source=../install/install.sh
    TMS_INSTALL_NO_MAIN=1 source "$INSTALL_SH"
    run verify_checksum "$BATS_TEST_TMPDIR/f" "0000000000000000000000000000000000000000000000000000000000000000"
    [ "$status" -eq 1 ]
    [[ "$output" == *"Somme de contrôle invalide"* ]] || false
}

@test "checksum mismatch aborts before anything is installed" {
    echo "corrompu" >>"$MOCK_DIST/tms-$FIXTURE_VERSION.tar.gz"
    run bash "$INSTALL_SH"
    [ "$status" -ne 0 ]
    [[ "$output" == *"Somme de contrôle invalide"* ]] || false
    [ ! -f "$TMS_DIR/.env" ]
    [ ! -f "$TMS_DIR/compose.prod.yaml" ]
    [ ! -f "$MOCK_LOG/docker.log" ] || refute grep -Eq "compose .*( pull| up )" "$MOCK_LOG/docker.log"
}

@test "without TMS_DIST_URL, install downloads from the GitHub release of TMS_GITHUB_REPO" {
    unset TMS_DIST_URL
    run bash "$INSTALL_SH"
    [ "$status" -eq 0 ]
    grep -q "https://github.com/sebvtl728/CRMTMS/releases/latest/download/SHA256SUMS" "$MOCK_LOG/curl.log"
}

@test "install requires domain, admin e-mail and organisme name" {
    unset TMS_DOMAIN
    run bash "$INSTALL_SH"
    [ "$status" -ne 0 ]
    [[ "$output" == *"TMS_DOMAIN"* ]] || false
}

@test "generated env has no empty secret" {
    run bash "$INSTALL_SH"
    [ "$status" -eq 0 ]
    refute grep -E '^(APP_KEY|POSTGRES_SUPERUSER_PASSWORD|POSTGRES_OWNER_PASSWORD|POSTGRES_APP_PASSWORD|REDIS_PASSWORD|RESTIC_PASSWORD)=$' "$TMS_DIR/.env"
    for key in APP_KEY POSTGRES_SUPERUSER_PASSWORD POSTGRES_OWNER_PASSWORD POSTGRES_APP_PASSWORD REDIS_PASSWORD RESTIC_PASSWORD; do
        [ -n "$(env_value "$key")" ]
    done
    # APP_KEY: base64 of exactly 32 bytes (AES-256 key for Laravel)
    local key
    key="$(env_value APP_KEY)"
    [[ "$key" == base64:* ]] || false
    [ "$(printf '%s' "${key#base64:}" | openssl base64 -d -A | wc -c | tr -d ' ')" -eq 32 ]
    # all passwords differ
    [ "$(grep -E '^(POSTGRES_SUPERUSER_PASSWORD|POSTGRES_OWNER_PASSWORD|POSTGRES_APP_PASSWORD|REDIS_PASSWORD|RESTIC_PASSWORD)=' "$TMS_DIR/.env" | cut -d= -f2- | sort -u | wc -l | tr -d ' ')" -eq 5 ]
}

@test "KEY=__GENERATE__ in the template gets a random secret" {
    run bash "$INSTALL_SH"
    [ "$status" -eq 0 ]
    local value
    value="$(env_value BATS_GENERATED_SECRET)"
    [ "${#value}" -ge 40 ]
    [ "$value" != "__GENERATE__" ]
}

@test "env file is mode 600" {
    run bash "$INSTALL_SH"
    [ "$status" -eq 0 ]
    [ "$(file_mode "$TMS_DIR/.env")" = "600" ]
    [ -z "$(find "$TMS_DIR" -maxdepth 1 -name '.env.*' ! -name '.env.prod.example')" ]
}

@test "generated env carries production settings and resolves latest version" {
    run bash "$INSTALL_SH"
    [ "$status" -eq 0 ]
    [ "$(env_value TMS_VERSION)" = "$FIXTURE_VERSION" ]
    # The image is pulled by the digest published with the verified bundle.
    [ "$(env_value TMS_IMAGE_DIGEST)" = "$FIXTURE_DIGEST" ]
    [ "$(env_value APP_ENV)" = "production" ]
    [ "$(env_value APP_DEBUG)" = "false" ]
    [ "$(env_value SESSION_SECURE_COOKIE)" = "true" ]
    [ "$(env_value APP_URL)" = "https://tms.example.test" ]
    [ "$(env_value TMS_DOMAIN)" = "tms.example.test" ]
    [ "$(env_value TMS_MODE)" = "single" ]
    [ "$(env_value TMS_DEFAULT_TENANT)" = "cap-formation" ]
    [ "$(env_value TMS_NO_TEST_DB)" = "1" ]
    [ "$(env_value TMS_ALERT_EMAIL)" = "admin@example.test" ]
    [ "$(env_value TMS_ACME_EMAIL)" = "admin@example.test" ]
    [ "$(env_value TMS_TLS)" = "acme" ]
    [ "$(env_value TMS_IMAGE_OWNER)" = "example" ]
    # comments of the template are kept
    grep -q "^# --- Backups" "$TMS_DIR/.env"
}

@test "install runs pull, migrations, start, tms:install and the backup timer" {
    run bash "$INSTALL_SH"
    [ "$status" -eq 0 ]
    local log="$MOCK_LOG/docker.log"
    grep -q "compose .* pull" "$log"
    grep -q "compose .* up -d --wait postgres redis" "$log"
    grep -q "migrate --database=pgsql_owner --force" "$log"
    grep -q "compose .* up -d --wait$" "$log"
    grep -q "tms:install --tenant-name=CAP FORMATION --slug=cap-formation --admin-email=admin@example.test --admin-name=Admin Test --seed-cap" "$log"
    # order: pull < migrate < full start < tms:install
    [ "$(grep -n ' pull' "$log" | head -1 | cut -d: -f1)" -lt "$(grep -n 'migrate --database' "$log" | cut -d: -f1)" ]
    [ "$(grep -n 'migrate --database' "$log" | cut -d: -f1)" -lt "$(grep -n 'tms:install' "$log" | cut -d: -f1)" ]
    # permission sync right after the migrations, without the owner credentials
    grep -q "run --rm --no-deps -T app php artisan tms:permissions:sync" "$log"
    [ "$(grep -n 'migrate --database' "$log" | cut -d: -f1)" -lt "$(grep -n 'tms:permissions:sync' "$log" | cut -d: -f1)" ]
    [ -z "$(grep 'tms:permissions:sync' "$log" | grep DB_OWNER)" ]

    grep -q "OnCalendar=\*-\*-\* 02:30:00" "$TMS_SYSTEMD_DIR/tms-backup.timer"
    grep -q "Persistent=true" "$TMS_SYSTEMD_DIR/tms-backup.timer"
    grep -q "ExecStart=$TMS_DIR/ops/backup.sh" "$TMS_SYSTEMD_DIR/tms-backup.service"
    grep -q "enable --now tms-backup.timer" "$MOCK_LOG/systemctl.log"
    # « Sauvegarder maintenant »: storage/ops in the volume, request file watched on the host.
    grep -q "run --rm -v tms_storage:/s --entrypoint sh restic/restic:" "$log"
    grep -qx "mkdir -p /s/ops" "$log"
    grep -qx "PathExists=/var/lib/docker/volumes/tms_storage/_data/ops/backup-request.json" "$TMS_SYSTEMD_DIR/tms-backup-request.path"
    grep -qx "Unit=tms-backup-request.service" "$TMS_SYSTEMD_DIR/tms-backup-request.path"
    grep -qx "ExecStartPre=-/usr/bin/find /var/lib/docker/volumes/tms_storage/_data/ops -maxdepth 1 -name backup-request.json -type f -delete" "$TMS_SYSTEMD_DIR/tms-backup-request.service"
    grep -qx "ExecStart=$TMS_DIR/ops/backup.sh --tag manuel" "$TMS_SYSTEMD_DIR/tms-backup-request.service"
    grep -q "enable tms-backup-request.path" "$MOCK_LOG/systemctl.log"
    grep -q "restart tms-backup-request.path" "$MOCK_LOG/systemctl.log"
    [[ "$output" != *"Sauvegarder maintenant"* ]] || false

    [[ "$output" == *"https://tms.example.test/activation/token123"* ]] || false
    [[ "$output" == *"https://tms.example.test"* ]] || false
    [[ "$output" == *"RESTIC_PASSWORD"* ]] || false
    [[ "$output" == *"APP_KEY"* ]] || false
}

@test "secrets never appear on docker or curl command lines" {
    run bash "$INSTALL_SH"
    [ "$status" -eq 0 ]
    for key in APP_KEY POSTGRES_SUPERUSER_PASSWORD POSTGRES_OWNER_PASSWORD POSTGRES_APP_PASSWORD REDIS_PASSWORD RESTIC_PASSWORD; do
        local value
        value="$(env_value "$key")"
        refute grep -qF -- "$value" "$MOCK_LOG/docker.log"
        refute grep -qF -- "$value" "$MOCK_LOG/curl.log"
    done
}

@test "tenant slug is derived from the name with French accents transliterated" {
    TMS_TENANT_NAME="Crème & Formation Évolution" run bash "$INSTALL_SH"
    [ "$status" -eq 0 ]
    [ "$(env_value TMS_DEFAULT_TENANT)" = "creme-formation-evolution" ]
    grep -q -- "--slug=creme-formation-evolution " "$MOCK_LOG/docker.log"
}

@test "multi mode passes the domain to tms:install, single mode does not" {
    TMS_MODE=multi run bash "$INSTALL_SH"
    [ "$status" -eq 0 ]
    grep -q -- "tms:install .*--domain=tms.example.test" "$MOCK_LOG/docker.log"

    rm -rf "$TMS_DIR" "$MOCK_LOG/docker.log"
    run bash "$INSTALL_SH"
    [ "$status" -eq 0 ]
    refute grep -q -- "--domain" "$MOCK_LOG/docker.log"
}

@test "TMS_SEED_CAP=0 skips the CAP references" {
    TMS_SEED_CAP=0 run bash "$INSTALL_SH"
    [ "$status" -eq 0 ]
    grep -q "tms:install" "$MOCK_LOG/docker.log"
    refute grep -q -- "--seed-cap" "$MOCK_LOG/docker.log"
}

@test "TMS_PRINT_SECRETS=0 keeps secret values off the terminal" {
    TMS_PRINT_SECRETS=0 run bash "$INSTALL_SH"
    [ "$status" -eq 0 ]
    [[ "$output" != *"$(env_value RESTIC_PASSWORD)"* ]] || false
    [[ "$output" != *"$(env_value APP_KEY)"* ]] || false
    [[ "$output" == *"$TMS_DIR/.env"* ]] || false
}

@test "TMS_TLS=internal is written and an unknown value is refused" {
    TMS_TLS=internal run bash "$INSTALL_SH"
    [ "$status" -eq 0 ]
    [ "$(env_value TMS_TLS)" = "internal" ]

    rm -rf "$TMS_DIR"
    TMS_TLS=bogus run bash "$INSTALL_SH"
    [ "$status" -ne 0 ]
    [[ "$output" == *"TMS_TLS"* ]] || false
}

@test "explicit version downloads that release" {
    TMS_VERSION="v$FIXTURE_VERSION" TMS_DIST_URL="https://dist.example.test/releases/download/v$FIXTURE_VERSION" run bash "$INSTALL_SH"
    [ "$status" -eq 0 ]
    [ "$(env_value TMS_VERSION)" = "$FIXTURE_VERSION" ]
    grep -q "releases/download/v$FIXTURE_VERSION/tms-$FIXTURE_VERSION.tar.gz" "$MOCK_LOG/curl.log"
}

# Release whose ops/import.sh is a stub: logs its arguments and whether the export password
# reached it (never its value), exits with MOCK_IMPORT_RC.
build_release_with_import_stub() {
    local stage="$BATS_TEST_TMPDIR/stage-import"
    stage_src "$stage"
    cat >"$stage/ops/import.sh" <<'SH'
#!/usr/bin/env bash
{
    echo "import.sh $*"
    [ -n "${TMS_IMPORT_PASSWORD:-}" ] && echo "password: set"
} >>"$MOCK_LOG/import.log"
echo "Récapitulatif de contrôle"
exit "${MOCK_IMPORT_RC:-0}"
SH
    rm -rf "$MOCK_DIST" && mkdir -p "$MOCK_DIST"
    bundle_src "$stage" "$FIXTURE_VERSION" "$MOCK_DIST" "$FIXTURE_DIGEST"
}

@test "install --import: fresh secrets, no organisme nor administrator, then import.sh on the file" {
    build_release_with_import_stub
    local export_file="$BATS_TEST_TMPDIR/tms-export-a.tar" password="mot-de-passe-de-l-export"
    echo "export" >"$export_file"
    unset TMS_TENANT_NAME
    TMS_IMPORT_PASSWORD="$password" run bash "$INSTALL_SH" --import "$export_file"
    [ "$status" -eq 0 ]
    grep -qx "import.sh $export_file" "$MOCK_LOG/import.log"
    grep -qx "password: set" "$MOCK_LOG/import.log"
    refute grep -q "tms:install" "$MOCK_LOG/docker.log"
    # The stack itself is installed: secrets, migrations, start, backups.
    for key in APP_KEY POSTGRES_OWNER_PASSWORD POSTGRES_APP_PASSWORD REDIS_PASSWORD RESTIC_PASSWORD; do
        [ -n "$(env_value "$key")" ]
    done
    grep -q "migrate --database=pgsql_owner --force" "$MOCK_LOG/docker.log"
    grep -q "enable --now tms-backup.timer" "$MOCK_LOG/systemctl.log"
    [[ "$output" == *"Récapitulatif de contrôle"* ]] || false
    [[ "$output" == *"ceux du"*"serveur exporté"* ]] || false
    [[ "$output" != *"Activation"* ]] || false
    # Only the new RESTIC_PASSWORD is shown: APP_KEY comes from the export.
    [[ "$output" == *"RESTIC_PASSWORD=$(env_value RESTIC_PASSWORD)"* ]] || false
    [[ "$output" != *"$(env_value APP_KEY)"* ]] || false
    # The export password never reaches a log or a command line.
    [[ "$output" != *"$password"* ]] || false
    refute grep -qF -- "$password" "$MOCK_LOG/docker.log" "$MOCK_LOG/import.log" "$TMS_DIR/.env"
}

@test "install reads TMS_IMPORT_FILE from the settings file and refuses a missing export before anything" {
    build_release_with_import_stub
    unset TMS_TENANT_NAME
    printf 'TMS_IMPORT_FILE=%s\n' "$BATS_TEST_TMPDIR/absent.tar" >"$BATS_TEST_TMPDIR/settings.env"
    TMS_INSTALL_ENV="$BATS_TEST_TMPDIR/settings.env" run bash "$INSTALL_SH"
    [ "$status" -ne 0 ]
    [[ "$output" == *"Fichier d'export introuvable ou illisible"* ]] || false
    [ ! -f "$MOCK_LOG/curl.log" ]
    [ ! -e "$TMS_DIR/.env" ]

    echo "export" >"$BATS_TEST_TMPDIR/absent.tar"
    TMS_IMPORT_PASSWORD="mot-de-passe-de-l-export" TMS_INSTALL_ENV="$BATS_TEST_TMPDIR/settings.env" run bash "$INSTALL_SH"
    [ "$status" -eq 0 ]
    grep -qx "import.sh $BATS_TEST_TMPDIR/absent.tar" "$MOCK_LOG/import.log"
}

@test "install --import: a failed import keeps the installation and says how to run it again" {
    build_release_with_import_stub
    echo "export" >"$BATS_TEST_TMPDIR/e.tar"
    MOCK_IMPORT_RC=1 TMS_IMPORT_PASSWORD="mot-de-passe-de-l-export" run bash "$INSTALL_SH" --import "$BATS_TEST_TMPDIR/e.tar"
    [ "$status" -ne 0 ]
    [[ "$output" == *"relancez $TMS_DIR/ops/import.sh $BATS_TEST_TMPDIR/e.tar"* ]] || false
    [[ "$output" != *"down -v"* ]] || false
    [ -f "$TMS_DIR/.env" ]
    grep -q "enable --now tms-backup.timer" "$MOCK_LOG/systemctl.log"
}

# --- proxmox.sh ----------------------------------------------------------------

@test "proxmox.sh refuses to run outside proxmox" {
    rm "$MOCK_BIN/pveversion"
    run bash "$PROXMOX_SH"
    [ "$status" -eq 1 ]
    [[ "$output" == *"Proxmox VE introuvable"* ]] || false
    [ ! -f "$MOCK_LOG/pct.log" ]
}

@test "proxmox.sh builds pct create with unprivileged and nesting" {
    TMS_NONINTERACTIVE=1 run bash "$PROXMOX_SH"
    [ "$status" -eq 0 ]
    local create
    create="$(grep '^pct create' "$MOCK_LOG/pct.log")"
    [[ "$create" == "pct create 123 local:vztmpl/debian-12-standard_12.7-1_amd64.tar.zst "* ]] || false
    [[ "$create" == *"--unprivileged 1"* ]] || false
    [[ "$create" == *"--features nesting=1,keyctl=1"* ]] || false
    [[ "$create" == *"--onboot 1"* ]] || false
    [[ "$create" == *"--hostname tms"* ]] || false
    [[ "$create" == *"--cores 2"* ]] || false
    [[ "$create" == *"--memory 4096"* ]] || false
    [[ "$create" == *"--rootfs local-lvm:20"* ]] || false
    [[ "$create" == *"--net0 name=eth0,bridge=vmbr0,ip=dhcp"* ]] || false
}

@test "proxmox.sh non-interactive mode downloads the template and runs the installer in the CT" {
    TMS_NONINTERACTIVE=1 CT_IP=192.168.1.50/24 CT_GW=192.168.1.1 run bash "$PROXMOX_SH"
    [ "$status" -eq 0 ]
    grep -q "^pveam update" "$MOCK_LOG/pveam.log"
    grep -q "^pveam download local debian-12-standard_12.7-1_amd64.tar.zst" "$MOCK_LOG/pveam.log"
    grep -q "net0 name=eth0,bridge=vmbr0,ip=192.168.1.50/24,gw=192.168.1.1" "$MOCK_LOG/pct.log"
    grep -q "^pct start 123" "$MOCK_LOG/pct.log"
    grep -q "^pct exec 123 -- env TMS_INSTALL_ENV=/root/tms-install.env bash /root/tms-install.sh" "$MOCK_LOG/pct.log"
    # the pushed installer is the verified release file
    cmp "$MOCK_DIST/install.sh" "$MOCK_LOG/push/tms-install.sh"
    grep -q "^TMS_DOMAIN=tms.example.test$" "$MOCK_LOG/push/tms-install.env"
    grep -q "^TMS_TENANT_NAME=CAP FORMATION$" "$MOCK_LOG/push/tms-install.env"
}

@test "proxmox.sh never puts secrets on pct command lines" {
    TMS_NONINTERACTIVE=1 run bash "$PROXMOX_SH"
    [ "$status" -eq 0 ]
    refute grep -Eiq 'PASSWORD|APP_KEY|SECRET' "$MOCK_LOG/pct.log"
    refute grep -Eq '^(APP_KEY|[A-Z_]*PASSWORD)=' "$MOCK_LOG/push/tms-install.env"
}

@test "proxmox.sh refuses a tampered install.sh" {
    echo "echo pwned" >>"$MOCK_DIST/install.sh"
    TMS_NONINTERACTIVE=1 run bash "$PROXMOX_SH"
    [ "$status" -ne 0 ]
    [[ "$output" == *"Somme de contrôle invalide"* ]] || false
    refute grep -q "^pct create" "$MOCK_LOG/pct.log"
}

@test "proxmox.sh non-interactive mode names a missing variable" {
    unset TMS_ADMIN_EMAIL
    TMS_NONINTERACTIVE=1 run bash "$PROXMOX_SH"
    [ "$status" -ne 0 ]
    [[ "$output" == *"TMS_ADMIN_EMAIL"* ]] || false
    [ ! -f "$MOCK_LOG/pct.log" ]
}

@test "proxmox.sh refuses a static address without gateway" {
    TMS_NONINTERACTIVE=1 CT_IP=192.168.1.50/24 run bash "$PROXMOX_SH"
    [ "$status" -ne 0 ]
    [[ "$output" == *"CT_GW"* ]] || false
}

@test "proxmox.sh update snapshots the CT then runs update.sh inside it" {
    TMS_DIST_URL="https://dist.example.test/x" run bash "$PROXMOX_SH" update 123 --version 1.2.3
    [ "$status" -eq 0 ]
    local snap
    snap="$(grep '^pct snapshot 123 ' "$MOCK_LOG/pct.log" | awk '{ print $4 }')"
    [[ "$snap" =~ ^pre-update-[0-9]{8}-[0-9]{6}$ ]] || false
    grep -q "^pct exec 123 -- env TMS_PCT_SNAPSHOT=$snap TMS_DIST_URL=https://dist.example.test/x /opt/tms/ops/update.sh --version 1.2.3$" "$MOCK_LOG/pct.log"
    # snapshot before update
    [ "$(grep -n '^pct snapshot' "$MOCK_LOG/pct.log" | cut -d: -f1)" -lt "$(grep -n 'ops/update.sh' "$MOCK_LOG/pct.log" | cut -d: -f1)" ]
    refute grep -q "^pct create" "$MOCK_LOG/pct.log"
}

@test "proxmox.sh update keeps only the 3 most recent pre-update snapshots" {
    printf '%s\n' manuel pre-update-20260101-000000 pre-update-20260201-000000 pre-update-20260301-000000 \
        pre-update-20260401-000000 >"$MOCK_LOG/snapshots"
    run bash "$PROXMOX_SH" update 123
    [ "$status" -eq 0 ]
    [ "$(grep -c '^pre-update-' "$MOCK_LOG/snapshots")" -eq 3 ]
    grep -qx manuel "$MOCK_LOG/snapshots"
    grep -qx pre-update-20260301-000000 "$MOCK_LOG/snapshots"
    grep -qx pre-update-20260401-000000 "$MOCK_LOG/snapshots"
    refute grep -qx pre-update-20260101-000000 "$MOCK_LOG/snapshots"
    refute grep -qx pre-update-20260201-000000 "$MOCK_LOG/snapshots"
    grep -q "^pct delsnapshot 123 pre-update-20260101-000000" "$MOCK_LOG/pct.log"
}

@test "proxmox.sh update propagates the update exit code and points to pct rollback" {
    MOCK_PCT_EXEC_RC=1 run bash "$PROXMOX_SH" update 123
    [ "$status" -eq 1 ]
    [[ "$output" == *"pct rollback 123 pre-update-"* ]] || false

    MOCK_PCT_EXEC_RC=2 run bash "$PROXMOX_SH" update 123
    [ "$status" -eq 2 ]
    [[ "$output" == *"pct rollback 123 pre-update-"* ]] || false
}

@test "proxmox.sh update goes on with a warning when the storage cannot snapshot" {
    MOCK_PCT_SNAPSHOT_FAIL=1 run bash "$PROXMOX_SH" update 123
    [ "$status" -eq 0 ]
    [[ "$output" == *"instantané"* ]] || false
    grep -q "ops/update.sh" "$MOCK_LOG/pct.log"
    refute grep -q "^pct delsnapshot" "$MOCK_LOG/pct.log"
}

@test "proxmox.sh update refuses an invalid CTID" {
    run bash "$PROXMOX_SH" update abc
    [ "$status" -ne 0 ]
    [[ "$output" == *"CTID"* ]] || false
    [ ! -f "$MOCK_LOG/pct.log" ]
}

# --- shared code ---------------------------------------------------------------

@test "install.sh and proxmox.sh embed the same release helpers as ops/lib.sh" {
    local fn ref
    for fn in verify_checksum checksum_of release_dist_url download image_digest_of fetch_bundle; do
        ref="$(bash -c 'source "$1" && declare -f "$2"' _ "$TMS_ROOT/ops/lib.sh" "$fn")"
        [ -n "$ref" ]
        [ "$(TMS_INSTALL_NO_MAIN=1 bash -c 'source "$1" && declare -f "$2"' _ "$INSTALL_SH" "$fn")" = "$ref" ]
    done
    for fn in verify_checksum checksum_of release_dist_url; do
        ref="$(bash -c 'source "$1" && declare -f "$2"' _ "$TMS_ROOT/ops/lib.sh" "$fn")"
        [ "$(TMS_PROXMOX_NO_MAIN=1 bash -c 'source "$1" && declare -f "$2"' _ "$PROXMOX_SH" "$fn")" = "$ref" ]
    done
}
