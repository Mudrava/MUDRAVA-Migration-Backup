#!/usr/bin/env bash
#
# Every tracked PHP file must carry the GPL-2.0-or-later notice within its
# first 25 lines. wp.org reviews flag missing per-file license headers, and
# GPL compliance is not something to discover during review.
set -euo pipefail
cd "$(dirname "$0")/.."

fail=0
while IFS= read -r file; do
  [ -f "$file" ] || continue
  if ! head -25 "$file" | grep -qi "GPL\|license"; then
    echo "missing license header: $file" >&2
    fail=1
  fi
done < <(
  {
    git ls-files '*.php'
    git ls-files --others --exclude-standard '*.php'
  } | sort -u
)

if [ "$fail" -ne 0 ]; then
  echo "header check FAILED" >&2
  exit 1
fi
echo "header check OK"
