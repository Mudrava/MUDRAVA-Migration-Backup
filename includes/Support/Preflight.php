<?php

/**
 * Preflight: everything that must be true before a job starts, reported
 * honestly. Never claims more disk, memory or time than measured.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Support;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Protected binary archives require seekable native streams and atomic file operations.

final class Preflight
{
    /**
     * @return array{checks:list<array{code:string,ok:bool,detail:string}>,warnings:list<string>,site:array<string,mixed>,can_run:bool}
     */
    public static function run(): array
    {
        $checks = Runtime::inspect();
        $warnings = [];
        $checks[] = [
            'code'   => 'single_site',
            'ok'     => !is_multisite(),
            'detail' => is_multisite() ? 'Multisite is not supported by this edition' : 'ok',
        ];

        $paths = new Paths();
        $storage = '';
        $storageReady = false;
        try {
            $storage = $paths->ensureStorage();
            $storageReady = true;
        } catch (\RuntimeException $error) {
            $warnings[] = 'Private backup storage is unsafe or inaccessible. Set MUDRAVA_MB_STORAGE_DIR '
                . 'to one writable directory outside the web root.';
        }
        $checks[] = [
            'code'   => 'storage_writable',
            'ok'     => $storageReady,
            'detail' => $storageReady ? 'ok' : 'private storage needs repair',
        ];
        if ($storageReady && strpos($storage . '/', rtrim(sys_get_temp_dir(), '/') . '/') === 0) {
            $warnings[] = 'Backups are in the system temporary directory and may be removed by the host. '
                . 'Set MUDRAVA_MB_STORAGE_DIR to a durable private path outside the web root.';
        }
        $legacyCount = $paths->legacyPublicArchiveCount();
        if ($legacyCount > 0) {
            $warnings[] = sprintf(
                '%d archive file(s) remain in the old public wp-content/mudrava-backups directory. '
                . 'Move them to the configured private storage and remove public copies before using this site in production.',
                $legacyCount
            );
        }

        $free = $storageReady ? @disk_free_space($storage) : false;
        $freeInt = $free === false ? null : (int) $free;
        $checks[] = [
            'code'   => 'disk_free',
            'ok'     => $freeInt !== null && $freeInt > 512 * 1048576,
            'detail' => $freeInt === null ? 'unknown' : self::humanSize($freeInt) . ' free',
        ];

        $siteBytes = self::siteSizeBytes();
        $checks[] = [
            'code'   => 'site_size',
            'ok'     => true,
            'detail' => self::humanSize($siteBytes) . ' (files; DB measured at export start)',
        ];

        // Rollback headroom per ADR-0005: safe restore needs site + archive.
        if ($freeInt !== null) {
            $need = $siteBytes * 2;
            if ($freeInt < $need) {
                $warnings[] = sprintf(
                    'Safe rollback needs ~%s more free space. Restore without a restore point is '
                    . 'possible - the restore screen lets you accept the higher risk - but is not recommended.',
                    self::humanSize($need - $freeInt)
                );
            }
        }

        $canRun = true;
        foreach ($checks as $c) {
            if (!$c['ok'] && in_array($c['code'], ['php_version', 'int64', 'zlib', 'json', 'storage_writable', 'single_site'], true)) {
                $canRun = false;
            }
        }

        return [
            'checks'   => $checks,
            'warnings' => $warnings,
            'site'     => self::siteMeta(),
            'can_run'  => $canRun,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function siteMeta(): array
    {
        global $wpdb;
        return [
            'site_url'     => home_url(),
            'home_url'     => get_option('home'),
            'wp_version'   => get_bloginfo('version'),
            'charset'      => (string) get_option('blog_charset'),
            'db_name'      => defined('DB_NAME') ? DB_NAME : '',
            'db_prefix'    => $wpdb->prefix,
            'multisite'    => is_multisite(),
            'php_version'  => PHP_VERSION,
            'producer'     => 'mudrava-migration-backup ' . MUDRAVA_MB_VERSION,
            'created_at'   => time(),
        ];
    }

    public static function siteSizeBytes(): int
    {
        // Bounded sample: sum of top-level dir sizes is too slow on huge
        // sites; use a quick du-style walk capped at 200k entries.
        $root = trailingslashit(ABSPATH);
        $bytes = 0;
        $count = 0;
        $stack = [$root];
        while ($stack !== [] && $count < 200000) {
            $dir = (string) array_pop($stack);
            $dh = @opendir($dir);
            if ($dh === false) {
                continue;
            }
            while (($name = readdir($dh)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $path = $dir . $name;
                if (is_link($path)) {
                    continue;
                }
                if (is_dir($path)) {
                    $stack[] = $path . '/';
                } elseif (is_file($path)) {
                    $bytes += (int) @filesize($path);
                }
                if (++$count >= 200000) {
                    closedir($dh);
                    return $bytes;
                }
            }
            closedir($dh);
        }
        return $bytes;
    }

    private static function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float) $bytes;
        while ($v >= 1024 && $i < 4) {
            $v /= 1024;
            $i++;
        }
        return round($v, 1) . ' ' . $units[$i];
    }
}
