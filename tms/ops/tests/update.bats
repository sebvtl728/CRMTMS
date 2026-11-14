#!/usr/bin/env bats
# Integration tests of ops/update.sh against a throwaway production-like stack
# installed from a copy of the release bundle (STACK_INSTALL=1, see helpers.bash).
#
# The "releases" are served from a local directory (TMS_DIST_URL=file://…), no network:
#   test-ok       current code + a harmless migration (creates bats_update_ok); its bundle
#                 adds two keys required by compose.prod.yaml (a default and a
#                 __GENERATE__ secret) and an updater printing a marker (re-exec check)
#   test-broken   current code + a migration that commits (bats_update_partial)
#                 then one that throws
#   test-sabotage bundle whose ops/migrate.sh wipes the restic snapshots then fails,
#                 so that the rollback itself cannot restore (exit 2)
# Images are tagged locally (tms-ci:<version>); TMS_PULL_POLICY=missing avoids a registry.

load helpers

setup_file() {
    export STACK_INSTALL=1
    stack_up
    export TMS_PULL_POLICY=missing
    build_test_images
    build_dist
    export TMS_DIST_URL="file://$STACK_DIR/dist"
}

teardown_file() {
    stack_down
    docker rmi tms-ci:test-ok tms-ci:test-broken tms-ci:test-sabotage >/dev/null 2>&1 || true
}

# A migration file (anonymous class) running one SQL statement or throwing.
write_migration() {
    local file="$1" body="$2"
    cat >"$file" <<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $body
    }
};
PHP
}

build_image() {
    local tag="$1" dir="$2"
    printf 'FROM tms-ci:bats\nCOPY --chown=www-data:www-data migrations/ database/migrations/\n' >"$dir/Dockerfile"
    docker build -q -t "tms-ci:$tag" "$dir" >&2
}

build_test_images() {
    local ok="$STACK_DIR/img-ok" broken="$STACK_DIR/img-broken"
    mkdir -p "$ok/migrations" "$broken/migrations"
    write_migration "$ok/migrations/2099_01_01_000001_bats_update_ok.php" \
        "DB::statement('create table bats_update_ok (id int)');"
    write_migration "$broken/migrations/2099_01_01_000001_bats_update_partial.php" \
        "DB::statement('create table bats_update_partial (id int)');"
    write_migration "$broken/migrations/2099_01_01_000002_bats_update_broken.php" \
        "throw new RuntimeException('migration volontairement cassée');"
    build_image test-ok "$ok"
    build_image test-broken "$broken"
    docker tag tms-ci:bats tms-ci:test-sabotage
}

# release_archive <dist dir> <version> [stage hook]: tms-<version>.tar.gz built by the real make-bundle.sh.
release_archive() {
    local dist="$1" version="$2" stage="$STACK_DIR/stage-$2"
    rm -rf "$stage"
    stage_src "$stage"
    printf '%s\n' "$version" >"$stage/docs/bats-release.txt"
    printf 'docs/bats-release.txt\n' >>"$stage/ops/release/files.txt"
    if [[ "$version" == "test-ok" ]]; then
        # The new release's own updater must be the one that runs.
        awk '{ print } /^source .*lib\.sh"$/ { print "log_info \"bats-reexec : programme de mise à jour de la version publiée\"" }' \
            "$TMS_ROOT/ops/update.sh" >"$stage/ops/update.sh"
        # New settings, required by the new compose file.
        printf 'BATS_NEW_KEY=valeur-par-defaut\nBATS_NEW_SECRET=__GENERATE__\nBATS_NEW_OPTIONAL=\n' >>"$stage/.env.prod.example"
        awk '{ print } /^x-app-env: &app-env$/ { print "  BATS_NEW_KEY: ${BATS_NEW_KEY:?BATS_NEW_KEY is required}"; print "  BATS_NEW_SECRET: ${BATS_NEW_SECRET:?BATS_NEW_SECRET is required}" }' \
            "$TMS_ROOT/compose.prod.yaml" >"$stage/compose.prod.yaml"
    fi
    if [[ "$version" == "test-sabotage" ]]; then
        cat >"$stage/ops/migrate.sh" <<'SH'
#!/usr/bin/env bash
# Sabotage: the pre-update snapshot disappears, then the "migration" fails.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
load_env
docker run --rm -v "$RESTIC_REPOSITORY:/repo" alpine sh -c 'rm -rf /repo/snapshots/*' >&2
exit 1
SH
        chmod 755 "$stage/ops/migrate.sh"
    fi
    mkdir -p "$dist"
    bundle_src "$stage" "$version" "$STACK_DIR/out-$version"
    cp "$STACK_DIR/out-$version/tms-$version.tar.gz" "$dist/"
}

