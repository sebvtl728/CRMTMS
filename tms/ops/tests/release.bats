#!/usr/bin/env bats
# ops/release/make-bundle.sh: deterministic bundle, file list in sync with lib.sh, SHA256SUMS.
# No Docker, no network.

TMS_ROOT="$(cd "$BATS_TEST_DIRNAME/../.." && pwd)"
MAKE_BUNDLE="$TMS_ROOT/ops/release/make-bundle.sh"
DIGEST="sha256:$(printf 'a%.0s' {1..64})"

list_files() {
    sed -e 's/#.*//' -e 's/[[:space:]]//g' "$TMS_ROOT/ops/release/files.txt" | grep -v '^$' | LC_ALL=C sort
}

@test "bundle is byte-for-byte reproducible" {
    "$MAKE_BUNDLE" 1.2.3 "$BATS_TEST_TMPDIR/a"
    sleep 1.1
    "$MAKE_BUNDLE" 1.2.3 "$BATS_TEST_TMPDIR/b"
    cmp "$BATS_TEST_TMPDIR/a/tms-1.2.3.tar.gz" "$BATS_TEST_TMPDIR/b/tms-1.2.3.tar.gz"
    cmp "$BATS_TEST_TMPDIR/a/SHA256SUMS" "$BATS_TEST_TMPDIR/b/SHA256SUMS"
}

@test "archive holds exactly files.txt, sorted, at the root, with fixed owner and mtime" {
    "$MAKE_BUNDLE" 1.2.3 "$BATS_TEST_TMPDIR/out"
    run tar -tzf "$BATS_TEST_TMPDIR/out/tms-1.2.3.tar.gz"
    [ "$status" -eq 0 ]
    [ "$output" = "$(list_files)" ]
    run tar -tvzf "$BATS_TEST_TMPDIR/out/tms-1.2.3.tar.gz"
    [[ "$output" != *"$USER"* || "$USER" == root ]] || false
    [[ "$output" == *"1980"* ]] || false
}

@test "files.txt covers every file that lib.sh fetch_bundle requires" {
    required="$(sed -n '/^    for f in compose.prod.yaml/,/; do$/p' "$TMS_ROOT/ops/lib.sh" | tr -d '\\' | sed 's/^ *for f in//; s/; do$//' | tr -s ' \n' '\n\n' | grep -v '^$' | LC_ALL=C sort)"
    [ -n "$required" ]
    run comm -23 <(printf '%s\n' "$required") <(list_files)
    [ "$status" -eq 0 ]
    [ -z "$output" ]
    # And nothing beyond the required files plus the documentation.
    run comm -13 <(printf '%s\n' "$required") <(list_files)
    [ "$output" = "docs/install.md" ]
}

@test "SHA256SUMS lists the archive, install.sh and proxmox.sh and verifies" {
    "$MAKE_BUNDLE" 1.2.3 "$BATS_TEST_TMPDIR/out"
    cd "$BATS_TEST_TMPDIR/out"
    [ "$(awk '{print $2}' SHA256SUMS | LC_ALL=C sort | tr '\n' ' ')" = "install.sh proxmox.sh tms-1.2.3.tar.gz " ]
    cmp install.sh "$TMS_ROOT/ops/install/install.sh"
    cmp proxmox.sh "$TMS_ROOT/ops/install/proxmox.sh"
    if command -v sha256sum >/dev/null; then sha256sum -c SHA256SUMS; else shasum -a 256 -c SHA256SUMS; fi
}

@test "bundle passes the installer's own extraction checks" {
    source "$TMS_ROOT/ops/lib.sh"
    set +u
    "$MAKE_BUNDLE" 1.2.3 "$BATS_TEST_TMPDIR/out"
    export TMS_DIST_URL="file://$BATS_TEST_TMPDIR/out" TMS_VERSION=1.2.3
    mkdir -p "$BATS_TEST_TMPDIR/work"
    fetch_bundle "$BATS_TEST_TMPDIR/work"
    [ -x "$BUNDLE_ROOT/ops/update.sh" ]
    TMS_VERSION=latest
    rm -rf "$BATS_TEST_TMPDIR/work" && mkdir -p "$BATS_TEST_TMPDIR/work"
    fetch_bundle "$BATS_TEST_TMPDIR/work"
    [ "$TMS_VERSION" = "1.2.3" ]
    [ -z "$BUNDLE_IMAGE_DIGEST" ]
}

