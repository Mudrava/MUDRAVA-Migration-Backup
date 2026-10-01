<?php

/**
 * Trusted add-on renderers for the shared product screen.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Integration;

defined('ABSPATH') || exit;

final class AdminPanels
{
    /** @var array<string,callable():void> */
    private static $renderers = [];

    /** @var array<string,string> */
    private static $labels = [];

    /** @return array<string,string> */
    public static function tabs(): array
    {
        return self::$labels;
    }

    /** @param callable():void $renderer */
    public static function register(string $slug, callable $renderer, string $label = ''): void
    {
        if (!in_array($slug, ['schedule', 'storage', 'notifications', 'pro'], true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Trusted PHP API.
            throw new \InvalidArgumentException('MUDRAVA_INVALID_ADMIN_PANEL');
        }
        self::$renderers[$slug] = $renderer;
        self::$labels[$slug] = $label !== '' ? $label : ucfirst($slug);
    }

    public static function render(string $slug): bool
    {
        if (!isset(self::$renderers[$slug])) {
            return false;
        }
        $level = ob_get_level();
        ob_start();
        try {
            call_user_func(self::$renderers[$slug]);
            $html = (string) ob_get_clean();
        } catch (\Throwable $error) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            return false;
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Installed add-on renders its own escaped markup, like a WP admin callback.
        echo $html;
        return true;
    }
}
