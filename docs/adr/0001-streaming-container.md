# ADR-0001: Append-only framed streaming container

Status: accepted (2026-09-25)

## Context
ZIP-based approaches require assembling the whole site in a temp directory
before transfer, doubling disk usage and breaking on multi-terabyte sites,
shared-hosting timeouts, and low `upload_max_filesize`.

## Decision
`.mudrava` is an append-only framed container (spec: `docs/mudrava-format.md`):
length-prefixed frames, forward-only reader, per-frame compression + AEAD,
checkpoints, manifest with rolling root hash, split at frame boundaries.

## Consequences
- No temp ZIP, no central directory dependency, bounded memory.
- One parser serves local file, split set, HTTP body, browser upload.
- Truncated archives produce deterministic errors with recoverable prefix.
- Format is public; golden fixtures pin every version; changes need spec +
  compat tests + CHANGELOG.
