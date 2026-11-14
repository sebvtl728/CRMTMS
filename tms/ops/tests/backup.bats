#!/usr/bin/env bats
# Integration tests of ops/backup.sh and ops/restore.sh against a throwaway
# production-like stack (see helpers.bash).

load helpers

setup_file() {
    stack_up
}

teardown_file() {
    stack_down
}

@test "backup then restore brings data back" {
    compose exec -T app sh -c 'echo marker-before > storage/app/bats-marker.txt'

    run "$TMS_ROOT/ops/backup.sh" --tag test
    [ "$status" -eq 0 ]
    snap="${lines[${#lines[@]}-1]}"
    [[ "$snap" =~ ^[0-9a-f]{8,64}$ ]]

    before=$(psql_owner "select count(*) from companies")
    [ "$before" -ge 1 ]

    psql_owner "delete from companies" >/dev/null
    [ "$(psql_owner 'select count(*) from companies')" -eq 0 ]
    compose exec -T app rm storage/app/bats-marker.txt

    run "$TMS_ROOT/ops/restore.sh" "$snap"
    [ "$status" -eq 0 ]

    count=$(psql_owner "select count(*) from companies")
    [ "$count" -ge 1 ]
    [ "$count" -eq "$before" ]

    run compose exec -T app cat storage/app/bats-marker.txt
    [ "$status" -eq 0 ]
    [ "$output" = "marker-before" ]

    # The stack is healthy again and the runtime role (RLS, not owner) still works.
    run compose exec -T caddy wget -q -O - http://127.0.0.1:8081/health
    [ "$status" -eq 0 ]
    [[ "$output" == *'"status":"ok"'* ]]
    run compose exec -T app php artisan migrate:status
    [ "$status" -eq 0 ]
}

@test "snapshots are tagged and retention flags are applied" {
    run "$TMS_ROOT/ops/backup.sh" --tag nightly
    [ "$status" -eq 0 ]
    snap="${lines[${#lines[@]}-1]}"

    load_env
    run restic_run -- snapshots --json
    [ "$status" -eq 0 ]
    [[ "$output" == *'"nightly"'* ]]
    [[ "$output" == *'"hostname":"tms"'* ]]
    [[ "$output" == *"${snap:0:8}"* ]]
}

@test "restore latest works" {
    run "$TMS_ROOT/ops/restore.sh" latest
    [ "$status" -eq 0 ]
}

@test "restore refuses an unknown snapshot and leaves the stack untouched" {
    run "$TMS_ROOT/ops/restore.sh" deadbeef
    [ "$status" -ne 0 ]
    run compose exec -T caddy wget -q -O - http://127.0.0.1:8081/health
    [ "$status" -eq 0 ]
}

@test "backup fails loudly without RESTIC_PASSWORD" {
    grep -v '^RESTIC_PASSWORD=' "$TMS_ENV_FILE" >"$STACK_DIR/env.nopass"
    TMS_ENV_FILE="$STACK_DIR/env.nopass" run "$TMS_ROOT/ops/backup.sh"
    [ "$status" -ne 0 ]
    [[ "$output" == *"RESTIC_PASSWORD"* ]]
}

