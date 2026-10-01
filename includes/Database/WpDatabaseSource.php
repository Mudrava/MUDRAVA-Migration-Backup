<?php

/**
 * DatabaseSource over $wpdb. Reads in bounded batches with a primary-key
 * cursor (or LIMIT/OFFSET fallback for PK-less tables). Values are returned
 * as raw positional strings - binary-safe, no object mapping.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Database;

use Mudrava\Migration\Archive\FrameWriter;
use Mudrava\Migration\Migration\DatabaseSource;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are reduced to safe codes at the REST boundary.
// phpcs:disable WordPress.DB.PreparedSQL -- Dynamic SQL identifiers pass the strict ident() allowlist; data values use wpdb::prepare().
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL identifiers are allowlisted and data values are prepared.

final class WpDatabaseSource implements DatabaseSource
{
    private const BATCH_BYTES = 4194304;
    private const MEMORY_RESERVE_BYTES = 16777216;

    /** @var \wpdb */
    private $wpdb;

    /** @var list<string> */
    private $tables;

    /** @var array<string,list<string>> */
    private $columnCache = [];

    /** @var array<string,list<array<string,mixed>>> */
    private $columnMetadataCache = [];

    /**
     * @param list<string> $exclude table names to skip (e.g. mudrava temp)
     */
    public function __construct(?\wpdb $wpdb = null, array $exclude = [])
    {
        $this->wpdb = $wpdb ?: $GLOBALS['wpdb'];
        $raw = $this->wpdb->get_col('SHOW TABLES');
        $this->assertQuerySucceeded();
        $this->tables = array_values(array_diff((array) $raw, $exclude));
        sort($this->tables);
    }

    public function tables(): array
    {
        return $this->tables;
    }

    public function columns(string $table): array
    {
        if (!isset($this->columnCache[$table])) {
            $cols = [];
            foreach ($this->columnMetadata($table) as $row) {
                $cols[] = (string) $row['Field'];
            }
            $this->columnCache[$table] = $cols;
        }
        return $this->columnCache[$table];
    }

    public function schema(string $table): string
    {
        $row = $this->wpdb->get_row('SHOW CREATE TABLE `' . self::ident($table) . '`', ARRAY_N);
        $this->assertQuerySucceeded();
        if ($row === null) {
            throw new \RuntimeException('MUDRAVA_DB_QUERY_FAILED: no schema for ' . $table);
        }
        return (string) $row[1];
    }

    /**
     * @return array{engine:string,charset:string}
     */
    public function tableMeta(string $table): array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            ),
            ARRAY_A
        );
        $this->assertQuerySucceeded();
        return [
            'engine'  => (string) ($row['ENGINE'] ?? ''),
            'charset' => (string) ($row['TABLE_COLLATION'] ?? ''),
        ];
    }

    public function rows(string $table, ?string $pkColumn, ?string $afterPk, int $limit): array
    {
        $safeTable = self::ident($table);
        if ($pkColumn !== null) {
            $safePk = self::ident($pkColumn);
            if ($afterPk === null) {
                // No lower bound: include zero, negative and string keys.
                $sql = "SELECT * FROM `{$safeTable}` ORDER BY `{$safePk}` ASC LIMIT %d";
                /** @var literal-string $sql */
                $query = $this->wpdb->prepare($sql, $limit);
            } else {
                // Keep the exact primary-key bytes; %d truncates unsigned
                // BIGINT values and cannot represent UUID/text keys.
                $sql = "SELECT * FROM `{$safeTable}` WHERE `{$safePk}` > %s ORDER BY `{$safePk}` ASC LIMIT %d";
                /** @var literal-string $sql */
                $query = $this->wpdb->prepare($sql, $afterPk, $limit);
            }
        } else {
            // Composite keys need deterministic ordering for offset paging.
            $primaryColumns = $this->primaryColumns($table);
            $orderBy = $primaryColumns === [] ? '' : ' ORDER BY ' . implode(', ', array_map(
                static function (string $column): string {
                    return '`' . self::ident($column) . '` ASC';
                },
                $primaryColumns
            ));
            $sql = "SELECT * FROM `{$safeTable}`{$orderBy} LIMIT %d, %d";
            /** @var literal-string $sql */
            $query = $this->wpdb->prepare($sql, (int) $afterPk, $limit);
        }
        if (!is_string($query)) {
            throw new \RuntimeException('MUDRAVA_DB_QUERY_FAILED: source query could not be prepared');
        }
        // Ask MySQL for byte lengths before fetching payloads. $wpdb buffers
        // every get_results() row, so LIMIT 500 alone is not a memory bound
        // when a table has BLOB/TEXT values. The length result is tiny and
        // lets us fetch only rows that fit the current request's headroom.
        $columns = $this->columns($table);
        $lengths = [];
        foreach ($columns as $column) {
            $lengths[] = 'COALESCE(OCTET_LENGTH(`' . self::ident($column) . '`), 0)';
        }
        $lengthQuery = 'SELECT (' . implode(' + ', $lengths) . ') AS mudrava_row_bytes' . substr($query, 8);
        $sizeRows = $this->wpdb->get_results($lengthQuery, ARRAY_N);
        $this->assertQuerySucceeded();
        if (!is_array($sizeRows)) {
            throw new \RuntimeException('MUDRAVA_DB_QUERY_FAILED: source lengths unavailable');
        }
        if ($sizeRows === []) {
            return [];
        }

        $limitBytes = self::phpMemoryLimitBytes();
        $memoryBudget = $limitBytes === null
            ? null
            : max(0, $limitBytes - memory_get_usage(true) - self::MEMORY_RESERVE_BYTES);
        $selected = 0;
        $selectedBytes = 0;
        $selectedMemory = 0;
        foreach ($sizeRows as $sizeRow) {
            $cellBytes = (int) ($sizeRow[0] ?? -1);
            $rowBytes = $cellBytes + count($columns) * 5 + 6;
            // wpdb materializes row objects and the adapter builds positional
            // arrays. Account for both structures and several payload copies.
            $rowMemory = $cellBytes * 6 + count($columns) * 160 + 1024;
            if ($cellBytes < 0 || $rowBytes > FrameWriter::MAX_FRAME_BYTES) {
                throw new \RuntimeException('MUDRAVA_DB_ROW_TOO_LARGE: row exceeds archive frame');
            }
            if ($selected === 0 && $memoryBudget !== null && $rowMemory > $memoryBudget) {
                throw new \RuntimeException('MUDRAVA_MEMORY_LIMIT: database row exceeds PHP memory headroom');
            }
            if (
                $selected > 0
                && (
                    $selectedBytes + $rowBytes > self::BATCH_BYTES
                    || ($memoryBudget !== null && $selectedMemory + $rowMemory > $memoryBudget)
                )
            ) {
                break;
            }
            $selected++;
            $selectedBytes += $rowBytes;
            $selectedMemory += $rowMemory;
        }
        $boundedQuery = preg_replace('/[0-9]+$/', (string) $selected, $query);
        if (!is_string($boundedQuery)) {
            throw new \RuntimeException('MUDRAVA_DB_QUERY_FAILED: cannot bound source query');
        }
        $rows = $this->wpdb->get_results($boundedQuery, ARRAY_N);
        $this->assertQuerySucceeded();
        if (count((array) $rows) !== $selected) {
            throw new \RuntimeException('MUDRAVA_DB_QUERY_FAILED: source rows changed during batch');
        }
        $out = [];
        foreach ((array) $rows as $row) {
            $cells = [];
            foreach ($row as $cell) {
                $cells[] = $cell === null ? null : (string) $cell;
            }
            $out[] = $cells;
        }
        return $out;
    }

    /**
     * Re-read the row at one positional offset, in exactly the order
     * rows() pages offset cursors with (composite-key ORDER BY when the
     * table has one, plan order otherwise). This is the anchor the export
     * engine re-checks before every offset batch: LIMIT/OFFSET has no
     * stable identity of its own, so deletes or inserts before the window
     * would otherwise slide it and skip rows in silence.
     */
    public function rowAtOffset(string $table, int $offset): ?array
    {
        $safeTable = self::ident($table);
        $primaryColumns = $this->primaryColumns($table);
        $orderBy = $primaryColumns === [] ? '' : ' ORDER BY ' . implode(', ', array_map(
            static function (string $column): string {
                return '`' . self::ident($column) . '` ASC';
            },
            $primaryColumns
        ));
        $sql = "SELECT * FROM `{$safeTable}`{$orderBy} LIMIT %d, 1";
        /** @var literal-string $sql */
        $query = $this->wpdb->prepare($sql, max(0, $offset));
        if (!is_string($query)) {
            throw new \RuntimeException('MUDRAVA_DB_QUERY_FAILED: anchor query could not be prepared');
        }
        $row = $this->wpdb->get_row($query, ARRAY_N);
        $this->assertQuerySucceeded();
        if ($row === null) {
            return null;
        }
        $cells = [];
        foreach ($row as $cell) {
            $cells[] = $cell === null ? null : (string) $cell;
        }
        return $cells;
    }

    private static function phpMemoryLimitBytes(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '-1') {
            return null;
        }
        if (preg_match('/^([0-9]+)([KMG]?)$/i', $raw, $matches) !== 1) {
            return null;
        }
        $power = ['' => 0, 'K' => 1, 'M' => 2, 'G' => 3][strtoupper($matches[2])];
        $bytes = (int) $matches[1];
        for ($i = 0; $i < $power; $i++) {
            if ($bytes > intdiv(PHP_INT_MAX, 1024)) {
                return null;
            }
            $bytes *= 1024;
        }
        return $bytes;
    }

    public function primaryKey(string $table): ?string
    {
        $columns = $this->primaryColumns($table);
        return count($columns) === 1 ? $columns[0] : null;
    }

    /** @return list<string> */
    private function primaryColumns(string $table): array
    {
        $columns = [];
        foreach ($this->columnMetadata($table) as $row) {
            if (($row['Key'] ?? '') === 'PRI') {
                $columns[] = (string) $row['Field'];
            }
        }
        return $columns;
    }

    /** @return list<array<string,mixed>> */
    private function columnMetadata(string $table): array
    {
        if (!isset($this->columnMetadataCache[$table])) {
            $rows = $this->wpdb->get_results('SHOW COLUMNS FROM `' . self::ident($table) . '`', ARRAY_A);
            $this->assertQuerySucceeded();
            $this->columnMetadataCache[$table] = (array) $rows;
        }
        return $this->columnMetadataCache[$table];
    }

    private function assertQuerySucceeded(): void
    {
        // wpdb::get_results() returns an empty array for both an empty
        // result and a failed query; last_error distinguishes them.
        if ($this->wpdb->last_error !== '') {
            throw new \RuntimeException('MUDRAVA_DB_QUERY_FAILED: source query failed');
        }
    }

    /**
     * Strict identifier allow-list - table/column names come from the
     * database itself but are re-validated before interpolation.
     */
    private static function ident(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new \RuntimeException('MUDRAVA_DB_ERROR: unsafe identifier ' . $name);
        }
        return $name;
    }
}
