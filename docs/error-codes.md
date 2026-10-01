# Error codes

Every failure surfaced to the UI or logs carries a stable `MUDRAVA_*` code.
Codes are a public API: they are **never renamed**, only added to. The
operator sees a short human message. REST responses and job state do not
expose local paths, SQL driver text, or raw exception messages.

Source of truth: `includes/Support/ErrorCode.php`.

| Code | Meaning | Typical recovery |
|:--|:--|:--|
| `MUDRAVA_RUNTIME_UNSUPPORTED` | Server cannot safely run large migrations (32-bit PHP, missing crypto/zlib). | Fix the preflight items, or migrate from a supported host. |
| `MUDRAVA_DISK_FULL` | Disk filled during export/import. | Free space, inspect the failed job, then restart safely. |
| `MUDRAVA_PERMISSION_DENIED` | Web server cannot write where it needs to. | Fix ownership of the configured private archive directory and restored paths. |
| `MUDRAVA_STORAGE_NOT_WRITABLE` | Private backup storage folder not writable (often created by a root shell). | Give the web-server user write access to `MUDRAVA_MB_STORAGE_DIR` or the path shown in the error. |
| `MUDRAVA_MEMORY_LIMIT` | An archive frame or database row cannot fit safely within this PHP process's memory limit. | Increase PHP memory or reduce the affected row/frame size; inspect the target before retrying. |
| `MUDRAVA_STORAGE_UNSAFE` | Storage is inside the web root, has broad permissions, is a symlink, or two default directories contain state. | Choose one private writable path outside the web root with `MUDRAVA_MB_STORAGE_DIR`. |
| `MUDRAVA_ARCHIVE_CORRUPT` | Frame failed CRC/AEAD integrity checks. | Re-download or re-export; do not restore a corrupt archive. |
| `MUDRAVA_ARCHIVE_CHANGED` | An archive part changed or was replaced between verification and restore ticks. On export: the source tree changed mid-run and the resume position collided with an already-archived file (a new file appeared where the walk had not looked yet). | Import: stop, re-upload the original complete archive, verify, retry. Export: quiesce file writes and restart the export. |
| `MUDRAVA_ARCHIVE_TRUNCATED` | Archive ends before its footer; transfer interrupted. | Re-upload the missing tail or the whole part. |
| `MUDRAVA_FORMAT_UNSUPPORTED` | Archive uses a newer `container_format`. | Update MUDRAVA on the target first. |
| `MUDRAVA_WRONG_PASSWORD` | AEAD tag mismatch for the supplied password. | Enter the correct password; the archive itself is intact. |
| `MUDRAVA_PART_MISSING` | A split-set part is not present on the server. | Upload the missing part (numbered in the message). |
| `MUDRAVA_PART_MISMATCH` | A part belongs to a different archive (UUID mismatch). | Select parts from one backup only. |
| `MUDRAVA_MANIFEST_MISMATCH` | Manifest counts disagree with frames read. | Treat as corrupt; re-export. |
| `MUDRAVA_DB_CONNECT` | Database connection lost mid-job. | Restore connectivity, inspect the site, then restart safely. |
| `MUDRAVA_DB_QUERY_FAILED` | A database operation failed (engine/charset/permissions). | Check the server-side log and database privileges. |
| `MUDRAVA_DB_TABLE_CHANGED` | The anchor row of a table without a usable primary key moved, changed, or vanished while the export was paging through it (for example rows deleted before the read window), so continuing could silently miss rows. | Pause writes to that table and re-run the export; adding a primary key makes the table immune. See [large-sites.md](large-sites.md) for the exact scope of this check. |
| `MUDRAVA_REMOTE_TIMEOUT` | Remote storage did not respond in time (Pro). | Resume; the transfer continues where it stopped. |
| `MUDRAVA_REMOTE_UNREACHABLE` | Remote storage host unreachable (Pro). | Check network/credentials, then resume. |
| `MUDRAVA_CRYPTO_UNAVAILABLE` | Required crypto (libsodium/OpenSSL) missing for this archive. | Enable the extension, or restore on a supported host. Never silently downgraded. |
| `MUDRAVA_PATH_UNSAFE` | Archive contains a path that cannot be restored safely (traversal, absolute, wrapper). | The whole restore aborts by design; inspect the archive. |
| `MUDRAVA_JOB_LOCKED` | Another migration job is already running. | Wait for it, or cancel it from the UI. |
| `MUDRAVA_JOB_NOT_FOUND` | Job no longer exists (finished or cancelled). | Refresh the screen. |
| `MUDRAVA_JOB_NOT_CANCELLABLE` | Cancellation was requested for an import, rollback, or finished job. | Let recovery finish; only a running export can be cancelled. |
| `MUDRAVA_RECOVERY_JOURNAL` | A recovery journal for this archive exists but its owner is unknown, damaged, aliased, or its import began a rollback that did not finish. | Finish the pending recovery first. Only after recovery is done may the journal be removed from private storage to allow a retry. |
| `MUDRAVA_RECOVERY_INCOMPLETE` | The previous guarded import recorded real mutations in its journal, so it may guard half-restored state. | Complete or roll back that recovery; do not delete the journal to force a retry. |
| `MUDRAVA_UPLOAD_CHUNK_INVALID` | An upload chunk was rejected (size/order/unknown upload). | Retry the upload; chunks are idempotent. |
| `MUDRAVA_PREFLIGHT_FAILED` | Server is missing requirements for the requested operation. | Fix the failing preflight check listed in the response. |
| `MUDRAVA_MULTISITE_UNSUPPORTED` | Multisite migration requested on the Free edition. | Single-site migration, or MUDRAVA Pro for network-wide moves. |

## Contract

- Codes appear in REST responses as `{ "code": "MUDRAVA_..." }` and in job
  state as `error`. Internal exceptions are mapped to safe codes.
- `MUDRAVA_ARCHIVE_CORRUPT`, `MUDRAVA_MANIFEST_MISMATCH` and
  `MUDRAVA_PATH_UNSAFE` fail during the full pre-import verification, before
  destructive writes. `MUDRAVA_ARCHIVE_CHANGED` also stops further ticks if
  the archive changes afterward.
- A failed job is terminal. Its restore point remains available for manual
  recovery; the operator must inspect the site before starting another job.
