#!/usr/bin/env bats
# Unit tests of ops/lib.sh helpers (no Docker).

setup() {
    # shellcheck source=../lib.sh
    source "$BATS_TEST_DIRNAME/../lib.sh"
    set +u
}

lt() {
    version_lt "$1" "$2" || { echo "attendu : $1 < $2" >&2; return 1; }
    if version_lt "$2" "$1"; then echo "attendu : non ($2 < $1)" >&2; return 1; fi
}

@test "versions follow semantic versioning precedence" {
    lt 0.1.0-rc.1 0.1.0
    lt 0.1.0-rc.1 0.1.0-rc.2
    lt 0.1.0-rc.9 0.1.0-rc.10
    lt 1.9.0 1.10.0
    lt 1.0.0-alpha 1.0.0-alpha.1
    lt 1.0.0-alpha.1 1.0.0-alpha.beta
    lt 1.0.0-alpha.beta 1.0.0-beta
    lt 1.0.0-beta.11 1.0.0-rc.1
    lt 0.9.9 1.0.0-rc.1
    lt 2.0.0 10.0.0
    [ "$(semver_cmp 1.2.3 1.2.3)" = "0" ]
    [ "$(semver_cmp 1.2.3+build.5 1.2.3)" = "0" ]
    [ "$(semver_cmp 1.2.03 1.2.3)" = "0" ]
}

@test "non-semver test versions keep a stable order" {
    lt bats test-ok
    lt test-broken test-ok
    lt bats test-sabotage
}

# make_repo_tar <dir> <files> [extra path]: a tar of repo/ (config + <files> long-named files),
# optionally with an extra file outside repo/ at the end.
make_repo_tar() {
    local dir="$1" i
    mkdir -p "$dir/src/repo/data/00"
    echo config >"$dir/src/repo/config"
    for ((i = 0; i < $2; i++)); do
        echo "$i" >"$dir/src/repo/data/00/$(printf 'pack-%04d-%s' "$i" "$(printf 'a%.0s' {1..90})")"
    done
    if [[ -n "${3:-}" ]]; then
        mkdir -p "$dir/src/$(dirname "$3")"
        echo x >"$dir/src/$3"
    fi
    (cd "$dir/src" && tar -cf "$dir/export.tar" repo ${3:+"$3"})
}

@test "export_archive_check accepts a large repository listing (more than 64 KB, no SIGPIPE)" {
    make_repo_tar "$BATS_TEST_TMPDIR" 1200
    [ "$(tar -tvf "$BATS_TEST_TMPDIR/export.tar" | wc -c)" -gt 65536 ]
    run export_archive_check "$BATS_TEST_TMPDIR/export.tar"
    [ "$status" -eq 0 ]
}

@test "export_archive_check refuses content outside repo/ and a truncated tar" {
    make_repo_tar "$BATS_TEST_TMPDIR" 1200 zzz/autre
    run export_archive_check "$BATS_TEST_TMPDIR/export.tar"
    [ "$status" -eq 2 ]

    head -c 20000 "$BATS_TEST_TMPDIR/export.tar" >"$BATS_TEST_TMPDIR/truncated.tar"
    run export_archive_check "$BATS_TEST_TMPDIR/truncated.tar"
    [ "$status" -eq 1 ]
}
