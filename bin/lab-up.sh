#!/usr/bin/env bash
#
# Bring up the two-site migration lab and seed it:
#   source (http://localhost:8081) - WordPress with the plugin active,
#     seeded with content, a large upload, and a serialized option.
#   target (http://localhost:8082) - clean WordPress with the plugin active.
#
# Idempotent: safe to re-run; it only seeds what is missing.
set -euo pipefail
cd "$(dirname "$0")/.."

WP_ADMIN_PASS="test-only-password"

docker compose up -d --build db-source db-target source target

# The official WordPress entrypoint blocks on the database before starting
# Apache, so once the site answers HTTP, wp-cli can reach the DB too.
wait_http() {
  local url="$1"
  local n=0
  until curl -fsS -o /dev/null "$url/wp-login.php"; do
    n=$((n + 1))
    if [ "$n" -gt 120 ]; then echo "timeout waiting for $url" >&2; exit 1; fi
    sleep 2
  done
}
echo "waiting for source/target HTTP..."
wait_http "http://localhost:8081"
wait_http "http://localhost:8082"

seed_site() {
  local svc="$1" url="$2" title="$3"
  if ! docker compose exec -T "$svc" wp core is-installed --allow-root >/dev/null 2>&1; then
    docker compose exec -T "$svc" wp core install \
      --url="$url" --title="$title" --admin_user=admin \
      --admin_password="$WP_ADMIN_PASS" --admin_email=admin@example.test --skip-email --allow-root
  fi
  # Activation creates private storage outside the docroot. Run it as the
  # same user as Apache, including on repeat runs with an older root-owned
  # lab storage directory.
  docker compose exec -T "$svc" bash -c '
    chown -R www-data:www-data .
    for dir in /var/www/mudrava-backups-*; do
      if [ -d "$dir" ]; then chown -R www-data:www-data "$dir"; fi
    done
  '
  docker compose exec -T -u www-data "$svc" wp plugin activate mudrava-migration-backup
  docker compose exec -T -u www-data "$svc" wp rewrite structure '/%postname%/'
  # mod_rewrite permalinks need the root .htaccess; WP will not create it
  # from scratch over wp-cli, and without it every /wp-json/ route 404s.
  docker compose exec -T "$svc" bash -c '
    if [ ! -f .htaccess ]; then
      printf "%s\n" \
        "# BEGIN WordPress" \
        "<IfModule mod_rewrite.c>" \
        "RewriteEngine On" \
        "RewriteBase /" \
        "RewriteRule ^index\.php$ - [L]" \
        "RewriteCond %{REQUEST_FILENAME} !-f" \
        "RewriteCond %{REQUEST_FILENAME} !-d" \
        "RewriteRule . /index.php [L]" \
        "</IfModule>" \
        "# END WordPress" > .htaccess
    fi
    chown www-data:www-data .htaccess
  '
}

seed_site source "http://localhost:8081" "MUDRAVA Source"
seed_site target "http://localhost:8082" "MUDRAVA Target"

# Seed content on source only if empty.
POSTS=$(docker compose exec -T source wp post list --post_type=post --format=count --allow-root 2>/dev/null || echo 0)
if [ "${POSTS:-0}" -lt 3 ]; then
  echo "seeding source content..."
  docker compose exec -T source wp post generate --count=5 --post_type=post --allow-root
  docker compose exec -T source wp user generate --count=3 --allow-root
  # A serialized option containing the source URL (rewrite stress).
  docker compose exec -T source wp eval \
    'update_option("mudrava_serialized_test", array("url" => "http://localhost:8081/x", "deep" => array("url" => "http://localhost:8081/y"))); echo get_option("mudrava_serialized_test") ? "ok" : "fail";' --allow-root
  # A ~6 MiB binary upload to force multi-chunk file frames.
  docker compose exec -T source bash -c '
    mkdir -p wp-content/uploads/2026/01
    head -c 6291456 /dev/urandom > wp-content/uploads/2026/01/blob.bin
    chown -R www-data:www-data wp-content/uploads
  '
fi

echo
echo "lab ready:"
echo "  source  http://localhost:8081  (admin / $WP_ADMIN_PASS)"
echo "  target  http://localhost:8082  (admin / $WP_ADMIN_PASS)"
