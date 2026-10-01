<?php
/**
 * Static-analysis stubs for constants defined at runtime by the main plugin
 * file. Scanned by PHPStan only; never loaded in production.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

if (!defined('MUDRAVA_MB_VERSION')) {
    define('MUDRAVA_MB_VERSION', '0.0.0');
}
if (!defined('MUDRAVA_MB_PLUGIN_FILE')) {
    define('MUDRAVA_MB_PLUGIN_FILE', __DIR__ . '/../../mudrava-migration-backup.php');
}
if (!defined('MUDRAVA_MB_PLUGIN_DIR')) {
    define('MUDRAVA_MB_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
}
if (!defined('MUDRAVA_MB_PLUGIN_URL')) {
    define('MUDRAVA_MB_PLUGIN_URL', 'https://example.invalid/wp-content/plugins/mudrava-migration-backup/');
}
if (!defined('MUDRAVA_MB_MIN_PHP')) {
    define('MUDRAVA_MB_MIN_PHP', '7.4');
}
