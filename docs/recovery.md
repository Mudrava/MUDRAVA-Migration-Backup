# Recovery guide

What to do when a migration goes wrong.

## Export interrupted (timeout, OOM, deploy)

Nothing is lost. The job stores a checkpoint after every completed
frame. Reload the MUDRAVA admin page or wait for the next cron tick -
the export resumes from the last verified frame. Interrupted part files
are truncated to the checkpoint before resuming, so partial garbage
never enters the archive.

If an export is stuck, reload the admin page to resume it. You can cancel a
running export from the UI (Cancel) or with WP-CLI:

```bash
wp eval '\Mudrava\Migration\Migration\JobRunner::cancelExport();'
```

The cancellation clears both the database job and its private checkpoint
mirror. Deleting only `mudrava_job` leaves the mirror in place and does not
cancel the job. An import or rollback cannot be cancelled: keep its
checkpoint and follow the restore guidance below.

## Archive will not restore

Read the error code shown in the UI:

| Code | Meaning | Action |
|---|---|---|
| `MUDRAVA_ARCHIVE_CORRUPT` | Frame CRC mismatch | Re-download the archive; the copy is damaged |
| `MUDRAVA_ARCHIVE_TRUNCATED` | File ends mid-frame | Transfer was cut; re-transfer |
| `MUDRAVA_PART_MISSING` | Split set incomplete | Upload the missing parts (listed in the error) |
| `MUDRAVA_PART_MISMATCH` | Part belongs to another archive | Replace the wrong part |
| `MUDRAVA_MANIFEST_MISMATCH` | Hash chain does not match manifest | Do not restore this file; it is not trustworthy |
| `MUDRAVA_WRONG_PASSWORD` | Password does not decrypt | Try the correct password; the archive is intact |
| `MUDRAVA_CRYPTO_UNAVAILABLE` | Required crypto extension missing | Install sodium (or matching OpenSSL); no silent downgrade happens |
| `MUDRAVA_PATH_UNSAFE` | Archive contains an escaping path | Restore aborted before any write; report the archive |
| `MUDRAVA_PERMISSION_DENIED` | A destination parent is not writable or an existing file cannot be read safely | Fix ownership/permissions and retry; archive verification stops before import mutation |

## Restore failed halfway

Before an ordinary restore, the plugin exports the destination site as a
restore-point `.mudrava` archive in its private storage directory. It checks
that this export completed before starting the destructive import. Keep this
archive until the restored site is verified. If an error occurs after archive
verification, the job attempts to verify and import the restore point under
the same scoped token. Keep the browser tab open; the scheduled task can also
advance this recovery when WordPress cron is running. The UI reports the
original error and whether the restore point was reapplied.
If a verified import stops receiving ticks for 30 minutes, WP-Cron treats it
as abandoned and starts the same restore-point recovery. This depends on a
working WordPress cron and a still-readable restore point.
When an administrator returns to the MUDRAVA page, the final failure and
recovery result appears until dismissed.

If automatic recovery fails, resolve its reported error, download the
restore-point archive from the Archives list, then import it through the same
restore screen. A second restore point is created first if disk permits.
During automatic recovery, a private journal also removes files and tables
that were absent before the failed import, plus unpublished temporary files
left by an interrupted write. Cleanup runs in resumable steps; it checks file
identity before deletion. A manual restore, a failed cleanup,
or directories created by the import can still need inspection. Confirm the
reported recovery result and inspect the site before treating it as complete.
For a completed new file, the journal compares device, inode, size, timestamps
and small samples from the beginning and end before removal. A replacement
with reused inode and different content is left alone. These checks cannot
prove ownership against a concurrent replacement with matching metadata and
samples, or one swapped immediately before deletion. A worker killed after
publishing a file but before the completed-file entry is written may also
leave that new file for manual cleanup. Quiesce external file writes while an
import or automatic recovery is running and inspect the recovered site before
bringing it back into service.
For new files, publication now fails if the destination path appears while
the file is being prepared. A crash between the exclusive publication and
the completed-file journal entry can still leave a full, untracked file. An
existing destination is kept open and checked again before replacement, but
a concurrent writer can still replace it after the last check and before
rename. The same quiescence requirement applies to overwrites.

## Retrying an import that failed before it wrote anything

Every destructive write is journaled *before* it happens, so a journal that
contains only its validated baseline proves its import never mutated a file
or a table. When you retry the same archive after such a failure (for
example a permissions refusal during verification), MUDRAVA reclaims that
pristine journal automatically and starts fresh — no manual file removal.
The retry must prove ownership: either the failed import job is still
stored, or the plugin recorded its ownership receipt when the job was
erased (a new restore point replaces it).

The plugin never deletes a journal that could guard real work. It refuses
with `MUDRAVA_RECOVERY_INCOMPLETE` when the journal contains any mutation
record, and with `MUDRAVA_RECOVERY_JOURNAL` when the owner is unknown or
the journal is damaged, aliased, or its import began a rollback that did
not finish. In those cases finish the pending recovery first; removing the
journal by hand is a deliberate operator action after inspection.

The higher-risk checkbox allows a restore without a restore point when disk
space is insufficient. In that case recovery needs a separate host snapshot
or backup; a failed import can leave the site unavailable until recovery
completes.

## Worst case: site is broken and you have no archive

You are in manual recovery territory:

1. Restore the database from your host's snapshot/backup if one exists.
2. Restore files from the same snapshot.
3. Reinstall the plugin and take a fresh archive immediately.

This is why the preflight and the UI always remind you to keep the
source site alive until the destination is verified.

## Verifying a restored site

```bash
wp db check
wp option get siteurl   # must be the new URL
wp option get home
wp transient delete --all
wp rewrite flush
```

Check serialized options that carried URLs (widgets, theme mods):

```bash
wp option get mudrava_serialized_test --format=json
```

The import report shows `transform_failures`; zero means every
serialized value parsed and rewrote cleanly.
