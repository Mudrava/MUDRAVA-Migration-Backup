# ADR-0002: Encryption - libsodium primary, OpenSSL fallback, never custom

Status: accepted (2026-09-25)

## Context
Password-protected archives must use maintained, vetted primitives.
Some legacy hosts lack libsodium.

## Decision
- KDF: Argon2id via `sodium_crypto_pwhash`; fallback PBKDF2-HMAC-SHA256
  (≥600k iterations) via OpenSSL.
- AEAD per frame: XChaCha20-Poly1305 (`sodium_crypto_aead_xchacha20poly1305_ietf`);
  fallback AES-256-GCM.
- Nonce derived from header prefix + frame sequence (unique per frame).
- Password never stored (archive, DB, logs, localStorage, telemetry).
- Missing capability ⇒ explicit message + user-chosen unencrypted path.
  Silent downgrade is a release blocker and covered by tests.
- Compress before encrypt.

## Consequences
- Hosts without either extension get unencrypted-only mode with clear UX.
- Key re-derived per request session from browser-held password (memory only).
