#!/usr/bin/env bats
# Unit tests of ops/lib.sh helpers (no Docker).

setup() {
    # shellcheck source=../lib.sh
    source "$BATS_TEST_DIRNAME/../lib.sh"
    set +u
}

# refute <cmd...>: fails when the command succeeds (a bare `! cmd` never fails a bats test).
refute() {
    if "$@"; then return 1; fi
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

    # A truncated archive is refused: unreadable (1, bsdtar) or with repo/config missing (2, GNU
    # tar lists what it could read); restic check --read-data then guards the content.
    head -c 20000 "$BATS_TEST_TMPDIR/export.tar" >"$BATS_TEST_TMPDIR/truncated.tar"
    run export_archive_check "$BATS_TEST_TMPDIR/truncated.tar"
    [ "$status" -ne 0 ]
}

@test "import_mode_check: several or an unknown number of organismes refused on a single installation" {
    run import_mode_check multi 2 single
    [ "$status" -eq 1 ]
    [[ "$output" == *"Cet export contient plusieurs organismes : installez en mode multi-organismes."* ]] || false

    for tenants in "" "?" "abc"; do
        run import_mode_check multi "$tenants" single
        [ "$status" -eq 1 ]
        [[ "$output" == *"Nombre d'organismes de l'export inconnu : installez en mode multi-organismes ou vérifiez l'export."* ]] || false
        [[ "$output" != *"plusieurs organismes"* ]] || false
    done

    run import_mode_check multi 1 single
    [ "$status" -eq 0 ]
    [[ "$output" == *"deviendra l'organisme par défaut"* ]] || false
    run import_mode_check multi "" multi
    [ "$status" -eq 0 ]
    run import_mode_check single 1 multi
    [ "$status" -eq 0 ]
    [[ "$output" == *"associez un nom de domaine"* ]] || false
    run import_mode_check single 1 single
    [ "$status" -eq 0 ]
    [ -z "$output" ]
}

# sftp_stub [failing pattern]: docker replaced by a stub logging each call (one line of arguments)
# in $BATS_TEST_TMPDIR/docker.log; a call matching the pattern fails. Dedicated key (fake content)
# and known hosts in $BATS_TEST_TMPDIR, remote repository on a NAS.
sftp_stub() {
    mkdir -p "$BATS_TEST_TMPDIR/bin"
    cat >"$BATS_TEST_TMPDIR/bin/docker" <<SH
#!/usr/bin/env bash
printf '%s\n' "\$*" >>"$BATS_TEST_TMPDIR/docker.log"
[[ -z "${1:-}" || "\$*" != *"${1:-}"* ]]
SH
    chmod +x "$BATS_TEST_TMPDIR/bin/docker"
    PATH="$BATS_TEST_TMPDIR/bin:$PATH"
    printf -- '-----BEGIN OPENSSH PRIVATE KEY-----\ncle-privee-bats-ne-jamais-afficher\n-----END OPENSSH PRIVATE KEY-----\n' >"$BATS_TEST_TMPDIR/tms_nas"
    chmod 600 "$BATS_TEST_TMPDIR/tms_nas"
    echo "nas.example.lan ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIbats" >"$BATS_TEST_TMPDIR/tms_nas_known_hosts"
    export RESTIC_REPOSITORY="$BATS_TEST_TMPDIR/repo" RESTIC_PASSWORD="mot-de-passe-local-bats"
    export RESTIC_REMOTE_REPOSITORY="sftp:tms-backup@nas.example.lan:/tms-backups/tms"
    export RESTIC_REMOTE_SSH_KEY="$BATS_TEST_TMPDIR/tms_nas" RESTIC_REMOTE_SSH_KNOWN_HOSTS="$BATS_TEST_TMPDIR/tms_nas_known_hosts"
    unset RESTIC_REMOTE_ENV_FILE RESTIC_REMOTE_PASSWORD
}

SFTP_ARGS='sftp.args=-i /run/tms/ssh_key -o IdentitiesOnly=yes -o UserKnownHostsFile=/run/tms/known_hosts -o StrictHostKeyChecking=yes -o BatchMode=yes'

# every_call_is_strict_sftp: each logged restic call mounts the key and the known hosts read-only
# and passes the strict ssh options; nothing ever disables the host key check nor shows the key.
every_call_is_strict_sftp() {
    local line
    [ -s "$BATS_TEST_TMPDIR/docker.log" ]
    while IFS= read -r line; do
        [[ "$line" == *"-v $RESTIC_REMOTE_SSH_KEY:/run/tms/ssh_key:ro "* ]] || false
        [[ "$line" == *"-v $RESTIC_REMOTE_SSH_KNOWN_HOSTS:/run/tms/known_hosts:ro "* ]] || false
        [[ "$line" == *"restic/restic:0.18.0 --no-cache -o $SFTP_ARGS "* ]] || false
        [[ "$line" != *"StrictHostKeyChecking=no"* ]] || false
        [[ "$line" != *"cle-privee-bats"* ]] || false
    done <"$BATS_TEST_TMPDIR/docker.log"
}

