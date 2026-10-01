<?php

/**
 * Uninstall: remove plugin options. Private archives are never deleted
 * automatically because one may be the user's only backup.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

// Deleting an ACTIVE plugin never runs the deactivation hook, so the
// tick cron must be cleared here too or it would linger in the cron table.
wp_clear_scheduled_hook('mudrava_tick');

delete_option('mudrava_job');
delete_option('mudrava_version');
delete_option('mudrava_restore_point');
delete_option('mudrava_cron_beat');
delete_option('mudrava_uninstalled_kept_archives');
