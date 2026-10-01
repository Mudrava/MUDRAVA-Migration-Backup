# The `.mudrava` Format Specification - container_format = 1

Status: **frozen draft for v1.0.0**. Any change requires a spec update, compatibility
tests, an old-reader/new-reader decision, and a CHANGELOG entry.

Design goals:

1. **Append-only streaming.** A writer never seeks backwards. A reader walks forward.
2. **Bounded memory.** Nothing in the format requires holding more than one frame in RAM.
3. **Resumable.** Checkpoints mark durable restart positions.
4. **Recoverable.** A truncated archive yields a deterministic error and a
   recoverable valid prefix.
5. **Forward compatible.** Unknown optional frame types can be skipped safely.
6. **Self-describing split sets.** Every physical part identifies its logical archive.

The format is intentionally **public**. Users must never be locked out of their
own data. A standalone `mudrava verify` recovery tool is planned.

---

## 1. Conventions

- All integers are **unsigned little-endian** unless stated otherwise.
- `uint8` = 1 byte, `uint16` = 2, `uint32` = 4, `uint64` = 8.
- Byte offsets in JSON/API contexts are transmitted as **decimal strings**
  (never rely on client float precision).
- "Logical bytes" = uncompressed, unencrypted payload bytes.
- "Stored bytes" = bytes physically written inside a frame payload.

## 2. Physical layout

```text
┌──────────────────────────────┐
│ PART HEADER (part 1 only)    │
├──────────────────────────────┤
│ FRAME 1                      │  ← SITE_METADATA (always first frame)
│ FRAME 2                      │
│ ...                          │
│ CHECKPOINT                   │  ← emitted at stage boundaries + periodically
│ ...                          │
│ MANIFEST                     │
│ FOOTER                       │
│ TAIL MAGIC                   │
└──────────────────────────────┘
```

A split archive is one logical archive stored in N physical files:

```text
my-site.mudrava              ← part 1 (full part header)
my-site.mudrava.part0002     ← part 2+ (part continuation header)
my-site.mudrava.part0003
```

## 3. Part header (part 1)

| Field | Size | Notes |
|:--|:--|:--|
| `magic` | 8 | ASCII `MUDRAVA\0` |
| `container_format` | uint16 | `1` |
| `header_size` | uint16 | total header length incl. this field's end; readers skip `header_size - offset` unknown trailing bytes |
| `flags` | uint32 | bit0 `FLAG_ENCRYPTED`, bit1 `FLAG_COMPRESSION_DEFAULT`, bit2 `FLAG_SPLIT_SET` |
| `archive_uuid` | 16 | random 128-bit ID, stable across all parts |
| `producer_version` | uint8 len + UTF-8 bytes | plugin version, e.g. `1.0.0` |
| `kdf` | uint8 | `0` = none, `1` = Argon2id (libsodium `crypto_pwhash`), `2` = PBKDF2-HMAC-SHA256 (OpenSSL fallback) |
| `kdf_opslimit` | uint32 | KDF parameter (0 if unused) |
| `kdf_memlimit` | uint32 | KDF parameter, KiB (0 if unused) |
| `kdf_salt` | 16 | random; present when `kdf != 0`, else zero-filled |
| `nonce_prefix` | 8 | random; per-frame nonce material (zero-filled when unencrypted) |
| `hint_len` | uint16 | optional tail, present when `header_size` extends past `nonce_prefix`; `0` when no hint |
| `hint` | `hint_len` bytes | plaintext UTF-8 password hint, see §9 |

`container_format` is independent of plugin version. A `3.x` plugin must still
read `container_format = 1`.

## 4. Part continuation header (parts 2..N)

| Field | Size | Notes |
|:--|:--|:--|
| `magic` | 8 | ASCII `MUDRAVAP` |
| `archive_uuid` | 16 | must equal part 1's UUID |
| `part_number` | uint32 | 2, 3, 4, … |

Frames continue with a **global sequence** across parts.

## 5. Frames

