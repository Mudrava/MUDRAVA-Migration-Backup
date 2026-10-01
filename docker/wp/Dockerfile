# WordPress with wp-cli baked in (the official image ships without it).
# Used by compose.yaml for the migration lab.
FROM wordpress:php8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends less mariadb-client \
    && rm -rf /var/lib/apt/lists/* \
    && curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp
