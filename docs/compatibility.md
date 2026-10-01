# Compatibility

## PHP

| Version | Status |
|---|---|
| 7.4 | Clean Composer install, PHP syntax check, 209 PHPUnit tests, PHPCS and PHPStan passed in PHP 7.4.33 CLI; browser export and import passed on WordPress 6.0.3/PHP 7.4.32, including an actual-byte 1 GiB file with a 64 MB PHP memory limit |
| 8.0 - 8.2, 8.4 | CI matrix configured; release run still needed |
| 8.3 | Tested locally with WordPress 7.1.2 and MariaDB 10.11 |
| 8.5 | Local PHPUnit, PHPStan/PHPCS and Xdebug branch coverage runs passed; branch coverage remains below the release target |
| Later PHP versions | Not verified |

The code avoids anything newer than 7.4 syntax. Typed properties,
arrow functions, and null coalescing assignment are the newest
constructs used. `sodium` is used when present; the OpenSSL fallback is
explicit and recorded in the archive header.

## WordPress

- Minimum declared: 6.0. An isolated Docker lab with WordPress 6.0.3 and PHP
  7.4.32 passed a browser export and import of an actual-byte 1 GiB file on
  the release ZIP. The primary Docker lab uses WordPress 7.1.2;
  intermediate versions and the full cross-host matrix have not run.
- Multisite jobs are rejected before starting. The Free edition currently
  supports standalone WordPress installations only.

## Database

| Engine | Notes |
|---|---|
| MariaDB 10.11 | Tested in the Docker lab |
| Other MariaDB, MySQL and Percona versions | Compatibility matrix pending |

The archive stores each table's `SHOW CREATE TABLE` statement and row data.
Cross-engine restores depend on compatible SQL syntax, collations and storage
engines, and have not been verified across the database matrix.

## Complex plugin sites

An isolated browser migration from WordPress 7.1.2/PHP 8.3 to PHP 7.4 passed
with Elementor 4.3.2, Polylang 3.8.10 and Advanced Custom Fields 6.8.10
active. The fixture covered a nested Elementor layout with a button link and
image, two linked Polylang translations, an ACF URL field and Unicode text,
and a media attachment. The destination's Elementor JSON, ACF values,
translation relation and file were checked directly; both pages rendered in
Chrome. After a successful verified import, MUDRAVA rebuilds WordPress rewrite
rules once on the next request, after restored language plugins have loaded.
The current ZIP and browser checks are recorded in the
[release validation](free-release-candidate-2026-09-30.md).

This covers those tested features, not every Elementor widget, ACF field type,
Polylang configuration or extension. Inspect custom plugin data after a
restore. If a language is added shortly before export, refresh WordPress
rewrite rules on the source so its language URLs work before migration.

## Extensions

| Extension | Required | Fallback |
|---|---|---|
| zlib | Yes | none (streaming compression) |
| sodium | Recommended | OpenSSL AES-256-GCM, explicitly recorded |
| mysqli | Via WordPress | none |
| mbstring | No | charset handling uses iconv where needed |

## Web servers

- Apache, nginx, LiteSpeed and Caddy: archive storage must be outside the
  WordPress web root. The plugin chooses a private parent directory when
  writable, otherwise a site-specific system temporary directory. Configure
  `MUDRAVA_MB_STORAGE_DIR` in `wp-config.php` for durable private storage.
- Existing pre-release installations with archives in
  `wp-content/mudrava-backups/` should move them to the new private directory
  and remove the old public directory. A web-server deny rule remains needed
  until the old copies are removed.

## Known limitations

- Publishing a new restored file uses an exclusive hardlink beside the final
  path. The destination filesystem must support hardlinks within a directory;
  otherwise that file fails to publish and recovery is attempted. Existing
  files and symlinks follow their separate publication paths.
- An existing regular file must be readable so the importer can keep its
  original inode open while preparing a replacement. If it cannot be opened,
  the import stops and attempts recovery.
- Compressed archive frames are bounded by their declared logical size.
  Oversized frames that cannot fit within the current PHP memory limit are
  rejected before decoding. A very large database row still cannot be
  streamed through the current frame format.
- Object caches that persist across the rewrite window can serve stale
  URLs; flush caches after restore (`wp cache flush`).
- Tables without a primary key use an offset cursor. Concurrent writes can
  change their row order during export; quiesce the source for such tables.
- Tables with foreign keys that reference other prefixed tables need further
  cross-prefix verification.