Every frame:

| Field | Size | Notes |
|:--|:--|:--|
| `sync` | 4 | ASCII `MUDF` |
| `frame_type` | uint8 | see table below |
| `frame_flags` | uint8 | bit0 `compressed`, bit1 `encrypted` |
| `sequence` | uint64 | global, starts at 1, strictly +1 per frame |
| `stored_len` | uint32 | payload bytes as physically stored |
| `logical_len` | uint32 | payload bytes after decrypt+decompress |
| `payload_crc32` | uint32 | CRC32 (IEEE) of the stored payload bytes |
| `payload` | stored_len | see §6 |

The reader MUST verify `sync`, `sequence`, and `payload_crc32` before use.
For encrypted frames the CRC is a cheap pre-check; the AEAD tag is the
authoritative integrity control.

### Frame types

| Value | Name | Required | Description |
|:--|:--|:--:|:--|
| `0x01` | `SITE_METADATA` | ✔ first frame | JSON: site name, source URL, WP version, table prefix, file root info |
| `0x10` | `DB_TABLE_BEGIN` | | JSON: `table`, `engine`, `charset` |
| `0x11` | `DB_SCHEMA` | | raw `SHOW CREATE TABLE` output (UTF-8) |
| `0x12` | `DB_ROWS` | | row batch, §7 |
| `0x13` | `DB_TABLE_END` | | JSON: `table`, `row_count` |
| `0x20` | `FILE_METADATA` | | JSON: `path` (normalized relative), `size`, `mtime`, `mode`, `type` (`file`/`symlink`), `target` (symlink only) |
| `0x21` | `FILE_DATA` | | raw file chunk bytes |
| `0x30` | `CHECKPOINT` | | JSON, §8 |
| `0x40` | `MANIFEST` | ✔ | JSON, §10 |
| `0x41` | `FOOTER` | ✔ last frame | JSON: `manifest_seq`, `total_frames`, `logical_bytes`, `parts_expected` |

Unknown frame types with the high bit set (`0x80`+) are **optional**: readers
skip them by `stored_len`. Unknown required types must abort with
`MUDRAVA_FORMAT_UNSUPPORTED`.

## 6. Payload pipeline

Write order: `logical payload → compress → encrypt → store`.
Read order: `stored → decrypt → decompress → logical`.

- **Compression** (`compressed` flag): raw DEFLATE (`gzdeflate`, level ≤ 6).
  If the compressed size ≥ logical size, the frame is stored uncompressed and
  the flag is cleared. Compression is per-frame; adaptive chunk sizing must
  never change archive semantics.
- **Encryption** (`encrypted` flag): per-frame AEAD.
  - Primary (libsodium): `crypto_aead_xchacha20poly1305_ietf`.
    Key = `crypto_pwhash(password, kdf_salt, opslimit, memlimit, ARGON2ID13)` (32 B).
    Nonce (24 B) = `nonce_prefix (8) || sequence (uint64 LE) || 8 zero pad`…
    concretely: `nonce = nonce_prefix || pack('P', sequence) || str_repeat("\0", 8)`.
    Payload = `ciphertext || tag (16 B)`. Nonce is **not** stored (derived).
  - Fallback (OpenSSL): AES-256-GCM. Key = PBKDF2-HMAC-SHA256
    (≥ 600 000 iterations, `kdf_salt`). Nonce (12 B) =
    `first 4 bytes of nonce_prefix || pack('J', sequence)`.
    Payload = `ciphertext || tag (16 B)`.
  - The password is **never** stored anywhere. Only KDF parameters + salt.
  - If the required crypto capability is missing on a host, the plugin must
    say so explicitly and offer an unencrypted migration - never silently
    downgrade.

## 7. Row batch encoding (`DB_ROWS`)

```text
uint16  column_count
uint32  row_count
per row, per column:
    uint8 kind        0 = NULL, 1 = bytes
    (kind 1) uint32 length + raw bytes
```

