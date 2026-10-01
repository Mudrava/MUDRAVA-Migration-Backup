# B.6b post-fix verification (BUG-02) — 2026-09-30

Same disposable lab target and control archive as `../b6b-prefix/`
(archive `up-fu-1790738269`, md5 `393a3d9dd40e9d03681b49adb9d6a45e`).
Plugin version under test included the documented recovery fix.
Driver: `temp/followup/drv/run-b6b-postfix.sh`, transcript `verify.txt`.

Sequence and results:

1. Upload, restore point `backup-localhost-20260930-031809-zoye19` → done.
2. Import start, `chmod 0500 uploads`, one tick →
   `failed MUDRAVA_PERMISSION_DENIED`, journal baseline-only
   (`file=0 table=0 baseline=1`) — identical failure boundary as pre-fix.
3. Receipt option is NULL while the failed job is still stored (ownership
   lives in the job itself).
4. Permissions repaired. Honest retry step 1: new restore point
   `backup-localhost-20260930-031813-h3xyoz`. `clear()` erased the failed
   import and recorded the receipt:
   `archive=up-fu-1790738269 error=MUDRAVA_PERMISSION_DENIED rollback=`.
5. Retry step 2: POST /job/import, SAME archive → **HTTP 200, running,
   restore token issued** (`RETRY_ACCEPTED: PASS`). Pre-fix this was
   `409 MUDRAVA_RECOVERY_JOURNAL`.
6. Ticks → `state=done`, `restored_rows=2637`, `restored_files=3791`,
   `transform_failures=0` (`retry-ticks.txt`).
7. Journal reclaimed (none on disk); receipt consumed (NULL).

Data comparison (destination vs canonical restore of this archive):

- `target-uploads.md5` is byte-identical to the retained canonical B.2
  restore of the same control archive (`diff` vs
  `b2-import-kill/target-uploads.md5` empty → `UPLOADS_MATCH_CANONICAL:
  PASS` appended to verify.txt).
- `wp_mud_keyset count=1200 hash=54872dd9376145085dad706f2cf2f8c6`,
  `wp_mud_nopk count=1200 hash=2c93cec30c9ad304df47d02eac199795` —
  identical to the canonical B.2 baseline (`target-db-canonical.txt` vs
  old `b2-import-kill/target-db.txt`).
- `home`/`siteurl` = `http://localhost:8083` (rewrite applied).
- Row counts posts/postmeta/users/terms/keyset/nopk all match source.

Note on `uploads-diff.txt`: the only difference against the LIVE source
is `big.bin`. The source drifted after the control archive was captured
(the 2026-09-29 A.3 mid-file mutation scenarios rewrote it in place at
19:35; the archive preserves the earlier snapshot). The restored bytes
match the archive and the canonical B.2 restore exactly, which is the
correct mixed-time semantics — not a regression.