build_dist() {
    local dist="$STACK_DIR/dist" latest="$STACK_DIR/dist-latest" v
    for v in test-ok test-broken test-sabotage; do
        release_archive "$dist" "$v"
    done
    (cd "$dist" && shasum -a 256 tms-*.tar.gz >SHA256SUMS)
    # A "latest" folder lists a single archive.
    mkdir -p "$latest"
    cp "$dist/tms-test-ok.tar.gz" "$latest/"
    (cd "$latest" && shasum -a 256 tms-test-ok.tar.gz >SHA256SUMS)
}

env_version() {
    sed -n 's/^TMS_VERSION=//p' "$TMS_ENV_FILE"
}

file_mode() {
    stat -c %a "$1" 2>/dev/null || stat -f %Lp "$1"
}

health() {
    compose exec -T caddy wget -q -O - http://127.0.0.1:8081/health
}

# `[[ ]]` checks end with `|| false`: bash 3.2 does not apply errexit to a failing `[[ ]]`.
refute() {
    if "$@"; then return 1; fi
}

@test "a running operation holds the lock: update and backup are refused" {
    perl -MFcntl=:flock -e 'open(my $f, ">>", $ARGV[0]) or die; flock($f, LOCK_EX) or die; sleep 120' \
        "$TMS_LOCK_FILE" >/dev/null 2>&1 3>&- &
    holder=$!
    sleep 1

    run "$TMS_DIR/ops/update.sh" --version test-ok
    update_status=$status
    update_output="$output"
    TMS_BACKUP_LOCK_WAIT=0 run "$TMS_DIR/ops/backup.sh"
    backup_status=$status
    backup_output="$output"
    kill "$holder"
    wait "$holder" 2>/dev/null || true

    [ "$update_status" -eq 1 ]
    [[ "$update_output" == *"Une autre opération"* ]] || false
    [ "$backup_status" -eq 1 ]
    [[ "$backup_output" == *"Une autre opération"* ]] || false
    [ "$(env_version)" = "bats" ]
}

@test "a failed backup cancels the update with nothing changed" {
    cp "$TMS_ENV_FILE" "$STACK_DIR/env.saved"
    sed -i.tmp -e 's/^RESTIC_PASSWORD=.*/RESTIC_PASSWORD=/' "$TMS_ENV_FILE"
    rm -f "$TMS_ENV_FILE.tmp"
    run "$TMS_DIR/ops/update.sh" --version test-ok
    cp "$STACK_DIR/env.saved" "$TMS_ENV_FILE"

    [ "$status" -eq 1 ]
    [[ "$output" == *"rien n'a été modifié"* ]] || false
    [ "$(env_version)" = "bats" ]
    [ ! -f "$TMS_DIR/docs/bats-release.txt" ]
    # the writers stopped for the backup are running again
    run health
    [ "$status" -eq 0 ]
    [[ "$output" == *'"status":"ok"'* ]] || false
    [[ "$output" == *'"version":"bats"'* ]] || false
}

