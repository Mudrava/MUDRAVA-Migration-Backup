#!/usr/bin/env bash
#
# Prove that two clean builds from the same source produce identical ZIP bytes.
# This catches timestamps, entry-order changes, and host metadata leaks.
set -euo pipefail

cd "$(dirname "$0")/.."

VERSION="${1:-$(awk '/^ \* Version:/ {print $3; exit}' mudrava-migration-backup.php)}"
PROFILE="${2:-clean}"
SUFFIX=""
[[ "${PROFILE}" == "with-pro" ]] && SUFFIX="-with-pro"
ZIP="dist/mudrava-migration-backup-${VERSION}${SUFFIX}.zip"
TMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/mudrava-repro.XXXXXX")"
trap 'rm -rf "${TMP_ROOT}"' EXIT

bash bin/dist.sh "${VERSION}" "${PROFILE}" >/dev/null
cp "${ZIP}" "${TMP_ROOT}/first.zip"
bash bin/dist.sh "${VERSION}" "${PROFILE}" >/dev/null

if ! cmp -s "${TMP_ROOT}/first.zip" "${ZIP}"; then
    echo "distribution build is not reproducible" >&2
    sha256sum "${TMP_ROOT}/first.zip" "${ZIP}" >&2
    exit 1
fi

echo "distribution build is byte-for-byte reproducible"
sha256sum "${ZIP}"
