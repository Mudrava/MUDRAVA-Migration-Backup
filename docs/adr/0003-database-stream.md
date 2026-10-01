# ADR-0003: Database representation - streamed table records, not monolithic SQL

Status: accepted (2026-09-25)

## Decision
Export as `DB_TABLE_BEGIN → DB_SCHEMA → DB_ROWS batches → DB_TABLE_END`.
Row batches use a binary encoding (spec §7): positional columns, NULL/bytes,
raw database bytes (BLOB-safe, no base64). URL replacement is WordPress-aware:
serialized-aware rewriter (fixes `s:N:` lengths), JSON-aware, plain strings;
binary values untouched unless a match is found in a decodable structure.

## Consequences
- Bounded RAM, resume at batch boundaries, per-table diagnostics.
- No blind regex over serialized data (length corruption class of bugs closed).
- Rewriter needs exhaustive regression matrix (nested serialization, unicode).