@test "update rolls back on failed migration" {
    psql_owner "insert into companies (id, tenant_id, name, quote_prefix) values (gen_random_uuid(), current_setting('app.tenant_id')::uuid, 'bats-avant-maj', 'BATS')" >/dev/null
    compose exec -T app sh -c 'echo avant-maj > storage/app/bats-update.txt'
    cp "$TMS_DIR/compose.prod.yaml" "$STACK_DIR/compose.before"

    run "$TMS_DIR/ops/update.sh" --version test-broken
    [ "$status" -eq 1 ]
    [[ "$output" == *"Échec de la mise à jour : retour à bats"* ]] || false

    # previous version and bundle files
    grep -q "^TMS_VERSION=bats$" "$TMS_ENV_FILE"
    [ "$(file_mode "$TMS_ENV_FILE")" = "600" ]
    [ ! -f "$TMS_DIR/docs/bats-release.txt" ]
    cmp "$STACK_DIR/compose.before" "$TMS_DIR/compose.prod.yaml"
    [ -x "$TMS_DIR/ops/update.sh" ]

    # previous data: the row created before the update is there, the committed half
    # of the broken migration is gone
    [ "$(psql_owner "select count(*) from companies where name = 'bats-avant-maj'")" -eq 1 ]
    [ "$(psql_owner "select to_regclass('public.bats_update_partial') is null")" = "t" ]
    run compose exec -T app cat storage/app/bats-update.txt
    [ "$output" = "avant-maj" ]

    run health
    [ "$status" -eq 0 ]
    [[ "$output" == *'"status":"ok"'* ]] || false
    [[ "$output" == *'"version":"bats"'* ]] || false

    # the pre-update snapshot exists and is tagged
    load_env
    run restic_run -- snapshots --json --tag pre-update-bats
    [[ "$output" == *'"pre-update-bats"'* ]] || false
    [ ! -f "$TMS_DIR/.update-in-progress" ]
    grep -q "retour à bats effectué (code 1)" "$TMS_DIR/logs/update.log"
}

@test "a rollback that cannot restore exits 2 naming the snapshot and the manual command" {
    run "$TMS_DIR/ops/update.sh" --version test-sabotage
    [ "$status" -eq 2 ]
    [[ "$output" == *"ops/restore.sh "* ]] || false
    [[ "$output" =~ snapshot\ [0-9a-f]{8,} ]] || false
    # files and version were put back anyway; the state is kept for the next attempt
    [ "$(env_version)" = "bats" ]
    [ ! -f "$TMS_DIR/docs/bats-release.txt" ]
    grep -q "^SNAPSHOT=[0-9a-f]" "$TMS_DIR/.update-in-progress"
    grep -q "code 2" "$TMS_DIR/logs/update.log"
    # operator side: state cleared once the situation is handled
    rm -f "$TMS_DIR/.update-in-progress"

    # the migration never ran: restart the stack on the previous version for the next tests
    compose up -d --wait >&2
    wait_health 120 bats
}

@test "a hard kill during the switch is rolled back by the next run" {
    TMS_UPDATE_TEST_KILL=after-env run "$TMS_DIR/ops/update.sh" --version test-ok
    [ "$status" -eq 137 ]
    # killed with the new .env and files in place and the services stopped
    [ -f "$TMS_DIR/.update-in-progress" ]
    [ "$(env_version)" = "test-ok" ]

    run "$TMS_DIR/ops/update.sh" --version test-ok
    [ "$status" -eq 1 ]
    [[ "$output" == *"Mise à jour interrompue détectée"* ]] || false
    [[ "$output" == *"Échec de la mise à jour : retour à bats"* ]] || false
    [[ "$output" != *"déjà en version"* ]] || false
    [ ! -f "$TMS_DIR/.update-in-progress" ]
    [ "$(env_version)" = "bats" ]
    refute grep -q "^BATS_NEW_KEY=" "$TMS_ENV_FILE"
    [ "$(file_mode "$TMS_ENV_FILE")" = "600" ]
    [ ! -f "$TMS_DIR/docs/bats-release.txt" ]
    [ "$(psql_owner "select count(*) from companies where name = 'bats-avant-maj'")" -eq 1 ]
    run health
    [[ "$output" == *'"status":"ok"'* ]] || false
    [[ "$output" == *'"version":"bats"'* ]] || false
}