@test "release.env carries the image digest of the version" {
    "$MAKE_BUNDLE" 1.2.3 "$BATS_TEST_TMPDIR/out" "$DIGEST"
    run tar -xOzf "$BATS_TEST_TMPDIR/out/tms-1.2.3.tar.gz" release.env
    [ "$status" -eq 0 ]
    [[ "$output" == *"TMS_IMAGE_DIGEST=$DIGEST"* ]] || false

    source "$TMS_ROOT/ops/lib.sh"
    set +u
    export TMS_DIST_URL="file://$BATS_TEST_TMPDIR/out" TMS_VERSION=1.2.3
    mkdir -p "$BATS_TEST_TMPDIR/work"
    fetch_bundle "$BATS_TEST_TMPDIR/work"
    [ "$BUNDLE_IMAGE_DIGEST" = "$DIGEST" ]
}

@test "without a digest, release.env pins nothing (local and test builds)" {
    "$MAKE_BUNDLE" 1.2.3 "$BATS_TEST_TMPDIR/out"
    run tar -xOzf "$BATS_TEST_TMPDIR/out/tms-1.2.3.tar.gz" release.env
    [ "$status" -eq 0 ]
    [[ "$output" == *"TMS_IMAGE_DIGEST="* ]] || false
    [[ "$output" != *"sha256:"* ]] || false
}

@test "a malformed digest is rejected by make-bundle and by the installer" {
    run "$MAKE_BUNDLE" 1.2.3 "$BATS_TEST_TMPDIR/out" "sha256:nope"
    [ "$status" -eq 2 ]

    source "$TMS_ROOT/ops/lib.sh"
    set +u
    mkdir -p "$BATS_TEST_TMPDIR/b"
    printf 'TMS_IMAGE_DIGEST=latest\n' >"$BATS_TEST_TMPDIR/b/release.env"
    run image_digest_of "$BATS_TEST_TMPDIR/b"
    [ "$status" -ne 0 ]
    printf 'TMS_IMAGE_DIGEST=%s\n' "$DIGEST" >"$BATS_TEST_TMPDIR/b/release.env"
    run image_digest_of "$BATS_TEST_TMPDIR/b"
    [ "$status" -eq 0 ]
    [ "$output" = "$DIGEST" ]
}

@test "compose pulls the application image by digest when one is set" {
    command -v docker >/dev/null || skip "docker absent"
    local env=(TMS_IMAGE=ghcr.io/example/tms TMS_VERSION=1.2.3 APP_KEY=k TMS_DOMAIN=t.test POSTGRES_APP_PASSWORD=p
        REDIS_PASSWORD=r TMS_ACME_EMAIL=a@t.test POSTGRES_SUPERUSER_PASSWORD=s POSTGRES_OWNER_PASSWORD=o)
    run env "${env[@]}" TMS_IMAGE_DIGEST="$DIGEST" docker compose -f "$TMS_ROOT/compose.prod.yaml" --env-file /dev/null config --images
    [ "$status" -eq 0 ]
    [[ "$output" == *"ghcr.io/example/tms:1.2.3@$DIGEST"* ]] || false
    run env "${env[@]}" TMS_IMAGE_DIGEST= docker compose -f "$TMS_ROOT/compose.prod.yaml" --env-file /dev/null config --images
    [ "$status" -eq 0 ]
    [[ "$output" == *"ghcr.io/example/tms:1.2.3"* && "$output" != *"@sha256"* ]] || false
}

@test "invalid version or missing arguments are rejected" {
    run "$MAKE_BUNDLE"
    [ "$status" -eq 2 ]
    run "$MAKE_BUNDLE" "../x" "$BATS_TEST_TMPDIR/out"
    [ "$status" -eq 2 ]
}

@test "a file listed but missing makes the build fail" {
    src="$BATS_TEST_TMPDIR/src"
    mkdir -p "$src/ops/release" "$src/ops/install"
    echo "nope.txt" >"$src/ops/release/files.txt"
    touch "$src/ops/install/install.sh" "$src/ops/install/proxmox.sh"
    TMS_BUNDLE_SRC="$src" run "$MAKE_BUNDLE" 1.0.0 "$BATS_TEST_TMPDIR/out"
    [ "$status" -ne 0 ]
    [[ "$output" == *"nope.txt"* ]] || false
}
