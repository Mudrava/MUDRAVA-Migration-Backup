<?php

/**
 * Request-local protection for the core and trusted installed add-ons.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Integration;

use Mudrava\Migration\Filesystem\PathGuard;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Canonical filesystem boundaries require realpath.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Trusted PHP integration API errors.

final class ProtectedDirectories
{
    /** @var array<string,bool> */
    private static $directories = [];

    /** Register on every request before starting or advancing a job. */
    public static function register(string $directory): void
    {
        $real = realpath($directory);
        if ($real === false || !is_dir($real)) {
            throw new \InvalidArgumentException('MUDRAVA_INVALID_PROTECTED_DIRECTORY');
        }
        self::$directories[rtrim($real, '/')] = true;
    }

    /** @return list<string> */
    public static function relativeTo(string $root): array
    {
        $canonical = realpath($root);
        if ($canonical === false) {
            return [];
        }
        $canonical = rtrim($canonical, '/') . '/';
        $directories = array_keys(self::$directories);
        if (defined('MUDRAVA_MB_PLUGIN_DIR')) {
            $core = realpath((string) MUDRAVA_MB_PLUGIN_DIR);
            $directories[] = rtrim($core !== false ? $core : (string) MUDRAVA_MB_PLUGIN_DIR, '/');
        }
        $relative = [];
        foreach ($directories as $directory) {
            // Never let a root registration exclude the entire site.
            if (strpos($directory, $canonical) === 0) {
                $relative[] = substr($directory, strlen($canonical));
            }
        }
        return array_values(array_unique($relative));
    }

    public static function contains(string $path, string $root): bool
    {
        $path = PathGuard::normalize($path);
        foreach (self::relativeTo($root) as $directory) {
            if ($path === $directory || strpos($path, $directory . '/') === 0) {
                return true;
            }
        }
        return false;
    }
}
