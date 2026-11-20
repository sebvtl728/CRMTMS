#!/usr/bin/env bats
# Moving to another server: ops/export.sh on a stack A, ops/import.sh on a fresh stack B
# (throwaway production-like stacks, see helpers.bash).
#
# One stack at a time (same ports, limited memory): setup_file builds A (demo data, a user with
# confirmed 2FA, a connector secret encrypted with A's APP_KEY, files in storage/app), records
# what B must show after the import, exports A three times, tears A down, then brings up B with
# other technical secrets and another APP_KEY.
# Versions: A and B run 0.8.2 (the image tms-ci:bats tagged 0.8.2). The « newer » export is taken
# with TMS_VERSION=0.9.0 (its meta.env says 0.9.0); the « older » one with TMS_VERSION=0.8.1
# after rolling back the last migration on A: importing it must run that migration again.
# The « multi » export is taken with TMS_MODE=multi once A holds a second organisme: B (single
# mode) must refuse it. The « older » export is taken with --stop (systemctl stubbed): A is stopped
# before its backup and its backup units disabled; a --stop export that fails first starts A again.
# At the end, the pre-import safety backup of B is restored.

load helpers

EXPORT_PASSWORD="mot-de-passe-export-bats"
CONNECTOR_SECRET="jeton-connecteur-bats-a"
TOTP_SECRET="JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP"
PHP_BOOT='require "vendor/autoload.php"; $a = require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$t = App\Modules\Core\Tenancy\Tenant::query()->where("slug", "organisme-a")->firstOrFail();'

# refute <cmd...>: fails when the command succeeds (a bare `! cmd` never fails a bats test).
refute() {
    if "$@"; then return 1; fi
}

file_mode() {
    stat -c %a "$1" 2>/dev/null || stat -f %Lp "$1"
}

# psql_su <sql>: as the PostgreSQL superuser (row level security does not apply).
psql_su() {
    compose exec -T postgres psql -U postgres -d tms -tA -v ON_ERROR_STOP=1 -c "$1"
}

# table_counts: « table=rows » for every table of the schema public.
table_counts() {
    psql_su "select table_name || '=' || (xpath('/row/c/text()', query_to_xml(format('select count(*) as c from public.%I', table_name), false, true, '')))[1]::text
        from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE' order by table_name"
}

# storage_listing: path and SHA-256 of every file of storage/app.
storage_listing() {
    compose exec -T app sh -c 'cd storage/app && find . -type f -exec sha256sum {} + | LC_ALL=C sort'
}

# local_snapshots: ids of the snapshots of the stack's own repository (none yet: empty).
local_snapshots() {
    (load_env && export RESTIC_PASSWORD RESTIC_REPOSITORY && restic_snapshot_ids 2>/dev/null) || true
}

# b_state: everything an import refused must leave untouched.
b_state() {
    table_counts
    storage_listing
    sha256_of "$TMS_ENV_FILE"
    local_snapshots
}

# php_in_tenant <php>: runs PHP in the app container inside the organisme organisme-a.
php_in_tenant() {
    compose exec -T app php -r "$PHP_BOOT"' app(App\Modules\Core\Tenancy\TenantContext::class)->run($t, function () { '"$1"' });'
}

# export_a <output dir> [env...]: ops/export.sh on A; stdout in <dir>.out, stderr in <dir>.err.
export_a() {
    local dir="$1"
    shift
    mkdir -p "$dir"
    env "$@" TMS_EXPORT_PASSWORD="$EXPORT_PASSWORD" "$TMS_ROOT/ops/export.sh" --output "$dir" >"$dir.out" 2>"$dir.err"
}

# running_services: services of the stack currently running, one per line.
running_services() {
    compose ps --status running --services | LC_ALL=C sort
}

