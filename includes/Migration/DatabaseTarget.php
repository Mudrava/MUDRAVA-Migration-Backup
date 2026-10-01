<?php

/**
 * Target for database restore. WordPress adapter executes real DDL/DML
 * through $wpdb with prepared statements.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Migration;

interface DatabaseTarget
{
    /** Drop existing table if present, then apply schema. */
    public function createTable(string $schemaSql): void;

    /**
     * Insert a batch of positional rows into the table.
     *
     * @param list<string>      $columns
     * @param list<list<?string>> $rows
     */
    public function insertBatch(string $table, array $columns, array $rows): void;

    public function tableExists(string $table): bool;
}