@test "only caddy publishes ports; postgres and redis are not exposed" {
    # JSON, not the text of {{.Publishers}}: its rendering changes between Compose versions.
    # One "service url:published->target/proto" line per published port (array or NDJSON).
    local ps
    ps=$(compose ps --format json)
    # shellcheck disable=SC2016  # jq variables, not shell ones
    run jq -r 'if type == "array" then .[] else . end
        | .Service as $s | (.Publishers // [])[] | select(.PublishedPort > 0)
        | "\($s) \(.URL):\(.PublishedPort)->\(.TargetPort)/\(.Protocol)"' <<<"$ps"
    [ "$status" -eq 0 ]
    [[ "$output" == *"caddy 0.0.0.0:18443->443/tcp"* ]] || false
    while IFS= read -r line; do
        [[ "$line" == "caddy "* ]] || false
    done <<<"$output"
}

@test "behind caddy the request is HTTPS (HSTS, secure cookies) and the app runs in production mode" {
    run curl -ksI https://localhost:18443/login
    [ "$status" -eq 0 ]
    echo "$output" | grep -qi "^strict-transport-security: max-age=31536000"
    echo "$output" | grep -qi "^set-cookie: tms_session=.*secure"

    # Static assets come from the volume shared with the app container.
    css=$(curl -ks https://localhost:18443/login | grep -o '/build/assets/app-[^"]*\.css' | head -n 1)
    [ -n "$css" ]
    run curl -ksI "https://localhost:18443$css"
    [[ "$output" == *" 200"* ]]

    run compose exec -T app php artisan about --json
    [ "$status" -eq 0 ]
    [[ "$output" == *'"environment":"production"'* ]]
    [[ "$output" == *'"debug_mode":false'* ]]
}

@test "owner credentials are not in the long-running containers" {
    for svc in app worker scheduler; do
        run compose exec -T "$svc" printenv DB_OWNER_PASSWORD
        [ -z "$output" ]
        run compose exec -T "$svc" printenv DB_OWNER_USERNAME
        [ -z "$output" ]
    done
}

@test "restore drops objects created after the snapshot, even with a foreign key to a restored table" {
    run "$TMS_ROOT/ops/backup.sh"
    [ "$status" -eq 0 ]
    snap="${lines[${#lines[@]}-1]}"

    psql_owner "create table bats_extra (id serial primary key, company_id uuid references companies(id))" >/dev/null
    [ "$(psql_owner "select to_regclass('public.bats_extra') is not null")" = "t" ]

    run "$TMS_ROOT/ops/restore.sh" "$snap"
    [ "$status" -eq 0 ]
    [ "$(psql_owner "select to_regclass('public.bats_extra') is null")" = "t" ]
    [ "$(psql_owner 'select count(*) from companies')" -ge 1 ]
}

@test "snapshots carry the version tag" {
    load_env
    run restic_run -- snapshots --json
    [[ "$output" == *'"version:bats"'* ]]
}

@test "backup still succeeds with the app container stopped" {
    compose stop app worker scheduler >&2
    run "$TMS_ROOT/ops/backup.sh"
    status_backup=$status
    compose up -d --wait >&2
    [ "$status_backup" -eq 0 ]
}

@test "failure alert falls back to a one-off container when app is down" {
    sed -e '/^RESTIC_PASSWORD=/d' "$TMS_ENV_FILE" >"$STACK_DIR/env.alert"
    echo "TMS_ALERT_EMAIL=ops@example.test" >>"$STACK_DIR/env.alert"
    compose stop app >&2
    TMS_ENV_FILE="$STACK_DIR/env.alert" run "$TMS_ROOT/ops/backup.sh"
    compose up -d --wait >&2
    [ "$status" -ne 0 ]
    [[ "$output" == *"RESTIC_PASSWORD"* ]]
    [[ "$output" == *"Alerte envoyée par e-mail à ops@example.test"* ]]
}

@test "APP_KEY travels with the snapshot: refused when different, adopted with --with-app-key" {
    key_script='require "vendor/autoload.php"; $a = require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
    run compose exec -T app php -r "$key_script"' echo encrypt("secret-ok");'
    [ "$status" -eq 0 ]
    enc="$output"
    compose exec -T app sh -c "printf '%s' '$enc' > storage/app/enc.txt"

    run "$TMS_ROOT/ops/backup.sh"
    [ "$status" -eq 0 ]
    snap="${lines[${#lines[@]}-1]}"

    # A "fresh server": another APP_KEY.
    old_key=$(sed -n 's/^APP_KEY=//p' "$TMS_ENV_FILE")
    sed -i.bak "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" "$TMS_ENV_FILE"
    rm -f "$TMS_ENV_FILE.bak"
    compose up -d --wait >&2

    run "$TMS_ROOT/ops/restore.sh" "$snap"
    [ "$status" -ne 0 ]
    [[ "$output" == *"APP_KEY"* ]]
    [[ "$output" == *"--with-app-key"* ]]

    new_key=$(sed -n 's/^APP_KEY=//p' "$TMS_ENV_FILE")
    run "$TMS_ROOT/ops/restore.sh" --with-app-key "$snap"
    [ "$status" -eq 0 ]
    [ "$(sed -n 's/^APP_KEY=//p' "$TMS_ENV_FILE")" = "$old_key" ]
    # the replaced .env is kept beside it, private
    bak=$(ls "$TMS_ENV_FILE".bak-* | tail -n 1)
    [ -f "$bak" ]
    [ "$(sed -n 's/^APP_KEY=//p' "$bak")" = "$new_key" ]
    [ "$(stat -c %a "$bak" 2>/dev/null || stat -f %Lp "$bak")" = "600" ]
    [ "$(stat -c %a "$TMS_ENV_FILE" 2>/dev/null || stat -f %Lp "$TMS_ENV_FILE")" = "600" ]

    run compose exec -T app php -r "$key_script"' echo decrypt(file_get_contents("storage/app/enc.txt"));'
    [ "$status" -eq 0 ]
    [ "$output" = "secret-ok" ]
}

@test "--with-app-key leaves .env untouched when the database restore fails" {
    # A snapshot with an unreadable dump and another APP_KEY.
    load_env
    bad="$STACK_DIR/bad-snapshot"
    mkdir -p "$bad"
    echo "pas un dump" >"$bad/db.dump"
    tar -cf "$bad/storage-app.tar" -C "$bad" db.dump
    printf 'APP_KEY=base64:%s\nTMS_VERSION=bats\n' "$(openssl rand -base64 32)" >"$bad/meta.env"
    run restic_run -v "$bad:/backup-data:ro" -- backup --host tms --json /backup-data
    [ "$status" -eq 0 ]
    snap=$(printf '%s\n' "$output" | sed -n 's/.*"snapshot_id":"\([0-9a-f]*\)".*/\1/p' | tail -n 1)
    [ -n "$snap" ]

    cp "$TMS_ENV_FILE" "$STACK_DIR/env.before"
    run "$TMS_ROOT/ops/restore.sh" --with-app-key "$snap"
    restore_status=$status
    compose up -d --wait >&2
    [ "$restore_status" -ne 0 ]
    cmp "$STACK_DIR/env.before" "$TMS_ENV_FILE"
}

@test "restore applies the dumped privileges without widening them" {
    # Narrow the default privileges of tms_app before the snapshot (no DELETE on new tables).
    psql_owner "alter default privileges for role tms_owner in schema public revoke delete on tables from tms_app" >/dev/null
    before=$(psql_owner "select coalesce(string_agg(defaclacl::text, ',' order by defaclobjtype), '') from pg_default_acl")
    grants_before=$(psql_owner "select count(*) from information_schema.role_table_grants where grantee = 'tms_app'")

    run "$TMS_ROOT/ops/backup.sh"
    [ "$status" -eq 0 ]
    snap="${lines[${#lines[@]}-1]}"
    run "$TMS_ROOT/ops/restore.sh" "$snap"
    [ "$status" -eq 0 ]

    after=$(psql_owner "select coalesce(string_agg(defaclacl::text, ',' order by defaclobjtype), '') from pg_default_acl")
    [ "$after" = "$before" ]
    [ "$(psql_owner "select count(*) from information_schema.role_table_grants where grantee = 'tms_app'")" -eq "$grants_before" ]
    [ "$(psql_owner "select has_schema_privilege('tms_app', 'public', 'USAGE')")" = "t" ]
    [ "$(psql_owner "select has_schema_privilege('tms_app', 'public', 'CREATE')")" = "f" ]

    psql_owner "alter default privileges for role tms_owner in schema public grant delete on tables to tms_app" >/dev/null
}