setup_file() {
    local dir="$BATS_FILE_TMPDIR"

    # --- Stack A -------------------------------------------------------------------------
    STACK_VERSION=0.8.2 STACK_ENV_EXTRA="TMS_DEFAULT_TENANT=organisme-a" stack_up
    php_in_tenant '
        $u = new App\Modules\Core\Users\User(["name" => "Admin A", "email" => "admin@a.test"]);
        $u->save();
        $u->forceFill(["two_factor_secret" => "'"$TOTP_SECRET"'", "two_factor_confirmed_at" => now()])->save();
        App\Modules\Connectors\Core\ConnectorConfig::create(["connector_key" => "bats", "secrets" => ["token" => "'"$CONNECTOR_SECRET"'"]]);
    ' >&2
    compose exec -T app sh -c 'mkdir -p storage/app/bats-move && head -c 4096 /dev/urandom > storage/app/bats-move/fichier.bin && echo "Pièce jointe" > "storage/app/bats-move/pièce jointe.txt"'
    # Nothing writes during the export (queue and scheduler stopped).
    compose stop worker scheduler >&2
    table_counts >"$dir/a-counts"
    storage_listing >"$dir/a-storage"
    env_get APP_KEY >"$dir/a-app-key"

    docker tag tms-ci:bats tms-ci:0.9.0 >&2
    docker tag tms-ci:bats tms-ci:0.8.1 >&2
    export_a "$dir/export"
    (load_env && export RESTIC_PASSWORD RESTIC_REPOSITORY && restic_run -- snapshots --tag export --json) >"$dir/a-export-snapshots"
    export_a "$dir/newer" TMS_VERSION=0.9.0

    # A second organisme, exported as a multi-organismes installation.
    compose exec -T app php -r 'require "vendor/autoload.php"; $a = require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        app(App\Modules\Core\Platform\TenantProvisioner::class)->create("Organisme deux", "organisme-deux");' >&2
    export_a "$dir/multi" TMS_MODE=multi

    # --stop with a backup that fails (no RESTIC_PASSWORD): the application is started again.
    sed '/^RESTIC_PASSWORD=/d' "$TMS_ENV_FILE" >"$dir/env.nopass"
    mkdir -p "$dir/failed"
    rc=0
    TMS_ENV_FILE="$dir/env.nopass" TMS_EXPORT_PASSWORD="$EXPORT_PASSWORD" \
        "$TMS_ROOT/ops/export.sh" --output "$dir/failed" --stop >"$dir/failed.out" 2>"$dir/failed.err" || rc=$?
    echo "$rc" >"$dir/failed.rc"
    running_services >"$dir/failed-services"
    compose stop worker scheduler >&2

    # An « older version »: the last migration is not applied yet.
    DB_OWNER_USERNAME=tms_owner DB_OWNER_PASSWORD="$(env_get POSTGRES_OWNER_PASSWORD)" \
        compose run --rm --no-deps -T -e DB_OWNER_USERNAME -e DB_OWNER_PASSWORD \
        app php artisan migrate:rollback --step=1 --database=pgsql_owner --force >&2
    psql_su "select count(*) from migrations where migration = '$(last_migration)'" >"$dir/a-old-last-migration"
    # Exported with --stop: systemctl stubbed, units of the backups present.
    mkdir -p "$dir/bin" "$dir/systemd" "$dir/older"
    printf '#!/usr/bin/env bash\nprintf "%%s\\n" "$*" >>"%s/systemctl.log"\n' "$dir" >"$dir/bin/systemctl"
    chmod +x "$dir/bin/systemctl"
    touch "$dir/systemd/tms-backup.timer" "$dir/systemd/tms-backup-request.path"
    PATH="$dir/bin:$PATH" TMS_SYSTEMD_DIR="$dir/systemd" TMS_VERSION=0.8.1 TMS_EXPORT_PASSWORD="$EXPORT_PASSWORD" \
        "$TMS_ROOT/ops/export.sh" --output "$dir/older" --stop >"$dir/older.out" 2>"$dir/older.err"
    running_services >"$dir/older-services"

    stack_down

    # --- Stack B: new technical secrets and APP_KEY, its own demo organisme -------------
    STACK_VERSION=0.8.2 TMS_TEST_SKIP_BUILD=1 stack_up
}

teardown_file() {
    stack_down
}

