# Changelog

All notable changes to this project are documented here. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

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

## [1.0.0] - 2026-09-25

Initial release.

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
