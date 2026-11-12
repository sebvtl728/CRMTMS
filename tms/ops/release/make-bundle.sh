#!/usr/bin/env bash
# Builds the release files of a version in <outdir>:
#   tms-<version>.tar.gz   deterministic bundle (files listed in ops/release/files.txt, at the
#                          root of the archive; sorted names, fixed mtime/owner/modes, no gzip name)
#   install.sh, proxmox.sh copied from ops/install/
#   SHA256SUMS             sha256sum format, lists the three files above
# Used by .github/workflows/release.yml and by the bats helpers.
#
# Usage: make-bundle.sh <version> <outdir>
# TMS_BUNDLE_SRC overrides the source tree (default: the tms/ directory of this script);
# the bats tests use it to bundle a modified copy.
set -euo pipefail

version="${1:-}" outdir="${2:-}"
[[ -n "$version" && -n "$outdir" ]] || { echo "usage: make-bundle.sh <version> <outdir>" >&2; exit 2; }
[[ "$version" =~ ^[0-9A-Za-z][0-9A-Za-z.+-]*$ ]] || { echo "version invalide : $version" >&2; exit 2; }

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
src="${TMS_BUNDLE_SRC:-$(cd "$here/../.." && pwd)}"
list="$src/ops/release/files.txt"
[[ -f "$list" ]] || { echo "liste introuvable : $list" >&2; exit 1; }

files=()
while IFS= read -r line; do
    line="${line%%#*}"
    line="${line//[[:space:]]/}"
    [[ -n "$line" ]] || continue
    [[ "$line" != /* && "$line" != *..* ]] || { echo "chemin refusé : $line" >&2; exit 1; }
    [[ -f "$src/$line" ]] || { echo "fichier manquant : $line" >&2; exit 1; }
    files+=("$line")
done <"$list"
for f in ops/install/install.sh ops/install/proxmox.sh; do
    [[ -f "$src/$f" ]] || { echo "fichier manquant : $f" >&2; exit 1; }
done
# Sort in the C locale: identical order everywhere.
sorted=()
while IFS= read -r line; do sorted+=("$line"); done < <(printf '%s\n' "${files[@]}" | LC_ALL=C sort -u)

mkdir -p "$outdir"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
for f in "${sorted[@]}"; do
    mkdir -p "$stage/$(dirname "$f")"
    cp "$src/$f" "$stage/$f"
    if [[ "$f" == *.sh ]]; then chmod 755 "$stage/$f"; else chmod 644 "$stage/$f"; fi
    TZ=UTC touch -t 198001010000 "$stage/$f"
done
printf '%s\n' "${sorted[@]}" >"$stage/.list"

archive="$outdir/tms-$version.tar.gz"
export COPYFILE_DISABLE=1
if tar --version 2>/dev/null | grep -q 'GNU tar'; then
    (cd "$stage" && tar --format=ustar --mtime='1980-01-01 00:00:00 UTC' --owner=0 --group=0 --numeric-owner \
        --no-recursion -cf - -T .list) | gzip -9n >"$archive"
else
    (cd "$stage" && tar --format ustar --uid 0 --gid 0 --uname root --gname root --no-recursion \
        -cf - -T .list) | gzip -9n >"$archive"
fi

cp "$src/ops/install/install.sh" "$src/ops/install/proxmox.sh" "$outdir/"
(
    cd "$outdir"
    sums=(install.sh proxmox.sh "tms-$version.tar.gz")
    if command -v sha256sum >/dev/null 2>&1; then sha256sum "${sums[@]}"; else shasum -a 256 "${sums[@]}"; fi >SHA256SUMS
)
echo "Version $version : $archive (${#sorted[@]} fichiers)" >&2
