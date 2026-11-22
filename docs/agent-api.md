# Agent API: driving MUDRAVA without a browser

Two machine surfaces drive the plugin: the `wp mudrava` WP-CLI bridge and the
REST controller behind the admin page. Both run the same engine and the same
safety gates. This document is the contract for automation, CI and agents.

## WP-CLI bridge

Registered when WP-CLI is loaded. Every command requires the migrator
capability: run with `--user=<admin-id>`. Output is JSON by default;
`--format=table|json` is honored where a row shape exists.

| Command | What it does |
| --- | --- |
| `wp mudrava preflight` | Environment readiness: PHP, int size, zlib, crypto backend, JSON, single site, storage writability, disk headroom. Same checks as the Environment tab. |
| `wp mudrava inventory` | Live table and wp-content folder sizes with honest notes (autoload cost, very large sites). |
| `wp mudrava maintenance` | Dry-run counts per cleanable item (revisions, auto-drafts, spam, trash, expired transients, orphan postmeta) plus advisories (unattached media, autoload rows/bytes). Advisories are never deleted. |
| `wp mudrava maintenance --apply --items=<list>` | Deletes exactly the listed items, in batches, then returns fresh counts. Unknown item names are refused, not guessed. |
| `wp mudrava export` | Exports the site in-process until done. Options: `--password`, `--hint`, `--split-mb`, `--exclude-tables`, `--exclude-dirs`, `--max-runtime`. |
| `wp mudrava status` | Current job state, same shape as REST `GET /job`. |
| `wp mudrava resume` | Continues the running job (`--password`, `--max-runtime`). |
| `wp mudrava abort` | Cooperative cancel at the next frame boundary. |
| `wp mudrava archives` | Archives in private storage: part counts, missing parts, bytes, encrypted flag, hint. |
| `wp mudrava archiveInfo <archive> [--password]` | Source URL, encryption, and the pre-restore environment diff (plugins the destination lacks, older plugins, theme change) from the archive software inventory. |
| `wp mudrava import <archive> [--password] [--url-from] [--url-to] [--proceed-unsafe] [--max-runtime]` | Restores onto this site. `<archive>` is an id in private storage or a path to `NAME.mudrava`; sibling `.partNNNN` files are staged from the same directory. Gates: provable set completeness (gap = refusal), restore point when disk affords it, explicit `--proceed-unsafe` otherwise. |
| `wp mudrava report` | One document: preflight + inventory + maintenance + archives + software. Built for monitoring scripts. |

## Exit codes (contractual)

| Code | Meaning |
| --- | --- |
| 0 | Job finished, or read-only command succeeded. |
| 1 | Refusal or failure. The message is a stable `MUDRAVA_*` code (see [error codes](error-codes.md)). |
| 2 | Job still running and resumable (`--max-runtime` expired; continue with `wp mudrava resume`). |

## Security model for headless use

* CLI runs as a real WordPress user (`--user=`); capability checks are the
  same as the browser (`mudrava_migrate`).
* Encrypted archives derive keys with Argon2id and verify with
  XChaCha20-Poly1305. A wrong password never corrupts anything; it is
  reported and the job stays resumable.
* Import refuses before the first destructive tick if any part of the set is
  missing, truncated or out of sequence. Part 1 is the base `NAME.mudrava`
  file; continuation parts are numbered from `.part0002`.
* `--proceed-unsafe` is the only way to restore without a restore point and
  it must be stated explicitly by the operator or the agent.

## REST surface

Namespace `mudrava/v1`, same capability gate (`mudrava_migrate`), cookie +
nonce. The admin page is a thin client of it. Endpoints: `POST /job/export`,
`POST /job/restore-point`, `POST /job/import`, `POST /job/tick` (drive or
resume), `GET /job`, `DELETE /job` (cancel), `GET /archives`,
`POST /archive-info`, `GET /archives/{id}/download`, `POST /upload/{id}` +
`/upload/{id}/finalize` + `/upload/{id}/status` (chunked import),
`GET /preflight`, `GET /maintenance`, `POST /maintenance` (apply),
`GET /inventory`.

## Examples

Scheduled clean + encrypted offsite-ready backup:

```bash
wp mudrava maintenance --apply --items=revisions,transients --user=1
wp mudrava export --password="$BACKUP_KEY" --hint='company vault' \
  --split-mb=512 --user=1
```

CI/monitoring probe (exit code + one JSON document):

```bash
wp mudrava report --user=1 | jq '.preflight.checks | map(select(.ok | not))'
```

Restore from a transferred split set (browse or headless, identical gates):

```bash
wp mudrava import /root/backups/site.mudrava --password="$BACKUP_KEY" \
  --url-from=https://old.example --url-to=https://new.example --user=1
```
