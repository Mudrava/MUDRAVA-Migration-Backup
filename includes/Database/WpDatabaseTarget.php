<?php

/**
 * DatabaseTarget over $wpdb. DROP+CREATE per table (idempotent under
 * replay), batched INSERTs with prepared placeholders. NULL cells become
 * literal NULL - never empty strings.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Database;

use Mudrava\Migration\Migration\DatabaseTarget;
use Mudrava\Migration\Rollback\ImportJournal;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are reduced to safe codes at the REST boundary.
// phpcs:disable WordPress.DB.PreparedSQL -- Dynamic SQL identifiers pass the strict ident() allowlist; data values use wpdb::prepare().
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL identifiers are allowlisted and data values are prepared.

final class WpDatabaseTarget implements DatabaseTarget
{
    /** Max rows per INSERT statement - keeps packets well under max_allowed_packet. */
    private const INSERT_CHUNK_ROWS = 50;

    /** @var \wpdb */
    private $wpdb;

    /** @var ImportJournal|null */
    private $journal;

    public function __construct(?\wpdb $wpdb = null, ?ImportJournal $journal = null)
    {
        $this->wpdb = $wpdb ?: $GLOBALS['wpdb'];
        $this->journal = $journal;
    }

    public function createTable(string $schemaSql): void
    {
        $table = self::validateSchema($schemaSql);
        if ($this->journal !== null) {
            $this->journal->recordTable($table);
        }
        $this->wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->wpdb->query('DROP TABLE IF EXISTS `' . $table . '`');
        $result = $this->wpdb->query($schemaSql);
        $this->wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
        if ($result === false) {
            throw new \RuntimeException('MUDRAVA_DB_ERROR: create failed for ' . $table . ': ' . $this->wpdb->last_error);
        }
    }

    /** Reject multi-statement or non-CREATE schema before import writes. */
    public static function validateSchema(string $schemaSql): string
    {
        if (
            strpos($schemaSql, "\0") !== false
            || self::hasStatementSeparator($schemaSql)
            || !preg_match('/^CREATE TABLE\s+`?([A-Za-z0-9_]+)`?\s*\(/i', $schemaSql, $m)
        ) {
            throw new \RuntimeException('MUDRAVA_DB_ERROR: cannot parse table name from schema');
        }
        return self::ident($m[1]);
    }

    /** Semicolons in quoted column defaults/comments are data, not SQL separators. */
    private static function hasStatementSeparator(string $sql): bool
    {
        $quote = '';
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($quote === '') {
                if ($char === ';') {
                    return true;
                }
                if ($char === "'" || $char === '"' || $char === '`') {
                    $quote = $char;
                }
                continue;
            }
            if ($char === '\\' && $quote !== '`') {
                $i++;
                continue;
            }
            if ($char === $quote) {
                if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                    $i++;
                } else {
                    $quote = '';
                }
            }
        }
        return $quote !== '';
    }

    public function insertBatch(string $table, array $columns, array $rows): void
    {
        if ($rows === [] || $columns === []) {
            return;
        }
        foreach (array_chunk($rows, self::INSERT_CHUNK_ROWS) as $chunk) {
            $this->insertChunk($table, $columns, $chunk);
        }
    }

    /**
     * @param list<string> $columns
     * @param list<list<?string>> $rows
     */
    private function insertChunk(string $table, array $columns, array $rows): void
    {
        $cols = '`' . implode('`, `', array_map([self::class, 'ident'], $columns)) . '`';
        $tuples = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $cell) {
                if ($cell === null) {
                    $cells[] = 'NULL';
                } else {
                    // prepare('%s', …) performs charset-correct escaping,
                    // binary-safe for BLOB payloads.
                    $cells[] = $this->wpdb->prepare('%s', $cell);
                }
            }
            $tuples[] = '(' . implode(', ', $cells) . ')';
        }
        $sql = 'INSERT INTO `' . self::ident($table) . '` (' . $cols . ') VALUES ' . implode(', ', $tuples);
        $result = $this->wpdb->query($sql);
        if ($result === false) {
            throw new \RuntimeException('MUDRAVA_DB_ERROR: insert failed into ' . $table . ': ' . $this->wpdb->last_error);
        }
    }

    /**
     * Strict identifier allow-list. WordPress table/column identifiers are
     * [A-Za-z0-9_]; anything else is rejected rather than escaped.
     */
    private static function ident(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new \RuntimeException('MUDRAVA_DB_ERROR: unsafe identifier ' . $name);
        }
        return $name;
    }

    public function tableExists(string $table): bool
    {
        $found = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            )
        );
        return $found !== null && $found !== false;
    }
}
