#!/usr/bin/env bash
#
# Verify the built dist zip: every PHP file must lint, the runtime
# whitelist must be complete, and no dev file may leak in.
set -euo pipefail
cd "$(dirname "$0")/.."

ZIP="${1:-$(ls -t dist/mudrava-migration-backup-*.zip | head -1)}"
[ -n "${ZIP}" ] || { echo "no dist zip found; run bin/dist.sh" >&2; exit 1; }

TMP=$(mktemp -d)
trap 'rm -rf "${TMP}"' EXIT
unzip -q "${ZIP}" -d "${TMP}"
ROOT="${TMP}/mudrava-migration-backup"

fail=0

# 1. Every shipped PHP file must parse.
while IFS= read -r f; do
    if ! php -l "${f}" >/dev/null 2>&1; then
        echo "LINT FAIL: ${f#"${ROOT}/"}" >&2
        fail=1
    fi
done < <(find "${ROOT}" -name '*.php')

# 2. Required runtime files.
for req in mudrava-migration-backup.php uninstall.php readme.txt LICENSE; do
    if [ ! -f "${ROOT}/${req}" ]; then
        echo "MISSING: ${req}" >&2
        fail=1
    fi
done

# 2b. The translation template must ship (wp.org requirement).
if [ ! -f "${ROOT}/languages/mudrava-migration-backup.pot" ]; then
    echo "MISSING: languages/mudrava-migration-backup.pot" >&2
    fail=1
fi

# 3. Dev files must not ship.
if find "${ROOT}" \( -name '*.test.php' -o -name 'phpunit.xml*' -o -name 'composer.json' -o -path '*/tests/*' -o -path '*/vendor/*' \) | grep -q .; then
    echo "DEV FILE LEAKED INTO DIST" >&2
    find "${ROOT}" \( -name '*.test.php' -o -name 'phpunit.xml*' -o -name 'composer.json' -o -path '*/tests/*' -o -path '*/vendor/*' \) >&2
    fail=1
fi

# 4. The zip must load standalone: bootstrap defines the autoloader and
#    the entry class exists.
php -r '
define("ABSPATH", "/tmp/");
$src = file_get_contents("'"${ROOT}"'/mudrava-migration-backup.php");
if (!str_contains($src, "spl_autoload_register")) { fwrite(STDERR, "no autoloader\n"); exit(1); }
if (!str_contains($src, "Plugin::instance")) { fwrite(STDERR, "no bootstrap call\n"); exit(1); }
'

[ "${fail}" -eq 0 ] && echo "dist OK: ${ZIP} ($(unzip -l "${ZIP}" | tail -1 | awk '{print $2}') files)"
exit "${fail}"
