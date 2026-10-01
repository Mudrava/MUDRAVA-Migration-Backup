# Security

The security policy and vulnerability reporting process live in
[SECURITY.md](../SECURITY.md). This document covers the engineering
details.

- Threat model: [threat-model.md](threat-model.md)
- Encryption design: [adr/0002-encryption.md](adr/0002-encryption.md)
- Path safety on restore: [adr/0005-rollback.md](adr/0005-rollback.md)

## Invariants (enforced by tests)

1. No outbound HTTP. `grep -r "wp_remote\|curl_\|file_get_contents('http"
   includes/` is a source inspection check. The current CI workflow is manual.
2. REST routes require appropriate capabilities and authentication. A scoped
   restore token permits only continuation of its import job
   (`tests/Http/RestApiTest`).
3. PathGuard rejects `..`, absolute paths, symlink escapes, and
   null-byte tricks (`tests/Unit/PathGuardTest`).
4. Crypto parameters are read from the header and verified; a missing
   extension aborts instead of downgrading
   (`tests/Unit/CryptoCapabilityTest`).
5. Passwords never appear in options, logs, or job state
   (`tests/Unit/LoggerTest`, `tests/Contract/VerticalMigrationTest`).
6. Corrupt archives never reach the database: verification precedes the
   first write (`tests/Contract`, `tests/Fuzz`).
