# Large sites guide

The plugin has no paid archive size cap. A real 100 GiB file completed export
and restore in the local test environment. Independent SHA-256 hashes matched.
Both sites used a 128 MB PHP memory limit. Smaller browser migrations also ran
under 64 MB. See the [100 GiB report](benchmark-100-gib.md) for the exact scope.

These measurements do not establish a universal hosting or database limit.
500 GiB, 1 TiB and 2 TiB runs have not been completed. Disk space, request limits,
filesystem support and unusually large database rows still matter.

## Before export

Use the preflight panel to inspect database size, file size and free space.
Compression depends on the site's data, so do not assume a fixed archive
ratio. The destination also needs room for a restore point and temporary
files. Keep the source unchanged during migration; concurrent database writes
can change rows between export batches.

## Database-heavy sites

The exporter checks byte lengths for up to 500 rows, then fetches only the
leading payload rows that fit its 4 MiB target and PHP memory headroom. This
adds a database length query per batch. A single larger row is allowed when
it fits the host's memory, but the format cannot split one row across frames.
Single-column primary keys use exact string cursors, including UUIDs and
unsigned BIGINT values. Composite keys use an ordered offset cursor. A very
large row can exceed PHP memory or the archive's 256 MiB frame ceiling; the
exporter reports the limit before fetching a row when its length is known.
Views and stored routines have not been validated for
export/import; do not treat them as covered by the current compatibility
matrix.

### What the PK-less anchor check does and does not prove

A table with no primary key (and no composite key to order by) is paged
with bare `LIMIT/OFFSET`, which has no stable row identity. Before every
offset batch the engine re-reads the last archived row at its recorded
offset and aborts with `MUDRAVA_DB_TABLE_CHANGED` when it moved, changed,
or vanished. That single-row anchor proves exactly one thing: the row at
the last-read offset is still the same row. It detects deletes before the
window, updates to the anchor row, and (on ordered tables) inserts that
shift the anchor.

It cannot prove the rest of the already-read prefix stayed stable, and no
bounded checkpoint-only check can: proving prefix stability requires
remembering or re-checksumming every row before the window. A
reorder/content-swap across the window that leaves the anchor row
untouched therefore passes the guard, and the export can emit one already
archived row a second time while silently skipping the row swapped into
already-read territory — inside an archive whose manifest count matches
and that verifies. This was reproduced live (2026-09-30 follow-up,
`a4-nopk-reorder` evidence) and is pinned by
`tests/Contract/NoPrimaryKeyShiftTest`. A plain `INSERT` duplicate of the
anchor row on InnoDB appends after the window and stays honest
(mixed-time); engines or layouts that place a new row before the window
do not.

Practical rule: add a primary key (or any unique index usable as a
composite cursor) to tables that receive concurrent writes during
migration. For genuinely PK-less tables, quiesce writes for the final
export; the anchor check is a corruption tripwire, not a stability
guarantee.

## Media-heavy sites

Files are read in 8 MiB chunks and can resume at a completed frame boundary.
Split archives still require every part, including the final manifest part,
for a verified restore. The 100 GiB round trip is measured; the full host, disk pressure and
interruption matrix has not been measured. Test a representative archive on
the intended host before scheduling a large cutover.

## Many small files

The inventory walks one directory at a time, but sorting a very large flat
directory can consume memory. Inode quotas and file permission failures stop
the job or trigger recovery; they are not silently skipped. Measure both
file count and bytes for the intended site.

## What export does when the file tree changes mid-run

Export is not a point-in-time snapshot; it re-walks directories as it goes
and checkpoints by position. The guarantees are deliberately narrow:

- A file that changes size, inode, or content mid-read is detected and the
  export fails (`MUDRAVA_ARCHIVE_CHANGED`).
- A file **added** in a location the walk has not listed yet would shift the
  resume position onto an already-archived path. The checkpoint remembers the
  last archived path and the resume **refuses** such a collision
  (`MUDRAVA_ARCHIVE_CHANGED: source tree changed during export, resume
  position collided with an archived file`). A new file that sorts after the
  resume position is simply included.
- A file **deleted** before the walk reaches it is a forward jump: no
  checkpoint-only check can see it, the export completes, and the archive
  honestly omits that file. This is a known limitation, not a detected
  failure — pause file writes during a final export.

## Cutover checklist

1. Confirm preflight has enough disk space for the source, archive, restore
   point and temporary writes.
2. Quiesce site writes before the final export and keep them paused until it
   finishes. Maintenance mode alone does not stop cron, CLI jobs or external
   integrations that write to the database or files.
3. Export, retain every archive part, and verify the complete set.
4. Restore on a staging target and check database content, media, links and
   plugin-specific data.
5. Plan DNS cutover and keep the source available for rollback.
