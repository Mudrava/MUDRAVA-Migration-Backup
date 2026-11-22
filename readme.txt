=== MUDRAVA Migration & Backup ===
Contributors: mudrava
Tags: migration, backup, restore, site migration, database backup
Requires at least: 6.0
Tested up to: 7.1.5
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free WordPress migration, backup and restore. Resumable, encrypted, split archives with no size cap, database cleanup and a WP-CLI bridge.

== Description ==

Move your WordPress site to another host or domain, keep a backup before a change, tidy a bloated database, or restore a saved copy. MUDRAVA Migration & Backup puts your database and selected site files into a portable `.mudrava` archive and runs the same workflow in the browser or from `wp mudrava` on the command line.

The free plugin includes export, import and restore, a WP-CLI bridge for automation, and database cleanup. You do not need a MUDRAVA account, and you do not need a paid extension to remove an archive size cap. Available disk, PHP and database resources still determine what your host can handle.

= A complete free migration workflow =

* Export your database and selected site files, including media, themes and plugins.
* Upload archives in chunks rather than one large request.
* Resume supported interrupted jobs from saved checkpoints.
* Protect an archive with optional password encryption (XChaCha20-Poly1305 with Argon2id).
* Split a backup into parts when that makes transfer easier.
* Exclude tables or paths you do not want to copy.
* Rewrite URLs, including recognized PHP-serialized values.
* Check the full archive before the restore begins replacing site data.

= WP-CLI automation for agencies and CI =

Every workflow is a command, not only a button: `wp mudrava preflight`, `inventory`, `maintenance`, `export`, `import`, `status`, `resume`, `archives` and a single machine-readable `report` for monitoring scripts. The commands run through the same safety gates as the browser UI: proven split-set completeness, a restore point when disk affords one, and an explicit acknowledgement flag before any restore that proceeds without it. Machine output is JSON by default, with exit codes that CI can branch on.

= Clean the database before it inflates every backup =

The Maintenance screen counts post revisions, spam and trashed comments, auto-drafts, expired transients and orphaned post meta before deleting anything. You choose what to clean; the plugin never deletes on its own. Unattached media and autoloaded options are measured and reported but left alone, because media files need your own review. The same surface is headless: `wp mudrava maintenance --apply --items=revisions,transients`.

= Know what the archive contains before you restore =

Every archive carries a software inventory: the active theme and the version of every plugin and must-use plugin on the source. Before the restore, the plugin compares that inventory with the destination and warns about plugins the destination lacks, plugins that are older than the source, and a different active theme, so page builder and custom field content does not silently render wrong after the move.

= Check before you overwrite =

Import first verifies the archive and checks restore paths. A standard restore also saves a backup of the destination site before applying the imported data. If available storage cannot hold that restore point, the interface explains the risk and requires explicit acknowledgement to proceed without it.

After an import error, the plugin attempts recovery from the restore point when one exists. Always inspect the recovered site; a restore point is a safety measure, not a guarantee against every host or database failure.

= For larger sites and interrupted connections =

Files are streamed in chunks and database rows are processed in batches. There is no paid size cap. A 100 GiB file was exported and restored in our test environment. Independent file hashes matched. That result does not guarantee every site or hosting configuration: free disk, request limits, filesystem support and unusually large database rows matter.

Exports advance through the admin page and can continue through WordPress cron when it is running. Keep the import page open until the restore finishes. Password encrypted jobs need the password supplied to continue.

= Local backups without an account =

The free plugin sends no telemetry and makes no external service requests. WordPress handles its normal plugin update checks. Archives are stored in a private directory outside the WordPress web root; the Environment screen warns if the host's temporary directory is being used.

You can choose a durable private storage path with `MUDRAVA_MB_STORAGE_DIR`. Treat backups as sensitive: they contain the data and credentials already present in your site.

= Compatibility and final migration checks =

MUDRAVA currently supports standalone WordPress installations. Multisite is not supported by this release.

The interface ships with hand-written translations in 27 languages, including German, Spanish, French, Italian, Portuguese (Brazil and Portugal), Dutch, Polish, Russian, Ukrainian, Japanese and Simplified Chinese.

Migration fixtures with Elementor, Advanced Custom Fields and Polylang have been tested. This does not cover every widget, custom field type, plugin extension or configuration. Inspect your pages, links, media, translated routes and custom data after restoring.

Pause writes during the final export. Orders, form submissions, uploads and background integrations can change the source while it is being read. This release does not make a snapshot from one point in time of a changing site.

= Documentation and support =