@test "update succeeds and records version" {
    run "$TMS_DIR/ops/update.sh" --version test-ok
    [ "$status" -eq 0 ]
    [[ "$output" == *"Mise à jour vers test-ok réussie"* ]] || false
    # the verified updater of the new release ran (re-exec)
    [[ "$output" == *"bats-reexec : programme de mise à jour de la version publiée"* ]] || false
    # new settings merged into .env: default copied, secret generated, empty one skipped
    grep -q "^BATS_NEW_KEY=valeur-par-defaut$" "$TMS_ENV_FILE"
    secret=$(sed -n 's/^BATS_NEW_SECRET=//p' "$TMS_ENV_FILE")
    [ "${#secret}" -ge 40 ]
    [ "$secret" != "__GENERATE__" ]
    refute grep -q "^BATS_NEW_OPTIONAL=" "$TMS_ENV_FILE"
    [[ "$output" != *"$secret"* ]] || false
    run compose exec -T app printenv BATS_NEW_KEY
    [ "$output" = "valeur-par-defaut" ]
    # previous .env kept, state cleared, outcome logged
    refute grep -q "^BATS_NEW_KEY=" "$TMS_DIR/.bundle-previous/.env"
    [ "$(file_mode "$TMS_DIR/.bundle-previous/.env")" = "600" ]
    [ ! -f "$TMS_DIR/.update-in-progress" ]
    grep -q "bats -> test-ok : réussie" "$TMS_DIR/logs/update.log"

    [ "$(env_version)" = "test-ok" ]
    [ "$(file_mode "$TMS_ENV_FILE")" = "600" ]
    [ "$(cat "$TMS_DIR/docs/bats-release.txt")" = "test-ok" ]
    [ "$(cat "$TMS_DIR/.bundle-previous/VERSION")" = "bats" ]
    [ -f "$TMS_DIR/.bundle-previous/compose.prod.yaml" ]
    [ ! -e "$TMS_DIR/.bundle-new" ]
    [ "$(psql_owner "select to_regclass('public.bats_update_ok') is not null")" = "t" ]
    [ "$(psql_owner "select count(*) from companies where name = 'bats-avant-maj'")" -eq 1 ]

    run health
    [[ "$output" == *'"status":"ok"'* ]] || false
    [[ "$output" == *'"version":"test-ok"'* ]] || false
    run compose ps --format '{{.Service}} {{.Image}}'
    [[ "$output" == *"app tms-ci:test-ok"* ]] || false
}

@test "updating to the installed version (latest) is refused unless --force" {
    TMS_DIST_URL="file://$STACK_DIR/dist-latest" run "$TMS_DIR/ops/update.sh"
    [ "$status" -eq 0 ]
    [[ "$output" == *"déjà en version test-ok"* ]] || false
    [[ "$output" != *"Sauvegarde avant mise à jour"* ]] || false
}

@test "a downgrade is refused unless --allow-downgrade" {
    run "$TMS_DIR/ops/update.sh" --version test-broken
    [ "$status" -eq 1 ]
    [[ "$output" == *"--allow-downgrade"* ]] || false
    [ "$(env_version)" = "test-ok" ]
    run health
    [[ "$output" == *'"version":"test-ok"'* ]] || false
}

@test "--bundle-dir is refused outside the re-exec" {
    run "$TMS_DIR/ops/update.sh" --version test-ok --bundle-dir /tmp
    [ "$status" -eq 1 ]
    [[ "$output" == *"--bundle-dir"* ]] || false
}

@test "update rejects a tampered archive before changing anything" {
    cp -R "$STACK_DIR/dist" "$STACK_DIR/dist-bad"
    echo corrompu >>"$STACK_DIR/dist-bad/tms-test-broken.tar.gz"
    TMS_DIST_URL="file://$STACK_DIR/dist-bad" run "$TMS_DIR/ops/update.sh" --version test-broken --allow-downgrade
    [ "$status" -eq 1 ]
    [[ "$output" == *"Somme de contrôle invalide"* ]] || false
    [ "$(env_version)" = "test-ok" ]
}
