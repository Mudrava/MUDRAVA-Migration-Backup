#!/usr/bin/env bash
#
# Build the distributable plugin zip (what gets uploaded to WP.org / GitHub
# releases). Dev-only files (tests, composer dev deps, tooling configs) are
# excluded so the archive contains exactly what a site needs to run.
#
# Usage: bin/dist.sh [version] [clean|with-pro]
# clean (default) ships no optional premium descriptions.
set -euo pipefail

cd "$(dirname "$0")/.."

VERSION="${1:-$(awk '/^ \* Version:/ {print $3; exit}' mudrava-migration-backup.php)}"
PROFILE="${2:-clean}"
case "${PROFILE}" in
    clean) SUFFIX="" ;;
    with-pro) SUFFIX="-with-pro" ;;
    *) echo "unknown build profile: ${PROFILE}" >&2; exit 1 ;;
esac
SLUG="mudrava-migration-backup"
OUT="dist"
STAGE="${OUT}/${SLUG}"
BUILD_EPOCH="${SOURCE_DATE_EPOCH:-$(git log -1 --format=%ct -- \
    mudrava-migration-backup.php uninstall.php readme.txt LICENSE \
    includes assets languages/index.php bin/make-pot.php bin/make-pot.sh \
    bin/normalize-mtime.php bin/dist.sh 2>/dev/null || true)}"
if [[ ! "${BUILD_EPOCH}" =~ ^[0-9]+$ ]]; then
    BUILD_EPOCH="1767225600"
fi

rm -rf "${STAGE}"
mkdir -p "${STAGE}"

# Whitelist: only ship what the plugin needs at runtime.
cp mudrava-migration-backup.php uninstall.php readme.txt "${STAGE}/"
cp -R includes assets languages "${STAGE}/"
rm -f "${STAGE}/languages/.gitkeep"
[ -f LICENSE ] && cp LICENSE "${STAGE}/"

if [[ "${PROFILE}" == "clean" ]]; then
    rm -f "${STAGE}/includes/Admin/ProPreview.php"
fi

# Generate the shipped catalog inside staging. A release build must not
# modify the tracked source catalog or leave the working tree dirty.
SOURCE_DATE_EPOCH="${BUILD_EPOCH}" \
    MUDRAVA_POT_SOURCE="${PWD}/${STAGE}" \
    MUDRAVA_POT_OUTPUT="${PWD}/${STAGE}/languages/mudrava-migration-backup.pot" \
    php bin/make-pot.php

php bin/normalize-mtime.php "${STAGE}" "${BUILD_EPOCH}"

# Composer autoloader is NOT required at runtime (the plugin ships its own
# PSR-4 autoloader); dev dependencies never ship.

cd "${OUT}"
# zip updates existing archives in place, retaining entries removed from the
# staging tree. Start fresh so an old hidden/dev file cannot survive a build.
rm -f "${SLUG}-${VERSION}${SUFFIX}.zip"
find "${SLUG}" -type f ! -name '*.DS_Store' -print \
    | LC_ALL=C sort \
    | zip -Xq "${SLUG}-${VERSION}${SUFFIX}.zip" -@
cd ..
rm -rf "${STAGE}"

echo "built ${OUT}/${SLUG}-${VERSION}${SUFFIX}.zip"
unzip -l "${OUT}/${SLUG}-${VERSION}${SUFFIX}.zip" | tail -1
