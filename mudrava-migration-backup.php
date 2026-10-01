<?php

/**
 * Plugin Name:       MUDRAVA Migration & Backup
 * Plugin URI:        https://wordpress.org/plugins/mudrava-migration-backup/
 * Description:       Migrate, back up and restore WordPress sites with one portable .mudrava file, resumable exports and optional encryption.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            MUDRAVA
 * Author URI:        https://mudrava.com/en/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mudrava-migration-backup
 * Domain Path:       /languages
 *
 * MUDRAVA Migration & Backup
 * Copyright (C) 2026 MUDRAVA
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2, as
 * published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

declare(strict_types=1);

namespace Mudrava\Migration;

defined('ABSPATH') || exit;

define('MUDRAVA_MB_VERSION', '1.0.0');
define('MUDRAVA_MB_PLUGIN_FILE', __FILE__);
define('MUDRAVA_MB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MUDRAVA_MB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MUDRAVA_MB_MIN_PHP', '7.4');

/**
 * PSR-4-ish autoloader for the plugin namespace (no Composer requirement
 * on production hosts; Composer autoload is used in development).
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Mudrava\\Migration\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = MUDRAVA_MB_PLUGIN_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

// Composer autoloader when present (dev dependencies never ship in the dist).
if (is_file(MUDRAVA_MB_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once MUDRAVA_MB_PLUGIN_DIR . 'vendor/autoload.php';
}

register_activation_hook(__FILE__, static function (): void {
    if (version_compare(PHP_VERSION, MUDRAVA_MB_MIN_PHP, '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(esc_html__(
            'MUDRAVA Migration & Backup requires PHP 7.4 or newer. Please upgrade PHP before activating.',
            'mudrava-migration-backup'
        ));
    }
    \Mudrava\Migration\Support\Installer::activate();
});

register_deactivation_hook(__FILE__, static function (): void {
    \Mudrava\Migration\Support\Installer::deactivate();
});

\Mudrava\Migration\Plugin::instance()->boot();
