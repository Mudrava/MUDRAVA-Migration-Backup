<?php
/**
 * DatabaseTarget that records every DDL/DML so tests can assert exact
 * restore semantics (idempotency, ordering, batch sizes).
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Support;

use Mudrava\Migration\Migration\DatabaseTarget;

final class RecordingDatabaseTarget implements DatabaseTarget
{
    /** @var list<string> */
    public $created = [];

    /** @var array<string,list<list<?string>>> */
    public $rows = [];

    /** @var array<string,list<string>> */
    public $columns = [];

    public function createTable(string $schemaSql): void
    {
        $this->created[] = $schemaSql;
        if (preg_match('/CREATE TABLE `([^`]+)`/', $schemaSql, $m)) {
            $table = $m[1];
            // DROP+CREATE semantics: replay must reset rows.
            $this->rows[$table] = [];
        }
    }

    public function insertBatch(string $table, array $columns, array $rows): void
    {
        $this->columns[$table] = $columns;
        foreach ($rows as $row) {
            $this->rows[$table][] = $row;
        }
    }

    public function tableExists(string $table): bool
    {
        return isset($this->rows[$table]);
    }
}
