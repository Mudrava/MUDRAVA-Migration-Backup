# PK-less anchor-check review (task 3, 2026-09-30)

Question from the follow-up brief: can one row fingerprint prove that a
table without a primary key stayed stable? Answer: **no** — the check is a
tripwire for the anchor row only. Live scenarios below (source container,
cron unscheduled, export driven one bounded batch at a time by
`temp/followup/drv/scenario-db.php`; mutation fires when the cursor is
strictly inside `wp_mud_nopk`, 1200 synthetic fixture rows, batch 500).

`archive-rows-mud_nopk.txt` is the sorted row multiset read back from the
finished archive (synthetic fixture data only); `dbset-nopk.txt` is the
live table after the run. `inspect.txt` shows the plugin's own frame
counts (`rows=` emitted, `uniq=` distinct).

## nopk-reorder — guard MISSES, verified archive repeats and skips a row

Two `UPDATE`s swap the full contents of offset 100 (already archived) and
offset 500 (first unread). The anchor row at offset 499 is untouched, so
the anchor check passes and the export completes `verified:true`.

- Archive: `rows=1200 uniq=1199` — `101:nopk-row-101-...` emitted twice.
- Set diff vs live: archive has the extra `101` copy; live has
  `501:nopk-row-501-...` which the archive never read.
- Conclusion: one row repeated AND one row skipped inside a done,
  verified archive whose manifest count matches. The manifest cannot see
  it because the count of rows *read* is honest; only the multiset is not.

## nopk-dup-anchor — honest mixed-time on InnoDB

Insert an exact copy of the anchor row (offset 499, id 500) mid-stream.
InnoDB appends the new row after the window, so the anchor re-read still
matches and the duplicate is picked up honestly in the final batch.

- Archive: `rows=1201 uniq=1200` (id 500 twice) — and the live table
  also holds id 500 twice: the multiset diff against the live table is
  empty. No skip, no dishonest repeat.
- A shift-style layout (free-slot reuse, page split) would instead slide
  the duplicate into the anchor slot and re-emit the original; that
  variant is pinned at unit level in
  `tests/Contract/NoPrimaryKeyShiftTest::testDuplicateAnchorWithInsertBeforeWindowDuplicatesKnownLimitation`.

## nopk-delete — guard catches (regression, unchanged behavior)

Deleting 60 rows before the window moves the anchor; the next batch aborts
with `MUDRAVA_DB_TABLE_CHANGED` and the job is terminal `failed`.

## Exact guarantee

The anchor check proves only that the row at the last-read offset is
unchanged. It cannot prove prefix stability without remembering or
re-checksumming every earlier row (unbounded, or O(n) per batch). No
heavyweight snapshot/lock was added; the precise scope is documented in
`docs/large-sites.md` ("What the PK-less anchor check does and does not
prove") and `docs/error-codes.md`. Practical rule: give concurrently
written tables a primary key, or quiesce writes for the final export.

## Reproduce

```
bash temp/followup/drv/run-db-scenario.sh nopk-reorder
bash temp/followup/drv/run-db-scenario.sh nopk-dup-anchor
bash temp/followup/drv/run-db-scenario.sh nopk-delete
```
