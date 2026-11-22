<?php

/**
 * Minimal WP-CLI stubs for PHPStan. The real WP_CLI class only exists in
 * a wp-cli process; the plugin guards every reference with a WP_CLI
 * constant check. This file is scanned by PHPStan only (phpstan.neon
 * scanDirectories: tests/stubs) and must never be loaded at runtime.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

if (!class_exists('WP_CLI')) {
    class WP_CLI
    {
        /**
         * @param callable|array<int|string,mixed>|string $callable
         */
        public static function add_command(string $name, $callable): void
        {
        }

        public static function line(string $message = ''): void
        {
        }

        /**
         * @param string|WP_Error $message
         */
        public static function log($message): void
        {
        }

        /**
         * @param string|WP_Error $message
         */
        public static function success($message): void
        {
        }

        /**
         * @param string|WP_Error $message
         */
        public static function warning($message): void
        {
        }

        /**
         * @param string|WP_Error $message
         */
        public static function error($message, bool $exit = true): void
        {
        }

        public static function halt(int $return_code): void
        {
        }
    }
}
