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
