# B.6b pre-fix reproduction (BUG-02) — 2026-09-30

Disposable lab target (mudrava-lab, localhost:8083), plugin @ 44d3762.
Control archive re-uploaded as `up-fu-1790735640` (md5 of source
control.mudrava `393a3d9dd40e9d03681b49adb9d6a45e`, 581,316,248 B).
Storage dir in this container: `/tmp/mudrava-backups-cfc3b5a8989c014f`.

Sequence (drv/run-b6b-prefix.sh, full transcript in repro.txt):

1. cron unscheduled; uploads wiped; journals wiped (clean slate).
2. Upload control archive (370 chunks, finalize done).
3. Restore point `backup-localhost-20260930-023419-rxetcw` → done.
4. CHECKSUM TABLE baseline (checksum-before.txt).
5. POST /job/import (restore point attached) → running, restore token issued.
6. `chmod 0500 wp-content/uploads`, tick ONCE →
   `state=failed error=MUDRAVA_PERMISSION_DENIED phase=null` (revision 4).
7. Boundary evidence:
   - journal `import-journal-5dfc8d89….jsonl`: 1 line, `baseline=1`,
     `file=0`, `table=0` (journal-after-fail.txt). Every destructive write
     is journaled BEFORE it happens (WpFileTarget records the `.mudrava-tmp`
     inode before opening it; WpDatabaseTarget records the table before
     DROP+CREATE), so zero records proves zero destructive writes.
   - uploads dir empty, no `*.mudrava-tmp` anywhere (repro.txt).
   - CHECKSUM TABLE after (checksum-after-fail.txt): every table identical
     EXCEPT `wp_options` (1490933231 → 1690056459). `wp_options` is where
     the plugin's own `mudrava_job` option lives — the revision 1→4 saves
     of the failed job state are the only change. No destination data row
     was touched; the journal record counts above are the authoritative
     first-mutation boundary.
8. Permissions repaired (0755). Honest operator retry exactly as the UI
   does it: new restore point `backup-localhost-20260930-023423-svpl4i`
   (this erases the failed import job via `assertNoRunningJob()`→`clear()`),
   then POST /job/import with the SAME archive.

Result (pre-fix FAIL, as reported):

    HTTP 409 {"code":"MUDRAVA_RECOVERY_JOURNAL"}

The baseline-only orphan journal blocks the retry forever; the failed-job
record that could prove it safe to reclaim was already erased by the
restore-point step. `mudrava_last_failed_import` option did not exist
(NULL in repro.txt) — the ownership evidence is simply gone pre-fix.
