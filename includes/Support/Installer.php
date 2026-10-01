<?php

/**
 * Activation / deactivation: capability checks, storage bootstrap, cron.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Support;

defined('ABSPATH') || exit;

final class Installer
{
    public const OPTION_VERSION = 'mudrava_version';

    public static function activate(): void
    {
        if (!Runtime::isSupported()) {
            deactivate_plugins(plugin_basename(MUDRAVA_MB_PLUGIN_FILE));
            wp_die(esc_html__(
                'MUDRAVA Migration & Backup cannot run on this server: PHP 7.4+ (64-bit), zlib and JSON are required.',
                'mudrava-migration-backup'
            ));
        }
        $paths = new Paths();
        $paths->ensureStorage();
        $paths->migrateLegacyArchives();
        update_option(self::OPTION_VERSION, MUDRAVA_MB_VERSION);

        if (!wp_next_scheduled('mudrava_tick')) {
            wp_schedule_event(time() + 60, 'mudrava_minute', 'mudrava_tick');
        }
        if (!wp_next_scheduled('mudrava_cleanup_uploads')) {
            wp_schedule_event(time() + 60, 'mudrava_minute', 'mudrava_cleanup_uploads');
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('mudrava_tick');
        wp_clear_scheduled_hook('mudrava_cleanup_uploads');
    }
}
