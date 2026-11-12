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
    run compose ps --format '{{.Service}} {{.Publishers}}'
    [ "$status" -eq 0 ]
    [ -n "$output" ]
    while IFS= read -r line; do
        case "$line" in
            caddy*) [[ "$line" == *"0.0.0.0:18443->443/tcp"* ]] ;;
            *) [[ "$line" != *"0.0.0.0"* && "$line" != *"[::]"* ]] ;;
        esac
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