# The newest migration of the repository: the one the « older » export lacks.
last_migration() { basename "$(find "$TMS_ROOT/database/migrations" -name '*.php' | sort | tail -n 1)" .php; }

good_export() { tail -n 1 "$BATS_FILE_TMPDIR/export.out"; }

@test "export: one encrypted tar named after the organisme, date and version, mode 600, SHA-256 printed" {
    local file
    file="$(good_export)"
    [ -f "$file" ]
    [[ "$file" == "$BATS_FILE_TMPDIR/export/"* ]] || false
    [[ "$(basename "$file")" =~ ^tms-export-organisme-a-[0-9]{8}-[0-9]{4}-v0\.8\.2\.tar$ ]] || false
    [ "$(file_mode "$file")" = "600" ]
    grep -qx "SHA-256 : $(sha256_of "$file")" "$BATS_FILE_TMPDIR/export.out"
    # Only that file in the output directory (no temporary file left).
    [ "$(ls -A "$BATS_FILE_TMPDIR/export" | wc -l | tr -d ' ')" -eq 1 ]
    # One restic repository inside, nothing readable: no secret, no personal data, no APP_KEY.
    # grep reads the whole list (-q would stop early, tar then fails on a write error).
    tar -tf "$file" | grep -x 'repo/config' >/dev/null
    refute grep -qaF "$CONNECTOR_SECRET" "$file"
    refute grep -qaF "admin@a.test" "$file"
    refute grep -qaF "$(cat "$BATS_FILE_TMPDIR/a-app-key")" "$file"
    refute grep -qaF "Pièce jointe" "$file"
    # The export password never appears in the output.
    refute grep -qF "$EXPORT_PASSWORD" "$BATS_FILE_TMPDIR/export.out" "$BATS_FILE_TMPDIR/export.err"
    # Taken from a backup tagged « export » on A.
    [[ "$(cat "$BATS_FILE_TMPDIR/a-export-snapshots")" == *'"export"'* ]] || false
    # The « newer » and « older » exports carry their version in their name.
    [[ "$(tail -n 1 "$BATS_FILE_TMPDIR/newer.out")" == *-v0.9.0.tar ]] || false
    [[ "$(tail -n 1 "$BATS_FILE_TMPDIR/older.out")" == *-v0.8.1.tar ]] || false
    [ "$(cat "$BATS_FILE_TMPDIR/a-old-last-migration")" = "0" ]
}

@test "export --stop: old server stopped, backup units disabled, restart commands printed; restarted on failure" {
    local dir="$BATS_FILE_TMPDIR"
    # Successful export (« older »): nothing of the application runs any more, the database does.
    refute grep -qxE 'app|worker|scheduler' "$dir/older-services"
    grep -qx postgres "$dir/older-services"
    [ "$(cat "$dir/systemctl.log")" = "disable --now tms-backup.timer tms-backup-request.path" ]
    grep -qF "Ancien serveur arrêté" "$dir/older.err"
    grep -qF "Pour annuler le déménagement et le remettre en service : docker compose --project-directory $TMS_ROOT" "$dir/older.err"
    grep -qF " up -d && systemctl enable --now tms-backup.timer tms-backup-request.path" "$dir/older.err"
    [[ "$(tail -n 1 "$dir/older.out")" == "$dir/older/"*-v0.8.1.tar ]] || false

    # Failed export: refused, no file, the application runs again.
    [ "$(cat "$dir/failed.rc")" -ne 0 ]
    grep -qF "RESTIC_PASSWORD" "$dir/failed.err"
    grep -qF "Export échoué : redémarrage de l'application sur ce serveur." "$dir/failed.err"
    grep -qx app "$dir/failed-services"
    [ -z "$(ls -A "$dir/failed")" ]
}

