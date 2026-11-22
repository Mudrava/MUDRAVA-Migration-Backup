<?php

/**
 * Software inventory: what runs this site, right now. Active plugins with
 * versions, the theme family, must-use plugins. Carried inside every
 * archive's SITE_METADATA so the destination can compare before it
 * overwrites, instead of discovering a missing page builder in blank
 * pages afterwards.
 *
 * The snapshot is a plain data function (arrays in, array out) so unit
 * tests can pin its shape; current() is the thin WordPress adapter.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Support;

defined('ABSPATH') || exit;

final class Software
{
    /** Plugin entries per archive frame; a site with more caps the list. */
    public const MAX_PLUGINS = 250;

    /**
     * Pure builder: active plugin basenames, plugin header data, theme
     * fields, mu-plugin names. Keys of $pluginData are plugin basenames
     * ("elementor/elementor.php") exactly as get_plugins() returns them.
     *
     * @param list<string> $activePaths
     * @param array<string,array<string,mixed>> $pluginData
     * @param array<string,string> $theme
     * @param list<string> $muPlugins
     * @return array{theme:array{name:string,version:string,parent:string},plugins:array<string,array{name:string,version:string}>,mu_plugins:list<string>,truncated:bool}
     */
    public static function snapshot(array $activePaths, array $pluginData, array $theme, array $muPlugins): array
    {
        $plugins = [];
        $truncated = false;
        foreach (array_values($activePaths) as $i => $path) {
            $path = (string) $path;
            if ($path === '') {
                continue;
            }
            if (count($plugins) >= self::MAX_PLUGINS) {
                $truncated = true;
                break;
            }
            $name = (string) ($pluginData[$path]['Name'] ?? $path);
            $version = (string) ($pluginData[$path]['Version'] ?? '');
            $plugins[$path] = ['name' => $name, 'version' => $version];
            unset($i);
        }
        return [
            'theme' => [
                'name' => (string) ($theme['name'] ?? ''),
                'version' => (string) ($theme['version'] ?? ''),
                'parent' => (string) ($theme['parent'] ?? ''),
            ],
            'plugins' => $plugins,
            'mu_plugins' => array_values($muPlugins),
            'truncated' => $truncated,
        ];
    }

    /**
     * Live snapshot of this installation. get_plugins() lives in
     * wp-admin/includes/plugin.php, which is not loaded on front-end or
     * REST requests by default, so the adapter loads it on demand.
     *
     * @return array{theme:array{name:string,version:string,parent:string},plugins:array<string,array{name:string,version:string}>,mu_plugins:list<string>,truncated:bool}
     */
    public static function current(): array
    {
        if (!function_exists('get_plugins')) {
            $file = ABSPATH . 'wp-admin/includes/plugin.php';
            if (is_readable($file)) {
                require_once $file;
            }
        }
        $pluginData = function_exists('get_plugins') ? get_plugins() : [];
        /** @var list<string> $active */
        $active = (array) get_option('active_plugins', []);
        // Network-activated plugins run too; in a single-site install the
        // option is usually empty, and reading it cannot fail.
        $network = (array) get_site_option('active_sitewide_plugins', []);
        foreach (array_keys($network) as $path) {
            $path = (string) $path;
            if ($path !== '' && !in_array($path, $active, true)) {
                $active[] = $path;
            }
        }

        $theme = ['name' => '', 'version' => '', 'parent' => ''];
        if (function_exists('wp_get_theme')) {
            $t = wp_get_theme();
            $theme['name'] = (string) $t->get('Name');
            $theme['version'] = (string) $t->get('Version');
            if ($t->parent() !== false && $t->parent()->exists()) {
                $theme['parent'] = (string) $t->parent()->get('Name')
                    . ' ' . (string) $t->parent()->get('Version');
            }
        }

        $mu = [];
        $muDir = defined('WPMU_PLUGIN_DIR')
            ? WPMU_PLUGIN_DIR
            : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/mu-plugins' : '');
        if ($muDir !== '' && is_dir($muDir)) {
            $entries = scandir($muDir) ?: [];
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === 'index.php') {
                    continue;
                }
                if (substr($entry, -4) === '.php' || is_dir($muDir . '/' . $entry)) {
                    $mu[] = $entry;
                }
            }
        }

        return self::snapshot($active, $pluginData, $theme, $mu);
    }
}
