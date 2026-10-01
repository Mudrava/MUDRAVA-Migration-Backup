<?php

/**
 * Read-only site inventory for the export screen: every real table in this
 * database and every real top-level folder of wp-content, each with an
 * honest size measurement. Powers the include/exclude pickers; nothing is
 * mutated, so the REST endpoint is a plain GET.
 *
 * The filesystem walk is bounded (WALK_CAP entries) exactly like the
 * preflight site-size check: on huge sites sizes are marked approximate
 * instead of pretending a full scan happened.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Support;

use Mudrava\Migration\Filesystem\WpFileInventory;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The picker measures the live schema and must not serve cached results.
final class Inventory
{
    /** Max filesystem entries per walk; beyond it sizes are approximate. */
    public const WALK_CAP = 200000;

    /**
     * Table-name suffixes that belong to the WordPress core schema. A
     * core table left out of an archive bricks the destination (the
     * importer only creates what the archive contains), so these are
     * locked in the picker.
     */
    private const CORE_SUFFIXES = [
        'posts', 'comments', 'links', 'options', 'postmeta', 'commentmeta',
        'terms', 'term_taxonomy', 'term_relationships', 'termmeta',
        'users', 'usermeta', 'blogmeta', 'blogs', 'signups',
        'sitecategories', 'sitemeta',
    ];

    /** Friendly labels for well-known wp-content children. */
    private const KNOWN_LABELS = [
        'uploads' => 'Media (uploads)',
        'plugins' => 'Plugins',
        'themes' => 'Themes',
        'languages' => 'Translations',
        'mu-plugins' => 'Must-use plugins',
    ];

    /** Known groups render first, in this order; the rest sort by name. */
    private const KNOWN_ORDER = ['uploads', 'plugins', 'themes', 'languages', 'mu-plugins'];

    /**
     * @return array{tables:list<array<string,mixed>>,files:list<array<string,mixed>>,core_bytes:int,core_files:int,approx:bool}
     */
    public static function run(): array
    {
        global $wpdb;
        $prefix = (string) $wpdb->prefix;

        // One information_schema pass: rows are an InnoDB estimate, so the
        // UI always renders them with a "~" - never as an exact promise.
        /** @var array<string,array{rows:int,bytes:int}> $sizes */
        $sizes = [];
        $meta = $wpdb->get_results(
            'SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH + INDEX_LENGTH AS BYTES'
            . ' FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()',
            ARRAY_A
        );
        foreach ((array) $meta as $row) {
            $sizes[(string) $row['TABLE_NAME']] = [
                'rows'  => (int) $row['TABLE_ROWS'],
                'bytes' => (int) $row['BYTES'],
            ];
        }

        $tables = [];
        foreach ((array) $wpdb->get_col('SHOW TABLES') as $name) {
            $name = (string) $name;
            $tables[] = [
                'name'   => $name,
                'rows'   => isset($sizes[$name]) ? $sizes[$name]['rows'] : 0,
                'bytes'  => isset($sizes[$name]) ? $sizes[$name]['bytes'] : 0,
                'locked' => self::isCoreTable($name, $prefix),
            ];
        }
        usort($tables, static function (array $a, array $b): int {
            return strcmp((string) $a['name'], (string) $b['name']);
        });

        $walk = self::walk();
        return [
            'tables'     => $tables,
            'files'      => self::fileGroups($walk['buckets']),
            'core_bytes' => $walk['core_bytes'],
            'core_files' => $walk['core_files'],
            'approx'     => $walk['approx'],
        ];
    }

    /**
     * Whether a table belongs to the WordPress core schema for a prefix.
     * Pure so it can be unit-tested without a database.
     */
    public static function isCoreTable(string $table, string $prefix): bool
    {
        if ($prefix === '' || strpos($table, $prefix) !== 0) {
            return false;
        }
        return in_array(substr($table, strlen($prefix)), self::CORE_SUFFIXES, true);
    }

    /**
     * Bucket key for a relative path: its wp-content child folder, the
     * bare 'wp-content' key for files sitting in the wp-content root, or
     * 'core' for everything else (wp-admin, wp-includes, root files).
     */
    public static function bucketFor(string $rel): string
    {
        if (strpos($rel, 'wp-content/') !== 0) {
            return 'core';
        }
        $rest = substr($rel, strlen('wp-content/'));
        $slash = strpos($rest, '/');
        return $slash === false ? 'wp-content' : 'wp-content/' . substr($rest, 0, $slash);
    }

    /**
     * Bounded walk of ABSPATH, bucketing file sizes per picker group.
     * Uses the exact exclusion set the export walk uses, so the sizes
     * shown are the sizes that actually travel.
     *
     * @return array{buckets:array<string,array{bytes:int,files:int}>,core_bytes:int,core_files:int,approx:bool}
     */
    public static function walk(): array
    {
        $root = rtrim(ABSPATH, '/');
        $excludes = WpFileInventory::defaultExcludes($root);
        /** @var array<string,array{bytes:int,files:int}> $buckets */
        $buckets = [];
        $coreBytes = 0;
        $coreFiles = 0;
        $count = 0;
        $approx = false;
        $stack = [''];
        while ($stack !== []) {
            $rel = (string) array_pop($stack);
            $abs = $rel === '' ? $root : $root . '/' . $rel;
            $dh = @opendir($abs);
            if ($dh === false) {
                continue;
            }
            while (($name = readdir($dh)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $child = $rel === '' ? (string) $name : $rel . '/' . (string) $name;
                if (WpFileInventory::pathExcluded($child, $excludes)) {
                    continue;
                }
                $childAbs = $root . '/' . $child;
                if (is_link($childAbs) || !is_file($childAbs)) {
                    // Directories descend; symlinks stay metadata-only in
                    // the real export and contribute no bytes here.
                    if (is_dir($childAbs)) {
                        $stack[] = $child;
                    }
                    continue;
                }
                $bytes = (int) @filesize($childAbs);
                $key = self::bucketFor($child);
                if ($key === 'core') {
                    $coreBytes += $bytes;
                    $coreFiles++;
                } else {
                    if (!isset($buckets[$key])) {
                        $buckets[$key] = ['bytes' => 0, 'files' => 0];
                    }
                    $buckets[$key]['bytes'] += $bytes;
                    $buckets[$key]['files']++;
                }
                if (++$count >= self::WALK_CAP) {
                    $approx = true;
                    break 2;
                }
            }
            closedir($dh);
        }
        return [
            'buckets'    => $buckets,
            'core_bytes' => $coreBytes,
            'core_files' => $coreFiles,
            'approx'     => $approx,
        ];
    }

    /**
     * Turn walk buckets into picker rows: known groups first (media,
     * plugins, themes, translations, mu-plugins), then any other real
     * wp-content child by name, then loose wp-content root files.
     *
     * @param array<string,array{bytes:int,files:int}> $buckets
     * @return list<array<string,mixed>>
     */
    private static function fileGroups(array $buckets): array
    {
        /** @var array<int,array<string,mixed>> $known */
        $known = [];
        /** @var list<array<string,mixed>> $other */
        $other = [];
        /** @var array<string,mixed>|null $rootFiles */
        $rootFiles = null;
        foreach ($buckets as $path => $bucket) {
            if ($path === 'wp-content') {
                $rootFiles = [
                    'path'  => $path,
                    'label' => __('Other files (wp-content root)', 'mudrava-migration-backup'),
                    'bytes' => $bucket['bytes'],
                    'files' => $bucket['files'],
                ];
                continue;
            }
            $base = substr($path, strlen('wp-content/'));
            $row = [
                'path'  => $path,
                'label' => self::KNOWN_LABELS[$base] ?? $base,
                'bytes' => $bucket['bytes'],
                'files' => $bucket['files'],
            ];
            $pos = array_search($base, self::KNOWN_ORDER, true);
            if ($pos === false) {
                $other[] = $row;
            } else {
                $known[$pos] = $row;
            }
        }
        ksort($known);
        usort($other, static function (array $a, array $b): int {
            return strcmp((string) $a['label'], (string) $b['label']);
        });
        $out = array_values($known);
        foreach ($other as $row) {
            $out[] = $row;
        }
        if ($rootFiles !== null) {
            $out[] = $rootFiles;
        }
        return $out;
    }
}
