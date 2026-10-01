# Security policy

Report vulnerabilities privately through [MUDRAVA](https://mudrava.com/en/).
Do not include passwords, customer archives or exploit details in public issues.
The current 1.x release is supported. Response time depends on the report;
no fixed remediation deadline is promised.

## Security controls

* Administrator capability checks and WordPress authentication protect the
  migration API. Restore continuation uses a job scoped token because restoring
  the users table can invalidate the original login. That token cannot access
  unrelated WordPress routes.
* Archives and restore state live outside the public WordPress root. Downloads
  use authenticated routes. Choose durable private storage when the Environment
  screen reports use of the system temporary directory.
* Import verifies the complete archive before replacing site data. Path guards
  reject unsupported paths and escapes. Archive file identities are pinned
  during verification and restore.
* Password encryption uses authenticated encryption. The destination must
  support the backend recorded in the archive. Unencrypted checksums detect
  accidental corruption; they do not prove who created an archive.
* Archive passwords are supplied for each job tick and are not persisted by the
  plugin. A user supplied password hint is stored, so do not put secrets in it.
* Free makes no external service requests. WordPress performs its normal update
  checks. Import only archives from sources you trust.

Use TLS for administration. Backups contain the site's existing data and
credentials. Pause writes during final export and inspect the destination
before sending visitors to it. A restore point supports recovery but cannot
protect against every hosting or database failure.

See [security engineering](docs/security.md), [privacy](docs/privacy.md) and
[the recovery guide](docs/recovery.md).
