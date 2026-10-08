=== MUDRAVA Migration & Backup ===
Contributors: mudrava
Tags: migration, backup, restore, clone, staging
Requires at least: 6.0
Tested up to: 7.1.3
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free WordPress migration, backup and restore. Resumable exports, optional encryption and no paid archive size cap.

== Description ==

Move your WordPress site to another host or domain, keep a backup before a change, or restore a saved copy. MUDRAVA Migration & Backup puts your database and selected site files into a portable `.mudrava` archive.

The free plugin includes export, import and restore. You do not need a MUDRAVA account, and you do not need a paid extension to remove an archive size cap. Available disk, PHP and database resources still determine what your host can handle.

= A complete free migration workflow =

* Export your database and selected site files, including media, themes and plugins.
* Upload archives in chunks rather than one large request.
* Resume supported interrupted jobs from saved checkpoints.
* Protect an archive with optional password encryption.
* Split a backup into parts when that makes transfer easier.
* Exclude tables or paths you do not want to copy.
* Rewrite URLs, including recognized PHP-serialized values.
* Check the full archive before the restore begins replacing site data.

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

Yes. Manual export, import, restore, optional encryption, splitting and resumable jobs are included in the free plugin.

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

= 1.0.0 =
* Initial public release: resumable export and restore, portable archives, optional authenticated encryption, chunked uploads, split sets, exclusions, URL rewriting and archive verification before restore.
* Destination restore points, recovery attempts, private archive storage and environment checks.
