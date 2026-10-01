<?php

/**
 * Plugin bootstrap: wires hooks, REST, admin, cron. No work happens at
 * file-load time beyond hook registration - shared-host safe.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration;

use Mudrava\Migration\Support\Capabilities;
use Mudrava\Migration\Support\Logger;
use Mudrava\Migration\Support\Paths;

defined('ABSPATH') || exit;

final class Plugin
{
    /** @var Plugin|null */
    private static $instance = null;

    /** @var Logger|null */
    private $logger = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
    }

    public function boot(): void
    {
        // Registered directly, not on 'init': a plugin activated via WP-CLI or
        // the REST API is loaded after 'init' has already fired, so an
        // init-hooked filter would never register and wp_schedule_event()
        // would silently fail to find the 'mudrava_minute' recurrence.
        $this->registerCronSchedules();
        add_action('rest_api_init', [$this, 'registerRest']);
        add_action('admin_menu', [$this, 'registerAdminMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('admin_init', static function (): void {
            try {
                (new Support\Paths())->migrateLegacyArchives();
            } catch (\RuntimeException $error) {
                // A root-owned or otherwise inaccessible private directory
                // must not take down every wp-admin page. Preflight reports
                // the storage failure on this plugin's Environment tab.
                return;
            }
        });
        add_action('mudrava_tick', [Migration\JobRunner::class, 'tickCron']);
        add_action('mudrava_cleanup_uploads', [Http\ChunkUpload::class, 'cleanupStale']);
        add_action('wp_loaded', [Migration\JobRunner::class, 'flushRewriteRulesAfterImport'], 999);
        $cleanupEvent = wp_get_scheduled_event('mudrava_cleanup_uploads');
        if ($cleanupEvent && $cleanupEvent->schedule !== 'mudrava_minute') {
            wp_clear_scheduled_hook('mudrava_cleanup_uploads');
        }
        if (!wp_next_scheduled('mudrava_cleanup_uploads')) {
            wp_schedule_event(time() + 60, 'mudrava_minute', 'mudrava_cleanup_uploads');
        }
        add_filter('map_meta_cap', [Capabilities::class, 'map'], 10, 4);
        // Restore-scoped auth: after the cookie handlers (10/20) fail because
        // a running restore has replaced wp_usermeta, a valid restore token
        // keeps the operator authenticated so ticks keep working.
        add_filter('determine_current_user', [Http\RestoreToken::class, 'authenticate'], 25);
    }

    /** Custom 1-minute schedule so ticks do not depend on host cron precision. */
    public function registerCronSchedules(): void
    {
        add_filter('cron_schedules', static function (array $schedules): array {
            $schedules['mudrava_minute'] = [
                'interval' => 60,
                'display'  => 'Every MUDRAVA minute',
            ];
            return $schedules;
        });
    }

    public function registerRest(): void
    {
        Http\RestApi::register();
    }

    public function registerAdminMenu(): void
    {
        add_menu_page(
            __('MUDRAVA Migration', 'mudrava-migration-backup'),
            __('MUDRAVA', 'mudrava-migration-backup'),
            Capabilities::CAP,
            'mudrava',
            [Admin\AdminPage::class, 'render'],
            'dashicons-backup',
            66
        );
    }

    /**
     * Asset cache-buster: the file's own mtime, so an edited admin.js or
     * admin.css is refetched immediately instead of being served stale
     * until the plugin version happens to change. Falls back to the
     * plugin version when the file cannot be stat'd.
     */
    private static function assetVer(string $rel): string
    {
        $path = MUDRAVA_MB_PLUGIN_DIR . $rel;
        $mtime = is_file($path) ? @filemtime($path) : false;
        return $mtime === false ? MUDRAVA_MB_VERSION : (string) $mtime;
    }

    public function enqueueAdminAssets(string $hook): void
    {
        if ($hook !== 'toplevel_page_mudrava') {
            return;
        }
        // Cache-busting by file mtime, not the static plugin version:
        // a released 1.0.0 that edits admin.js would otherwise keep
        // serving the old file to every browser until the version bumps.
        $cssVer = self::assetVer('assets/admin.css');
        $jsVer = self::assetVer('assets/admin.js');
        wp_enqueue_style(
            'mudrava-admin',
            MUDRAVA_MB_PLUGIN_URL . 'assets/admin.css',
            [],
            $cssVer
        );
        wp_enqueue_script(
            'mudrava-admin',
            MUDRAVA_MB_PLUGIN_URL . 'assets/admin.js',
            [],
            $jsVer,
            true
        );
        wp_localize_script('mudrava-admin', 'mudravaAdmin', [
            'root'      => esc_url_raw(rest_url('mudrava/v1')),
            'nonce'     => wp_create_nonce('wp_rest'),
            'homeUrl'   => esc_url_raw(home_url()),
            'chunkBytes' => self::uploadChunkBytes(),
            'i18n'      => [
                'export'      => __('Create backup', 'mudrava-migration-backup'),
                'import'      => __('Restore site', 'mudrava-migration-backup'),
                'working'     => __('Working…', 'mudrava-migration-backup'),
                'done'        => __('Done', 'mudrava-migration-backup'),
                'failed'      => __('Failed', 'mudrava-migration-backup'),
                'confirm'     => __('This will overwrite the current site. Are you sure?', 'mudrava-migration-backup'),
                'pwRequired' => __(
                    'Choose a password, or turn off encryption to export unencrypted.',
                    'mudrava-migration-backup'
                ),
                'pwMismatch' => __('Passwords do not match.', 'mudrava-migration-backup'),
                'showPw'     => __('Show password', 'mudrava-migration-backup'),
                'hidePw'     => __('Hide password', 'mudrava-migration-backup'),
                'closeNoteImport' => __(
                    'Keep this tab open until the restore finishes - the restore only advances while this page is open.',
                    'mudrava-migration-backup'
                ),
                'closeNoteNoCron' => __(
                    'Site cron is not running. Without visitors, this job may pause until cron returns. Keep this tab open to be sure.',
                    'mudrava-migration-backup'
                ),
                'hintIsPassword' => __(
                    'The hint cannot be the password or part of it. Describe where it is kept instead, e.g. "company vault".',
                    'mudrava-migration-backup'
                ),
                // Strings rendered by admin.js; localized here so the whole
                // UI is translatable from PHP (no JS-side i18n runtime).
                'uploading'   => __('Uploading', 'mudrava-migration-backup'),
                'assembling'  => __('Assembling archive', 'mudrava-migration-backup'),
                'cancelled'   => __('Cancelled.', 'mudrava-migration-backup'),
                'cancelling'  => __('Cancelling…', 'mudrava-migration-backup'),
                'prevPage'    => __('Previous page', 'mudrava-migration-backup'),
                'nextPage'    => __('Next page', 'mudrava-migration-backup'),
                'pageWord'    => __('Page', 'mudrava-migration-backup'),
                'part'        => __('part', 'mudrava-migration-backup'),
                'parts'       => __('part(s)', 'mudrava-migration-backup'),
                'download'    => __('Download', 'mudrava-migration-backup'),
                'downloadAll' => __('Download all', 'mudrava-migration-backup'),
                'downloadPart' => __('Download part', 'mudrava-migration-backup'),
                'individualParts' => __('Individual parts', 'mudrava-migration-backup'),
                'missingParts' => __('missing parts', 'mudrava-migration-backup'),
                'unknown'     => __('unknown', 'mudrava-migration-backup'),
                'job'         => __('job', 'mudrava-migration-backup'),
                'running'     => __('running', 'mudrava-migration-backup'),
                'resuming'    => __('Resuming the interrupted job…', 'mudrava-migration-backup'),
                'resumePassword' => __('Enter the archive password to continue.', 'mudrava-migration-backup'),
                'uploadIncomplete' => __('upload incomplete', 'mudrava-migration-backup'),
                'noArchives'  => __('No archives on this site yet.', 'mudrava-migration-backup'),
                'encrypted'   => __('encrypted', 'mudrava-migration-backup'),
                'hint'        => __('hint', 'mudrava-migration-backup'),
                'partsTag'    => __('parts', 'mudrava-migration-backup'),
                'importSetComplete' => __('set complete', 'mudrava-migration-backup'),
                'rpCreating' => __('Saving a restore point of this site', 'mudrava-migration-backup'),
                'restorePointSaved' => __('Restore point saved', 'mudrava-migration-backup'),
                'rollingBack' => __('Restoring the previous site after an import error', 'mudrava-migration-backup'),
                'rollbackDone' => __('Restore point reapplied; verify the site', 'mudrava-migration-backup'),
                'rollbackFailed' => __('Automatic recovery failed; inspect the restore point', 'mudrava-migration-backup'),
                /* translators: %s: names of unrecognized archive files. */
                'importHoldBadNames' => __(
                    'Unrecognized files (must be *.mudrava or *.mudrava.partNNNN): %s',
                    'mudrava-migration-backup'
                ),
                'importHoldMixed' => __(
                    'Files from different archives are selected. Pick the parts of ONE archive.',
                    'mudrava-migration-backup'
                ),
                /* translators: %s: names of duplicate archive parts. */
                'importHoldDup'   => __('Duplicate parts selected: %s', 'mudrava-migration-backup'),
                'importHoldNoFirst' => __(
                    'Part 1 of the set is missing: the plain .mudrava file (no .partNNNN suffix).',
                    'mudrava-migration-backup'
                ),
                /* translators: %s: numbers of missing archive parts. */
                'importHoldMissing' => __(
                    'The set is incomplete - missing parts: %s',
                    'mudrava-migration-backup'
                ),
                /* translators: %s: number of the missing final archive part. */
                'importHoldNoTail' => __(
                    'The last selected file is not the end of the archive - part %s is still missing.',
                    'mudrava-migration-backup'
                ),
                'importHoldSplit1' => __(
                    'This is part 1 of a split set, not a complete archive. Select the .mudrava file AND every .mudrava.partNNNN file of this set.',
                    'mudrava-migration-backup'
                ),
                'importHoldNoHeader' => __(
                    'The first file is not a valid .mudrava archive (bad header).',
                    'mudrava-migration-backup'
                ),
                /* translators: %s: archive part number. */
                'importHoldBadSlot' => __(
                    'Part %s is not a valid continuation part (renamed or wrong file).',
                    'mudrava-migration-backup'
                ),
                /* translators: %s: archive part number. */
                'importHoldWrongSlot' => __(
                    'Slot %s holds a file that belongs to a different archive or a different part.',
                    'mudrava-migration-backup'
                ),
                'importHoldUnreadable' => __(
                    'Could not read the selected files. Select them again.',
                    'mudrava-migration-backup'
                ),
                'rpSafe'      => __(
                    'Restore point: recommended. There is enough free disk to keep a safety copy of this site before overwriting.',
                    'mudrava-migration-backup'
                ),
                'rpUnsafe'    => __(
                    'Not enough disk space for a restore point. A failed restore will need another backup to recover. Check the box to accept this risk.',
                    'mudrava-migration-backup'
                ),
                'phase_database' => __('database', 'mudrava-migration-backup'),
                'phase_files'    => __('files', 'mudrava-migration-backup'),
                'phase_finalize' => __('finalize', 'mudrava-migration-backup'),
                'phase_done' => __('Completed', 'mudrava-migration-backup'),
                'phase_rollback_verifying' => __('Verifying the restore point', 'mudrava-migration-backup'),
                'phase_rollback_restoring' => __('Restoring the previous site', 'mudrava-migration-backup'),
                'phase_rollback_cleaning' => __('Removing files and tables created by the failed import', 'mudrava-migration-backup'),
                'phase_rollback_done' => __('Restore point reapplied', 'mudrava-migration-backup'),
                // Content pickers (tables / wp-content folders).
                'pickerLoading' => __('Measuring this site…', 'mudrava-migration-backup'),
                'pickerFailed'  => __(
                    'Could not load the live inventory. Reload the page to try again.',
                    'mudrava-migration-backup'
                ),
                'pickerAll'     => __('all included', 'mudrava-migration-backup'),
                'pickerCore'    => __(
                    'WordPress core table - always included',
                    'mudrava-migration-backup'
                ),
                'pickerApprox'  => __(
                    'Sizes are approximate on very large sites.',
                    'mudrava-migration-backup'
                ),
                'rowsWord'      => __('rows', 'mudrava-migration-backup'),
                'filesWord'     => __('files', 'mudrava-migration-backup'),
                'contentEdit' => __('Change what is included', 'mudrava-migration-backup'),
                'contentEditClose' => __('Done editing', 'mudrava-migration-backup'),
                'contentAll'  => __('Everything included', 'mudrava-migration-backup'),
                'contentExcluded' => __('Excluded', 'mudrava-migration-backup'),
            ],
        ]);
    }

    /**
     * Upload chunk size: 75% of the smallest PHP request limit, up to 16 MiB.
     * Multipart form boundaries and fields need room inside post_max_size.
     */
    public static function uploadChunkBytes(): int
    {
        $limits = [];
        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $raw = (string) ini_get($key);
            $bytes = self::iniBytes($raw);
            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }
        return self::chunkBytesForLimit($limits === [] ? 0 : min($limits));
    }

    private static function chunkBytesForLimit(int $limit): int
    {
        if ($limit <= 0) {
            return 8 * 1048576;
        }
        return (int) max(1024, min(16777216, (int) ($limit * 0.75)));
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '0') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $num = (int) $value;
        if ($unit === 'g') {
            return $num * 1073741824;
        }
        if ($unit === 'm') {
            return $num * 1048576;
        }
        if ($unit === 'k') {
            return $num * 1024;
        }
        return $num;
    }

    public function logger(): Logger
    {
        if ($this->logger === null) {
            $this->logger = new Logger();
        }
        return $this->logger;
    }

    public function paths(): Paths
    {
        return new Paths();
    }
}