[Plugin page](https://wordpress.org/plugins/mudrava-migration-backup/)
[Support forum](https://wordpress.org/support/plugin/mudrava-migration-backup/)
[Source code and developer documentation](https://github.com/Mudrava/MUDRAVA-Migration-Backup)
[Compatibility and tested scenarios](https://github.com/Mudrava/MUDRAVA-Migration-Backup/blob/main/docs/compatibility.md)
[About MUDRAVA](https://mudrava.com/en/)

== Installation ==

1. Install MUDRAVA Migration & Backup from the WordPress plugin directory, or upload the plugin ZIP.
2. Activate the plugin and open **MUDRAVA** in the admin menu.
3. Review the **Environment** report for PHP, extensions, storage and permissions.
4. Create and download a backup on the source site.
5. Install the plugin on the destination, upload the archive or all split parts, check the restore settings and restore.
6. Inspect the destination before sending visitors to it.

Requires WordPress 6.0+, 64-bit PHP 7.4+ and zlib. Encrypted archives need their recorded encryption backend on the destination: libsodium or the supported OpenSSL fallback.

== Frequently Asked Questions ==

= Can I export and restore for free? =

Yes. Manual export, import, restore, optional encryption, splitting, database cleanup, resumable jobs and the WP-CLI bridge are included in the free plugin.

= Can I automate migrations and backups with WP-CLI or cron? =

Yes. `wp mudrava export`, `import`, `maintenance`, `report` and the other commands drive the same engine as the admin screens, with JSON output and CI-friendly exit codes. `wp mudrava report` returns readiness, sizes, maintenance counts and archives in one document for monitoring scripts.

= Does the plugin delete junk from my database automatically? =

No. Maintenance counts revisions, spam, trashed comments, auto-drafts, expired transients and orphaned post meta first, then deletes only the categories you select, after you apply. Unattached media is measured and reported but never removed, because deciding what a media file is for needs a human.

= Is there an archive size limit? =

There is no paid archive size cap. Your host's available disk, memory and request limits still apply. Very large individual database rows can exceed the format's frame limits. Test your workload on the intended host.

= Do I need an account or an external migration service? =

No. The free plugin operates on your sites and your uploaded archives. You transfer the files yourself.

= What happens when a job is interrupted? =

The job saves durable checkpoints. Supported interruptions can resume from the last completed checkpoint. Errors caused by changed files, changed archive parts, storage failure or other failed safety checks may require intervention. Keep the original archive and inspect the reported error.

= Can I restore a backup on another domain? =

Yes, with the destination URL settings and URL rewriting. Recognized serialized values are handled without instantiating PHP objects. Inspect custom serialized and binary data after migration; parse failures are reported.

= Must I pause writes on the source? =

Yes, for a consistent final migration. Pause orders, submissions, uploads and external writers from export start to completion. Maintenance mode alone does not stop every background process. Concurrent changes can stop export or leave unvisited content out of the backup.

= Does verification guarantee a working website? =

No. Archive verification checks the archive and the supported restore paths. You still need to test the restored site's pages, accounts, media, integrations and business workflows.

= Are encrypted archives authenticated? =

Yes. The selected backend uses authenticated encryption. Unencrypted archive checksums detect accidental corruption but do not authenticate an archive against deliberate modification. Only import backups from sources you trust.

= Can I import an archive from the earlier closed MUDRAVA plugin? =

This public plugin uses a different container format. Earlier archives sharing the `.mudrava` extension are not assumed compatible. Keep the older reader and original backups until compatibility or a conversion path is explicitly documented.

= Does this release include scheduled cloud backups? =

No. This release provides the local manual migration and backup workflow. It can continue an already started export through cron; that is different from scheduling new backups.

== Screenshots ==

1. Create a backup and download the completed archive.
2. Upload an archive and review the destination restore point options.
3. Choose optional password encryption, splitting and exclusions.
4. Check PHP, extensions and private storage in the Environment report.
5. Browse local archives, including saved destination restore points.

== Changelog ==

= 1.1.1 =
* Fixed: WordPress.org listing metadata now fits the directory limits. Tags are trimmed to five relevant terms (previously nine were supplied, so the last four - including the intended WP-CLI and database tags - were silently ignored), and the short description is now 138 characters (previously 187, so it was truncated on import). No functional or format change; archives from 1.0.x remain byte-compatible.

= 1.1.0 =
* Added: `wp mudrava` WP-CLI bridge: preflight, inventory, maintenance, export, import, status, resume, abort, archives and a single machine-readable `report` for automation, through the same safety gates as the browser (proven set completeness, restore point when disk affords it, explicit `--proceed-unsafe` acknowledgement otherwise). JSON output by default, CI-friendly exit codes.
* Added: Maintenance screen and CLI command: revisions, spam, trashed comments, auto-drafts, expired transients and orphaned post meta are counted before anything is deleted; only selected items are removed; unattached media and autoload are measured as advisories, never pruned.
* Added: software inventory in every archive (active theme, plugin and mu-plugin versions) plus a pre-restore environment diff that flags plugins the destination lacks, plugins older here than on the source and a different theme.
* Added: hand-written interface translations in 27 languages.
* Tested: the real upgrade path from 1.0.1 on fresh WordPress 7.1.5 installs (PHP 8.3, MariaDB 10.11): upgrade keeps the plugin active, maintenance counts match row by row, a password-encrypted 33-part archive was exported and restored onto a second clean 7.1.5 site with all URLs rewritten and zero notices in the debug log. The E2E migration lab plus 594 PHPUnit tests, PHPStan and PHPCS run against this exact build.
* Fixed: the orphaned post meta scan uses the real postmeta column and non-reserved aliases, so maintenance counts work on MariaDB as well as MySQL.
* Fixed: WP-CLI import stages split sets from disk with the correct 0002-based part numbering and refuses a numbering gap instead of stopping silently.

= 1.0.1 =
* Tested: verified full compatibility with WordPress 7.1.4 - the full E2E migration lab (export and restore, encrypted archive round trip, split archive upload) plus 580 PHPUnit tests, PHPStan and PHPCS were re-run against this exact build on WordPress 7.1.4 and PHP 8.3. No changes to the engine code.
* Fixed: the plugin version constant now matches the release version.

= 1.0.0 =
* Initial public release: resumable export and restore, portable archives, optional authenticated encryption, chunked uploads, split sets, exclusions, URL rewriting and archive verification before restore.
* Destination restore points, recovery attempts, private archive storage and environment checks.
