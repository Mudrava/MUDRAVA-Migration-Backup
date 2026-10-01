# A.3 post-fix evidence (BUG-03, 2026-09-30)

Live re-runs on the source container (`mudrava-dev-source-1`, PHP 8.3.33,
WP 7.1.2, plugin mounted from the followup worktree at commit under test).
Deterministic stepping: cron tick unscheduled, export driven one bounded
batch at a time by `temp/followup/drv/scenario-file.php`; the mutation
fires exactly when the checkpoint cursor is mid-way through
`wp-content/uploads/2026/09/big.bin` (offset 8 388 608 = one 8 MiB chunk).

Fixtures per run (`seed-fixtures.php`): big.bin 96 MiB, inplace.txt,
replace.txt, stable.txt, nested `a/child.txt` (walk order: `a/child.txt`
before `a.txt`-style siblings), plus the standard WP tables and the
`wp_mud_keyset` / `wp_mud_nopk` fixtures (1200 rows each).

## add-between (A.3e — file added before the resume position)

Pre-fix (2026-09-29, `mudrava-validation-2026-09-29` evidence): export
completed `verified:true` with 3792 FILE_METADATA frames — one duplicated
path inside a "verified" archive.

Post-fix: the resume refuses.

- `events.jsonl`: `step_fatal` at step 2148 with
  `MUDRAVA_ARCHIVE_CHANGED: source tree changed during export, resume
  position collided with an archived file`; job terminal `failed`.
- `inspect.txt`: the surviving (truncated, no manifest) partial archive
  contains each emitted path exactly once — the guard fired before any
  duplicate `FILE_METADATA` was written.

## delete-before-read (A.3d — known limitation, honestly pinned)

File deleted after its directory was listed but before the walk opened it.
No checkpoint-only check can see a forward jump. The export completes
`verified:true`; the archive omits the deleted file and its manifest
matches what was actually archived (3791 files = the 3792-file baseline
minus the deleted `stable.txt`). This is the documented limitation stated
in `docs/large-sites.md`, the admin UI, and readme.txt — not a detected
failure.

## none (normal interrupted export across nested dirs)

No mutation; the export is interrupted/resumed thousands of times (one
bounded batch per step) across the nested `a/` directory. Completes
`verified:true`; every fixture path present exactly once, DB row counts
and content hashes match the seed (`keyset rows=1200 uniq=1200`,
`nopk rows=1200 uniq=1200`). No false positive from the new guard.

## Reproduce

```
bash temp/followup/drv/run-file-scenario.sh add-between
bash temp/followup/drv/run-file-scenario.sh delete-before-read
bash temp/followup/drv/run-file-scenario.sh none
```

Unit-level pin: `tests/Contract/InventoryDriftGuardTest` (5 tests,
including the legacy-checkpoint compatibility case and the nested-order
no-false-positive case).
