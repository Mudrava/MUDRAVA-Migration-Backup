#!/usr/bin/env bash
#
# Regenerate languages/mudrava-migration-backup.pot from translatable
# strings. Pure PHP scanner (no wp-cli/i18n-tools dependency) covering
# __(), _e(), _x(), esc_html__(), esc_html_e(), esc_attr__(), esc_attr_e().
# wp.org requires the .pot to ship in the plugin and match the text domain.
set -euo pipefail
cd "$(dirname "$0")/.."

php bin/make-pot.php
echo "pot written: languages/mudrava-migration-backup.pot"
