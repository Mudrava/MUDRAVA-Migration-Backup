# Shared hosting guide

MUDRAVA is built for the constraints of shared hosting: low memory,
short `max_execution_time`, no SSH, aggressive process killers.

## What you need

- PHP 7.4+ with `zlib` (and ideally `sodium`).
- Enough free disk for the archive (site size x ~0.7 typical, x1.0 worst
  case with incompressible media).
- Write access to a private directory outside the web root. By default the
  plugin uses the parent of the WordPress directory when writable, then the
  system temporary directory. Once either default directory exists, all PHP
  users select that same directory even if their write permissions differ.
  If both contain backup state, configure one path explicitly rather than
  letting the plugin guess. Set `MUDRAVA_MB_STORAGE_DIR` in `wp-config.php`
  to a durable private directory when the host cleans temporary files.

## If the storage folder is not writable

Error `MUDRAVA_STORAGE_NOT_WRITABLE` usually means the folder was
created by a different user (e.g. a root SSH session or wp-cli). Fix
via your file manager or hosting panel by setting ownership to the web
server user, or delete the folder and let the plugin recreate it from
the web UI.

## Export on a slow host

1. Open MUDRAVA -> Backup. Start the export and keep the tab open; the
   browser drives ticks between server timeouts.
2. If your host kills requests hard, rely on WP-Cron: ticks continue in
   the background without the tab.
3. Use "Split archive at" (e.g. 512 MB) when your host has per-file
   upload/download limits or an unstable FTP. Parts are named
   `.part0002`, `.part0003`, ... and reassemble automatically.

## Import on a slow host

- Uploads use chunks sized below this host's PHP POST limit (up to 16 MiB)
  and retry a failed chunk while the page stays open. After a reload, select
  the same files again in the same tab to continue from the chunks already
  received. The server assembles one chunk per request after all parts arrive.
- The restore is table-by-table and file-by-file; an interruption
  resumes from the checkpoint.

## Limits you may hit

| Limit | Symptom | Mitigation |
|---|---|---|
| `max_execution_time` | Ticks stop mid-step | Resume from the last checkpoint; very large database rows still need host testing |
| `memory_limit` | Export or import fails on a large row | Increase the limit or exclude the affected table and report the row size |
| `max_input_time` / POST size | Upload rejected | Automatic chunk sizing below the smaller PHP request limit |
| Inode quota | File copy fails | Free inodes or split the site |
| `open_basedir` | Path errors | Ensure `wp-content` is inside it |

## Cron not firing

Some hosts disable WP-Cron. Alternatives: keep the admin tab open
(browser-driven ticks), or add a real cron entry:

```
* * * * * curl -s "https://example.com/wp-cron.php?doing_wp_cron" >/dev/null
```
