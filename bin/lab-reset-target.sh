#!/usr/bin/env bash
#
# Reset the target site to a pristine state so a migration E2E run is
# honest: the database is dropped and recreated (wp-cli cannot do this
# over the TLS-pinned client, so we talk to the container's mysql
# directly), restored uploads are wiped, and plugin storage is cleared.
# The source site is left untouched.
set -euo pipefail
cd "$(dirname "$0")/.."

docker compose exec -T db-target bash -c \
  'mysql -uroot -proot -e "DROP DATABASE IF EXISTS wordpress; CREATE DATABASE wordpress; GRANT ALL ON wordpress.* TO wordpress@\"%\";"'

docker compose exec -T target bash -c '
  rm -rf wp-content/uploads/* wp-content/mudrava-backups wp-content/themes/twenty* 2>/dev/null || true
  rm -f wp-content/.htaccess
  mkdir -p wp-content/mudrava-backups
  chown -R www-data:www-data /var/www/html
'

# Re-install WordPress on the now-empty database and re-activate the plugin.
bash bin/lab-up.sh >/dev/null
echo "target reset OK"
