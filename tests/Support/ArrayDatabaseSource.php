<?php

/**
 * In-memory DatabaseSource for engine tests. Deterministic, tiny, and
 * intentionally strict: reading past the end must return [] not warnings.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Support;

use Mudrava\Migration\Migration\DatabaseSource;

final class ArrayDatabaseSource implements DatabaseSource
{
    /** @var array<string,array{columns:list<string>,pk:?string,rows:list<list<?string>>,types?:array<string,string>}> */
    private $tables;

    /**
     * @param array<string,array{columns:list<string>,pk?:?string,rows:list<list<?string>>,types?:array<string,string>}> $tables
     */
    public function __construct(array $tables)
    {
        $this->tables = $tables;
    }

    public function tables(): array
    {
        return array_keys($this->tables);
    }

    public function columns(string $table): array
    {
        return $this->tables[$table]['columns'];
    }

    public function schema(string $table): string
    {
        $cols = [];
        foreach ($this->tables[$table]['columns'] as $c) {
            $cols[] = '`' . $c . '` ' . ($this->tables[$table]['types'][$c] ?? 'TEXT');
        }
        return 'CREATE TABLE `' . $table . '` (' . implode(', ', $cols) . ')';
    }

    public function tableMeta(string $table): array
    {
        return ['engine' => 'InnoDB', 'charset' => 'utf8mb4'];
    }

    public function rows(string $table, ?string $pkColumn, ?string $afterPk, int $limit): array
    {
        $def = $this->tables[$table];
        $out = [];
        if ($pkColumn !== null) {
            $idx = (int) array_search($pkColumn, $def['columns'], true);
            foreach ($def['rows'] as $row) {
                if ($afterPk === null || self::compareKeys((string) $row[$idx], $afterPk) > 0) {
                    $out[] = $row;
                    if (count($out) >= $limit) {
                        break;
                    }
                }
            }
            return $out;
        }
        // Offset cursor.
        return array_slice($def['rows'], (int) $afterPk, $limit);
    }

    public function rowAtOffset(string $table, int $offset): ?array
    {
        $rows = $this->tables[$table]['rows'];
        return $rows[$offset] ?? null;
    }

    private static function compareKeys(string $left, string $right): int
    {
        if (ctype_digit($left) && ctype_digit($right)) {
            return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
        }
        return strcmp($left, $right);
    }

    public function primaryKey(string $table): ?string
    {
        return $this->tables[$table]['pk'] ?? null;
    }
}
