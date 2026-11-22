# Changelog

All notable changes to this project are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
the project adheres to [Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-11-22

### Added

- WP-CLI bridge (`wp mudrava`): preflight, inventory, maintenance, export,
  import, status, resume, abort, archives, archive info and a single
  machine-readable `report`, behind the exact same safety gates as the
  browser. JSON by default; exit codes 0 done, 1 refusal, 2 resumable
  ("still running"). See `docs/agent-api.md`.
- Maintenance module (screen + CLI): post revisions, spam and trashed
  comments, auto-drafts, expired transients and orphaned post meta are
  counted first and deleted only when you apply the selection; unattached
  media and autoloaded options are measured as advisories, never pruned.
- Software inventory in `SITE_METADATA` (active theme, every plugin and
  mu-plugin version) and a pre-restore environment diff that flags plugins
  the destination lacks, plugins older here than on the source and a
  different theme.
- Hand-written interface translations in 27 languages (ca, cs, da, de_DE,
  el, es_ES, fr_FR, gl, hr, hu, id, it_IT, ja, nb, nl_NL, pl_PL, pt_BR,
  pt_PT, ro, ru, sk, sl, sr, sv, tr_TR, zh_CN plus the English source).

### Fixed

- The orphaned post meta scan referenced a column name that does not exist
  on the real `postmeta` schema and an alias that MariaDB reserves;
  maintenance now counts correctly on MariaDB and MySQL alike.
- The CLI imported split sets by scanning continuation parts from `0001`,
  while the format numbers them from `0002`; staging and part counting now
  follow the format and refuse a numbering gap instead of stopping
  silently (covered by `tests/Contract/CliSetEnumerationTest`).

### Tested

- Real upgrade path 1.0.1 to 1.1.0 on fresh WordPress 7.1.5 installs
  (PHP 8.3, MariaDB 10.11): the upgrade keeps the plugin active with zero
  debug-log notices, maintenance counts match row by row, a
  password-encrypted 33-part archive exported and restored onto a second
  clean 7.1.5 site with every URL rewritten and media intact.
- 594 PHPUnit tests, PHPStan and PHPCS against this exact build; the
  distributable ZIP reproduces byte-identically across builds.

## [1.0.1] - 2026-11-02

### Changed

- Verified compatibility metadata for WordPress 7.1.4: the full E2E migration lab
  (export and restore, encrypted archive round trip, split archive upload), 580
  PHPUnit tests, PHPStan and PHPCS were re-run against this exact build on
  WordPress 7.1.4 and PHP 8.3. The engine code is unchanged.
- The plugin header and `MUDRAVA_MB_VERSION` are synced to the release version;
  the constant no longer trails the header.

### Added

- Development lab: `bin/lab-reset-target.sh` scopes its `chown` to the directories
  it rewrites, so the E2E lab runs on Docker Desktop for macOS, where ownership
  changes on bind-mounted git object files are denied.

## [1.0.0] - 2026-09-25

Initial release.

### Fixed

- An interrupted export whose source tree gained a file before the resume
  position no longer archives that file's slot twice. The checkpoint now
  remembers the last archived path, and a resume that lands on an equal or
  earlier path refuses with `MUDRAVA_ARCHIVE_CHANGED` instead of emitting a
  duplicate `FILE_METADATA` into a "verified" archive. The check compares
  path segments (a directory legitimately precedes its later-named
  siblings), uses one remembered path only, and stays off for checkpoints
  written before this release. A file deleted before the walk reaches it
  remains a documented, honestly-omitted limitation.
- Retrying an import that failed before its first destructive write (for
  example a permissions refusal during verification) no longer requires
  removing the recovery journal by hand. The retry now reclaims a journal
  that provably contains only its validated baseline, after proving it
  belongs to a terminal failed import of the same archive that never began
  a rollback. Journals with any mutation record, unknown or damaged
  ownership, or an unfinished rollback are never deleted; the retry
  refuses with typed `MUDRAVA_RECOVERY_INCOMPLETE` or
  `MUDRAVA_RECOVERY_JOURNAL` instead.
- PK-less table export no longer silently skips rows when the table is
  modified mid-stream. The engine re-reads the last archived row before
  every offset batch and aborts with `MUDRAVA_DB_TABLE_CHANGED` if the
  read window slid, instead of producing a "verified" archive with a gap
  the manifest count could not see.
- A full or unwritable disk during a job no longer leaves the job stuck
  `running` forever. When the private job mirror cannot be written, the
  terminal state now falls back to the option store, so failures reach a
  durable `failed` state and the real error code (e.g.
  `MUDRAVA_DISK_FULL`) is reported instead of being masked.
- The restore-point endpoints return a typed `409` when private storage
  is unwritable or unsafe, instead of an uncaught HTTP 500.

### Added

- Streaming `.mudrava` archive format: framed, CRC32C-checked,
  SHA-256 hash-chained, resumable, optional AES-256-GCM encryption
  (sodium secretstream / OpenSSL fallback, Argon2id KDF).
- Resumable export/import state machine with durable checkpoints,
  bounded per-tick work, and lock-based concurrency guard.
- Serialized-safe URL rewriting via a strict PHP-serialized parser;
  unparseable values are preserved byte-identical and reported.
- PathGuard restore safety; rollback journal; dry-run archive
  inspection.
- Chunked resumable browser uploads (4 MiB chunks).
- Split archives for hosts with per-file limits.
- Admin UI (Backup / Restore tabs, preflight, progress, archive list
  with downloads), REST API under `mudrava/v1`.
- Test suite: unit, contract (full vertical migration), golden
  archive fixture, fuzz reader; PHPStan level 8; PHPCS
  (PSR-12 + WP security sniffs + PHP 7.4 floor).
- Docker two-site migration lab + deterministic Playwright E2E.