Values are raw database bytes (no base64). Binary/BLOB safe. Unicode passes
through as stored bytes. Column names are NOT repeated per batch; they appear
in `DB_TABLE_BEGIN.columns` (JSON array, positional).

## 8. Checkpoint (`CHECKPOINT`)

```json
{
  "state": "files",
  "stage": "file_stream",
  "sequence": 19421,
  "logical_bytes": "881312890880",
  "cursor": { "table": "wp_options", "pk": 40211, "path": "uploads/2026/09", "file": "big.mov", "file_offset": "1073741824" },
  "updated_at": 1790000000
}
```

A resume restarts from the **last valid checkpoint**: the reader replays
forward from the checkpoint's `sequence`. All operations between checkpoint
boundaries are idempotent (table re-create, file overwrite, batch delete-then-insert).

## 9. Password hint

Stored in the part-1 header as a plaintext tail (`hint_len` + `hint`),
*outside* the encrypted payload. This is deliberate: the archives list must
show the hint before the operator types a password, and the header tail is
skippable via `header_size`, so older readers ignore it and archives without
a hint keep the exact pre-hint byte layout.

The UI must state:

> Anyone who has this archive can read the password hint.

Hints must not contain the password itself or obvious fragments.

## 10. Manifest (`MANIFEST`)

```json
{
  "frame_count": 194444,
  "type_counts": { "1": 1, "16": 41, "18": 9021, "32": 120000, "33": 480000 },
  "file_count": 120000,
  "file_bytes": "871234567890",
  "table_count": 41,
  "row_count": 902100,
  "root_hash": "<hex>",
  "created_at": 1790000000
}
```

`root_hash` is a rolling SHA-256 chain over every frame:

```text
h0 = SHA-256("MUDRAVA-ROOT-V1" || archive_uuid)
h(i+1) = SHA-256(h(i) || frame_type || pack('P', sequence) || pack('N', payload_crc32))
```

Verification = recompute the chain while streaming and compare with manifest.

## 11. Footer + tail magic

`FOOTER` frame (JSON, §5) followed by 8-byte tail magic `MUDRAVAE`.
A file ending at `FOOTER` + tail magic is structurally complete.
A missing tail ⇒ `MUDRAVA_ARCHIVE_TRUNCATED` with the last valid sequence
reported (valid prefix remains recoverable/diagnosable).

## 12. Split sets

- Splitting happens **only at frame boundaries**.
- Default: OFF or AUTO. Presets: 2 GB / 4 GB / 8 GB / custom.
- One logical backup = one `archive_uuid`; the UI shows ONE backup with N parts.
- The importer must explicitly report: missing part (exact number), wrong part
  (UUID/part mismatch), duplicate part (idempotent skip), corrupt part.

## 13. Path safety (normative)

`FILE_METADATA.path` MUST be a normalized relative POSIX path.
Restorers MUST reject: `..` segments, absolute paths, Windows drive paths,
null bytes, and any resolved path escaping the destination root.
Symlinks are NOT restored by default; an advanced opt-in may restore symlinks
whose resolved target stays inside the destination root.

## 14. Error codes (format layer)

```text
MUDRAVA_FORMAT_UNSUPPORTED   unknown required frame/header version
MUDRAVA_ARCHIVE_CORRUPT      sync/crc/AEAD failure mid-stream
MUDRAVA_ARCHIVE_TRUNCATED    missing footer/tail
MUDRAVA_WRONG_PASSWORD       KDF/AEAD rejection
MUDRAVA_PART_MISSING         split set incomplete
MUDRAVA_PART_MISMATCH        wrong UUID or part number
MUDRAVA_MANIFEST_MISMATCH    root_hash / counts disagree
```

## 15. Versioning policy

- Additive frame types / optional flags: minor, readers skip unknown optional.
- Semantic changes (framing, crypto, row encoding): bump `container_format`.
- Readers must support all older `container_format` values they claim.
- Golden archive fixtures under `tests/GoldenArchives/` pin every published version.