@test "sftp copy: key and known hosts mounted read-only, host key checked strictly on every restic call" {
    sftp_stub
    run restic_remote_copy 0123abcd
    [ "$status" -eq 0 ]
    every_call_is_strict_sftp
    [ "$(wc -l <"$BATS_TEST_TMPDIR/docker.log" | tr -d ' ')" -eq 3 ]
    grep -q -- "--no-cache -o .* cat config$" "$BATS_TEST_TMPDIR/docker.log"
    grep -q -- "--no-cache -o .* copy 0123abcd$" "$BATS_TEST_TMPDIR/docker.log"
    grep -q -- "--no-cache -o .* forget --prune --host tms " "$BATS_TEST_TMPDIR/docker.log"
    # Passwords travel by name (-e), never as values; the local repository is the copy source.
    refute grep -qF "mot-de-passe-local-bats" "$BATS_TEST_TMPDIR/docker.log"
    grep -qF -- "-v $BATS_TEST_TMPDIR/repo:$BATS_TEST_TMPDIR/repo" "$BATS_TEST_TMPDIR/docker.log"
    [[ "$output" != *"cle-privee-bats"* ]] || false
}

@test "sftp copy: the first run creates the remote repository with the same options" {
    sftp_stub " cat config"
    run restic_remote_copy 0123abcd
    [ "$status" -eq 0 ]
    every_call_is_strict_sftp
    grep -q -- "--no-cache -o .* init --from-repo $BATS_TEST_TMPDIR/repo --copy-chunker-params$" "$BATS_TEST_TMPDIR/docker.log"
}

@test "sftp copy: a failing restic call fails the copy" {
    sftp_stub " copy 0123abcd"
    run restic_remote_copy 0123abcd
    [ "$status" -ne 0 ]
    refute grep -q " forget " "$BATS_TEST_TMPDIR/docker.log"
}

@test "sftp copy refuses a missing key or known hosts file, French message, nothing run, key never shown" {
    sftp_stub
    RESTIC_REMOTE_SSH_KEY="" run restic_remote_copy 0123abcd
    [ "$status" -ne 0 ]
    [[ "$output" == *"Copie SFTP : RESTIC_REMOTE_SSH_KEY (chemin de la clé SSH dédiée) manquant"* ]] || false

    RESTIC_REMOTE_SSH_KEY="$BATS_TEST_TMPDIR/absente" run restic_remote_copy 0123abcd
    [ "$status" -ne 0 ]
    [[ "$output" == *"Copie SFTP : clé SSH introuvable ou illisible (RESTIC_REMOTE_SSH_KEY=$BATS_TEST_TMPDIR/absente)."* ]] || false

    RESTIC_REMOTE_SSH_KNOWN_HOSTS="" run restic_remote_copy 0123abcd
    [ "$status" -ne 0 ]
    [[ "$output" == *"Copie SFTP : RESTIC_REMOTE_SSH_KNOWN_HOSTS (empreintes du serveur distant, ssh-keyscan) manquant"* ]] || false
    [[ "$output" != *"cle-privee-bats"* ]] || false

    RESTIC_REMOTE_SSH_KNOWN_HOSTS="$BATS_TEST_TMPDIR/absent" run restic_remote_copy 0123abcd
    [ "$status" -ne 0 ]
    [[ "$output" == *"Copie SFTP : fichier d'empreintes introuvable ou illisible"* ]] || false
    [[ "$output" != *"cle-privee-bats"* ]] || false

    [ ! -e "$BATS_TEST_TMPDIR/docker.log" ]
}

@test "non-sftp copy (S3): no SSH mount nor sftp option, credentials file passed to docker" {
    sftp_stub
    RESTIC_REMOTE_REPOSITORY="s3:https://s3.example.test/bucket/tms" RESTIC_REMOTE_ENV_FILE="$BATS_TEST_TMPDIR/remote.env" \
        RESTIC_REMOTE_SSH_KEY="" RESTIC_REMOTE_SSH_KNOWN_HOSTS="" run restic_remote_copy 0123abcd
    [ "$status" -eq 0 ]
    refute grep -q "ssh_key\|sftp.args" "$BATS_TEST_TMPDIR/docker.log"
    [ "$(grep -c -- "--env-file $BATS_TEST_TMPDIR/remote.env" "$BATS_TEST_TMPDIR/docker.log")" -eq 3 ]
}
