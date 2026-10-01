<?php
/**
 * Offset-paging DatabaseSource whose rows can change between batches, for
 * regression tests around concurrent DELETE/INSERT on PK-less tables. The
 * row list is shared by reference with the test so a mutation lands exactly
 * between two bounded batches, the way it does on a live WordPress site.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Support;

use Mudrava\Migration\Migration\DatabaseSource;

final class MutableDatabaseSource implements DatabaseSource
{
    /** @var list<string> */
    private $columns;

    /** @var array{0:list<list<?string>>} container holding the shared reference */
    private $shared;

    /** @var callable|null invoked once, right after the first batch returns */
    private $onBatch;

    /** @var int */
    private $batches = 0;

    /** @var bool */
    private $fired = false;

    /**
     * @param list<string>        $columns
     * @param list<list<?string>> $rows     mutable table contents
     * @param callable|null       $onBatch  called after the first batch
     */
    public function __construct(array $columns, array &$rows, ?callable $onBatch = null)
    {
        $this->columns = $columns;
        // PHP has no reference properties; a single-slot array keeps the
        // caller's list alive so mutations between batches are visible.
        $this->shared = [&$rows];
        $this->onBatch = $onBatch;
    }

    public function tables(): array
    {
        return ['wp_mutable'];
    }

    public function columns(string $table): array
    {
        return $this->columns;
    }

    public function schema(string $table): string
    {
        $cols = [];
        foreach ($this->columns as $column) {
            $cols[] = '`' . $column . '` TEXT';
        }
        return 'CREATE TABLE `' . $table . '` (' . implode(', ', $cols) . ')';
    }

    /** @return array{engine:string,charset:string} */
    public function tableMeta(string $table): array
    {
        return ['engine' => 'InnoDB', 'charset' => 'utf8mb4'];
    }

    public function rows(string $table, ?string $pkColumn, ?string $afterPk, int $limit): array
    {
        // PK-less offset paging, exactly like WpDatabaseSource's LIMIT/OFFSET.
        $batch = array_slice($this->shared[0], (int) $afterPk, $limit);
        $this->batches++;
        if (!$this->fired && $this->onBatch !== null && $this->batches >= 1) {
            $this->fired = true;
            ($this->onBatch)($this->batches);
        }
        return $batch;
    }

    public function rowAtOffset(string $table, int $offset): ?array
    {
        return $this->shared[0][$offset] ?? null;
    }

    public function primaryKey(string $table): ?string
    {
        return null;
    }
}
