# ADR-0007: Free/Pro boundary - monetize automation, not pain

Status: accepted (2026-09-25), subscription model updated with owner approval (2026-09-30)

## Context
WordPress.org guideline 5 (verified 2026-09-25): functionality in a directory
plugin may not be locked behind payment or disabled after trial/quota.
Recommended pattern: premium code in an add-on hosted outside WordPress.org.

## Decision
Free (GitHub public + WP.org, GPL-2.0-or-later): full local migration engine -
export/import/overwrite, streaming, resume, split, password encryption,
integrity, URL rewrite, exclusions, preflight, basic rollback.
**No artificial size limit.**

Pro v1 (separate GPL-compatible add-on, outside directory): scheduling, S3 /
S3-compatible delivery, retries, retention and operational reporting. SFTP,
direct transfer, incremental/final-delta, Multisite and fleet workflows are
deferred features, not current functionality.

Integration surface: Free defines storage interfaces and a versioned
`Integration\CoreApi` PHP facade. The initial facade starts/ticks only a named
local export and exposes status/completed archive descriptors. Future lifecycle
and isolation contracts are required before releasing scheduled offsite delivery.
Free contains zero premium code and zero unlock logic.

## Consequences
- Two repositories, shared interface contract tested in Free CI.
- Approved annual plans: $59/3, $129/15, $249/50 production sites. Active paid
  activation admits new Pro automation. Expiry does not interrupt running jobs,
  delete archives or prevent manual Free restore. Free has no registration or
  site quota. Provider-side activation enforces quotas, not email identities.
