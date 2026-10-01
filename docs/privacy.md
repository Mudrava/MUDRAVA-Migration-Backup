# Privacy

MUDRAVA Migration & Backup is privacy-first by design. This document
states exactly what the plugin does and does not do with site data.

## No phone-home

- The plugin makes **zero outbound HTTP requests**. No update pings
  beyond WordPress core's own, no telemetry, no usage tracking, no
  account, no API keys.

## Where archives live

- A site-specific private directory outside the WordPress web root. Set
  `MUDRAVA_MB_STORAGE_DIR` to a durable writable private path when the
  Environment tab reports that the system temporary directory is in use.
- Old pre-release copies in `wp-content/mudrava-backups/` must be moved
  outside the web root and removed. nginx and Caddy do not honor `.htaccess`.
- Archives contain the full database and files of the site. Treat the
  directory as sensitive: delete archives after migration, and never
  serve it over HTTP.

## Passwords and encryption

- An export password is accepted per request over the authenticated
  channel, used in memory for that tick, and **never persisted** in
  options, transients, session data, logs, or the archive.
- The archive header stores KDF parameters and a salt (public by
  design), never the key or password.
- Lost passwords are unrecoverable. The plugin cannot and does not help
  recover them.

## Logging

- `Logger` accepts only known machine-readable string metadata (`kind`,
  `error` code and `exception_class`). It redacts arbitrary text, including
  nested context, even if the key does not look sensitive. Event names must
  be simple identifiers. Secret-looking context keys are always redacted.
- Error codes shown to users are stable `MUDRAVA_*` codes; filesystem
  paths and stack details are never exposed in UI responses.

## Data you control

- Import reads only what you upload. Export reads only the site's own
  accessible database tables and selected site files, including WordPress
  core and content. Protected storage and supported exclusions are omitted.
- Uninstall removes the plugin's options and cron hooks. Archives in private storage are **not** deleted automatically.
  Remove them yourself when they are no longer needed.

## Site data

Archives can contain personal data already held by your site. The plugin copies
and restores that data at your direction. Its use does not itself establish
legal compliance. Use encryption and suitable access controls for sensitive
backups. Password hints are stored in archive metadata; never include secrets
in a hint.