@test "export refuses a short password and two different passwords, before any backup" {
    local out="$BATS_TEST_TMPDIR/out" before
    mkdir -p "$out"
    before="$(local_snapshots)"

    TMS_EXPORT_PASSWORD="trop-court" run "$TMS_ROOT/ops/export.sh" --output "$out"
    [ "$status" -ne 0 ]
    [[ "$output" == *"12 caractères minimum"* ]] || false

    run bash -c 'printf "%s\n%s\n" "mot-de-passe-numero-un" "mot-de-passe-numero-deux" | "$1" --output "$2"' _ "$TMS_ROOT/ops/export.sh" "$out"
    [ "$status" -ne 0 ]
    [[ "$output" == *"Les deux mots de passe saisis diffèrent"* ]] || false
    [[ "$output" != *"mot-de-passe-numero"* ]] || false

    [ -z "$(ls -A "$out")" ]
    [ "$(local_snapshots)" = "$before" ]
}

@test "import refuses a wrong password and leaves B untouched" {
    local before
    before="$(b_state)"
    TMS_IMPORT_PASSWORD="mauvais-mot-de-passe" run "$TMS_ROOT/ops/import.sh" "$(good_export)"
    [ "$status" -ne 0 ]
    [[ "$output" == *"Mot de passe incorrect pour cet export"* ]] || false
    [[ "$output" == *"rien n'a été modifié"* ]] || false
    [[ "$output" != *"mauvais-mot-de-passe"* ]] || false
    [ "$(b_state)" = "$before" ]
}

@test "import refuses a truncated or damaged file and leaves B untouched" {
    local before file size damaged="$BATS_TEST_TMPDIR/damaged"
    before="$(b_state)"
    file="$(good_export)"
    size="$(wc -c <"$file" | tr -d ' ')"

    head -c "$((size / 2))" "$file" >"$BATS_TEST_TMPDIR/truncated.tar"
    TMS_IMPORT_PASSWORD="$EXPORT_PASSWORD" run "$TMS_ROOT/ops/import.sh" "$BATS_TEST_TMPDIR/truncated.tar"
    [ "$status" -ne 0 ]
    [[ "$output" == *"incomplet"* ]] || false
    [ "$(b_state)" = "$before" ]

    # A valid tar missing one data file of the repository: restic check refuses it.
    mkdir -p "$damaged"
    tar -xf "$file" -C "$damaged"
    rm -f "$(find "$damaged/repo/data" -type f | head -n 1)"
    tar -cf "$BATS_TEST_TMPDIR/damaged.tar" -C "$damaged" repo
    TMS_IMPORT_PASSWORD="$EXPORT_PASSWORD" run "$TMS_ROOT/ops/import.sh" "$BATS_TEST_TMPDIR/damaged.tar"
    [ "$status" -ne 0 ]
    [[ "$output" == *"endommagé ou incomplet"* ]] || false
    [ "$(b_state)" = "$before" ]

    # Not an export at all: not a tar, or a tar holding something else than a repository.
    TMS_IMPORT_PASSWORD="$EXPORT_PASSWORD" run "$TMS_ROOT/ops/import.sh" "$TMS_ROOT/compose.prod.yaml"
    [ "$status" -ne 0 ]
    [[ "$output" == *"illisible ou incomplet"* ]] || false
    mkdir -p "$damaged/autre"
    echo "x" >"$damaged/autre/fichier"
    tar -cf "$BATS_TEST_TMPDIR/other.tar" -C "$damaged" repo autre
    TMS_IMPORT_PASSWORD="$EXPORT_PASSWORD" run "$TMS_ROOT/ops/import.sh" "$BATS_TEST_TMPDIR/other.tar"
    [ "$status" -ne 0 ]
    [[ "$output" == *"n'est pas un export TMS"* ]] || false
    [ "$(b_state)" = "$before" ]
}

@test "import refuses an export of a newer version and leaves B untouched" {
    local before
    before="$(b_state)"
    TMS_IMPORT_PASSWORD="$EXPORT_PASSWORD" run "$TMS_ROOT/ops/import.sh" "$(tail -n 1 "$BATS_FILE_TMPDIR/newer.out")"
    [ "$status" -ne 0 ]
    [[ "$output" == *"version 0.9.0, plus récente que la version installée (0.8.2)"* ]] || false
    [[ "$output" == *"mettez d'abord à jour en 0.9.0"* ]] || false
    [ "$(b_state)" = "$before" ]
}

@test "import refuses a multi-organismes export with several organismes on a single installation" {
    local before
    before="$(b_state)"
    TMS_IMPORT_PASSWORD="$EXPORT_PASSWORD" run "$TMS_ROOT/ops/import.sh" "$(tail -n 1 "$BATS_FILE_TMPDIR/multi.out")"
    [ "$status" -ne 0 ]
    [[ "$output" == *"Cet export contient plusieurs organismes : installez en mode multi-organismes."* ]] || false
    [ "$(b_state)" = "$before" ]
}

@test "import refuses to start while another maintenance operation holds the lock" {
    local before
    before="$(b_state)"
    # exec: the killed process is the one holding the lock (fd 9), no orphan keeps it.
    bash -c 'source "$1/ops/lib.sh"; tms_lock 0; echo verrouillé; exec sleep 60' _ "$TMS_ROOT" >"$BATS_TEST_TMPDIR/holder" 2>&1 &
    local holder=$!
    until grep -q verrouillé "$BATS_TEST_TMPDIR/holder"; do sleep 0.2; done
    TMS_IMPORT_PASSWORD="$EXPORT_PASSWORD" run "$TMS_ROOT/ops/import.sh" "$(good_export)"
    kill "$holder"
    wait "$holder" 2>/dev/null || true
    [ "$status" -ne 0 ]
    [[ "$output" == *"Une autre opération de maintenance"* ]] || false
    [ "$(b_state)" = "$before" ]
}

