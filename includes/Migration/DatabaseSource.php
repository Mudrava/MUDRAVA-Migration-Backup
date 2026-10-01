<?php

/**
 * Source of database content for export. WordPress adapter implements this
 * over $wpdb; tests implement it over SQLite/arrays. Keeping the engine
 * WordPress-free makes the core deterministically testable.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Migration;

interface DatabaseSource
{
    /** @return list<string> table names to export (already filtered by exclusions) */
    public function tables(): array;

    /** @return list<string> column names, positional */
    public function columns(string $table): array;

    /** @return string SHOW CREATE TABLE output */
    public function schema(string $table): string;

    /**
     * @return array{engine:string,charset:string} engine/charset metadata for DB_TABLE_BEGIN
     */
    public function tableMeta(string $table): array;

    /**
     * Fetch the next batch after a lossless primary-key cursor. A null
     * cursor means the first batch; for tables without a single primary key,
     * the cursor is a decimal row offset.
     *
     * @return list<list<?string>> rows of positional cells; empty = done
     */
    public function rows(string $table, ?string $pkColumn, ?string $afterPk, int $limit): array;

    /**
     * The exact row sitting at a positional offset, in the same order
     * rows() pages offset cursors with. The export engine re-reads the
     * last archived row through this method before every offset batch:
     * without a primary key, deletes or inserts before the window slide
     * LIMIT/OFFSET silently, so the anchor is the only durable proof that
     * the cursor still points where the archive says it does.
     *
     * @return list<?string>|null null when the offset is past the end
     */
    public function rowAtOffset(string $table, int $offset): ?array;

    public function primaryKey(string $table): ?string;
}