@test "import into B: same rows in every table, same files, connector secret and 2FA usable, healthy" {
    local before_snapshots key_b
    before_snapshots="$(local_snapshots)"
    key_b="$(env_get APP_KEY)"
    # B before the import, for the return to that state (last test).
    table_counts >"$BATS_FILE_TMPDIR/b-before-counts"
    storage_listing >"$BATS_FILE_TMPDIR/b-before-storage"
    [ "$key_b" != "$(cat "$BATS_FILE_TMPDIR/a-app-key")" ]

    TMS_IMPORT_PASSWORD="$EXPORT_PASSWORD" run "$TMS_ROOT/ops/import.sh" "$(good_export)"
    [ "$status" -eq 0 ]
    [[ "$output" != *"$EXPORT_PASSWORD"* ]] || false

    # Every table of the schema public, read as superuser: identical row counts.
    diff <(table_counts) "$BATS_FILE_TMPDIR/a-counts"
    # Same storage/app tree, file by file.
    diff <(storage_listing) "$BATS_FILE_TMPDIR/a-storage"

    # Control summary (stdout), consistent with A.
    a_count() { sed -n "s/^$1=//p" "$BATS_FILE_TMPDIR/a-counts"; }
    [[ "$output" == *"Organismes   : $(a_count tenants)"* ]] || false
    [[ "$output" == *"Utilisateurs : $(a_count users)"* ]] || false
    [[ "$output" == *"Entreprises  : $(a_count organisations)"* ]] || false
    [[ "$output" == *"Personnes    : $(a_count people)"* ]] || false
    [[ "$output" == *"Sessions     : $(a_count training_sessions)"* ]] || false
    [[ "$output" == *"Fichiers     : $(wc -l <"$BATS_FILE_TMPDIR/a-storage" | tr -d ' ')"* ]] || false
    [[ "$output" == *"ceux du serveur exporté"* ]] || false

    # APP_KEY and organisme of A adopted; the previous .env kept aside.
    [ "$(env_get APP_KEY)" = "$(cat "$BATS_FILE_TMPDIR/a-app-key")" ]
    [ "$(env_get TMS_DEFAULT_TENANT)" = "organisme-a" ]
    [ "$(file_mode "$TMS_ENV_FILE")" = "600" ]
    bak="$(ls "$TMS_ENV_FILE".bak-* | tail -n 1)"
    [ "$(sed -n 's/^APP_KEY=//p' "$bak")" = "$key_b" ]

    # Safety backup of B taken before, tagged pre-import, named in the summary.
    safety="$(comm -13 <(printf '%s\n' "$before_snapshots" | sort) <(local_snapshots | sort) | grep . )"
    [ "$(printf '%s\n' "$safety" | wc -l | tr -d ' ')" -eq 1 ]
    printf '%s\n' "$safety" >"$BATS_FILE_TMPDIR/b-safety"
    [[ "$output" == *"ops/restore.sh --with-app-key ${safety:0:8}"* ]] || false
    (load_env && export RESTIC_PASSWORD RESTIC_REPOSITORY && restic_run -- snapshots --tag pre-import --json) | grep -q "$safety"

    # The connector secret, encrypted with A's APP_KEY, decrypts on B.
    run php_in_tenant 'echo App\Modules\Connectors\Core\ConnectorConfig::query()->where("connector_key", "bats")->firstOrFail()->secret("token");'
    [ "$status" -eq 0 ]
    [ "$output" = "$CONNECTOR_SECRET" ]
    # The 2FA of A's user still verifies a current TOTP code.
    run php_in_tenant '
        $u = App\Modules\Core\Users\User::query()->where("email", "admin@a.test")->firstOrFail();
        $code = app(PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp("'"$TOTP_SECRET"'");
        echo app(App\Modules\Core\Users\TwoFactorAuthenticator::class)->verifyCode($u, $code) ? "2fa-ok" : "2fa-ko";'
    [ "$status" -eq 0 ]
    [ "$output" = "2fa-ok" ]

    # Healthy, and the organisme of A is the one the application serves.
    run compose exec -T caddy wget -q -O - http://127.0.0.1:8081/health
    [ "$status" -eq 0 ]
    [[ "$output" == *'"status":"ok"'* ]] || false
    [[ "$output" == *'"version":"0.8.2"'* ]] || false
    run compose exec -T app php -r 'require "vendor/autoload.php"; $a = require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo App\Modules\Core\Tenancy\Tenant::query()->where("slug", config("tms.default_tenant"))->value("slug");'
    [ "$status" -eq 0 ]
    [[ "$output" == *"organisme-a"* ]] || false
}

@test "import of an export from an older version runs the missing migrations" {
    TMS_IMPORT_PASSWORD="$EXPORT_PASSWORD" run "$TMS_ROOT/ops/import.sh" "$(tail -n 1 "$BATS_FILE_TMPDIR/older.out")"
    [ "$status" -eq 0 ]
    [[ "$output" == *"(version 0.8.1)"* ]] || false
    # The migration rolled back on A before this export ran during the import.
    [ "$(psql_su "select count(*) from migrations where migration = '$(last_migration)'")" = "1" ]
    run compose exec -T app php artisan migrate:status
    [ "$status" -eq 0 ]
    [[ "$output" != *"Pending"* ]] || false
    run compose exec -T caddy wget -q -O - http://127.0.0.1:8081/health
    [ "$status" -eq 0 ]
    [[ "$output" == *'"status":"ok"'* ]] || false
}

@test "the pre-import safety backup brings B back to its state before the import" {
    local safety key_b
    safety="$(cat "$BATS_FILE_TMPDIR/b-safety")"
    [ -n "$safety" ]
    run "$TMS_ROOT/ops/restore.sh" --with-app-key "$safety"
    [ "$status" -eq 0 ]
    diff <(table_counts) "$BATS_FILE_TMPDIR/b-before-counts"
    diff <(storage_listing) "$BATS_FILE_TMPDIR/b-before-storage"
    # B's own APP_KEY is back (it travels in the safety snapshot).
    key_b="$(sed -n 's/^APP_KEY=//p' "$(ls "$TMS_ENV_FILE".bak-* | head -n 1)")"
    [ "$(env_get APP_KEY)" = "$key_b" ]
    run compose exec -T caddy wget -q -O - http://127.0.0.1:8081/health
    [ "$status" -eq 0 ]
    [[ "$output" == *'"status":"ok"'* ]] || false
}
