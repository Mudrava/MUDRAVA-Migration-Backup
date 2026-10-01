<?php

/**
 * Contracts for the live wpdb source and target adapters, including bounded
 * inserts, binary/null values and strict SQL identifier validation.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Database\WpDatabaseSource;
use Mudrava\Migration\Database\WpDatabaseTarget;
use PHPUnit\Framework\TestCase;

final class WpDatabaseAdaptersTest extends TestCase
{
    public function testSourceReadsSortedSchemaMetadataAndBinaryRows(): void
    {
        $wpdb = new \wpdb();
        $columnReads = 0;
        $wpdb->colCallback = static function (string $sql): array {
            return ['wp_zeta', 'wp_skip', 'wp_alpha'];
        };
        $wpdb->resultsCallback = static function (string $sql, $format) use (&$columnReads): array {
            if (strpos($sql, 'SHOW COLUMNS') === 0) {
                $columnReads++;
                return [
                    ['Field' => 'id', 'Key' => 'PRI'],
                    ['Field' => 'payload', 'Key' => ''],
                ];
            }
            if (strpos($sql, 'SELECT *') === 0) {
                return [[1, "bin\0ary", null]];
            }
            if (strpos($sql, 'AS mudrava_row_bytes') !== false) {
                return [[8]];
            }
            return [];
        };
        $wpdb->rowCallback = static function (string $sql, $format) {
            if (strpos($sql, 'SHOW CREATE TABLE') === 0) {
                return ['wp_alpha', 'CREATE TABLE `wp_alpha` (`id` bigint PRIMARY KEY)'];
            }
            return ['ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci'];
        };

        $source = new WpDatabaseSource($wpdb, ['wp_skip']);
        $this->assertSame(['wp_alpha', 'wp_zeta'], $source->tables());
        $this->assertSame(['id', 'payload'], $source->columns('wp_alpha'));
        $this->assertSame(['id', 'payload'], $source->columns('wp_alpha'));
        $this->assertSame(1, $columnReads, 'column metadata should be cached');
        $this->assertStringStartsWith('CREATE TABLE', $source->schema('wp_alpha'));
        $this->assertSame(
            ['engine' => 'InnoDB', 'charset' => 'utf8mb4_unicode_ci'],
            $source->tableMeta('wp_alpha')
        );
        $this->assertSame([['1', "bin\0ary", null]], $source->rows('wp_alpha', 'id', null, 50));
        $this->assertSame([['1', "bin\0ary", null]], $source->rows('wp_alpha', null, '20', 10));
        $this->assertSame('id', $source->primaryKey('wp_alpha'));
    }

    public function testSourcePreservesTextAndUnsignedBigintCursors(): void
    {
        $wpdb = new \wpdb();
        $queries = [];
        $wpdb->resultsCallback = static function (string $sql) use (&$queries): array {
            if (strpos($sql, 'SHOW COLUMNS') === 0) {
                return [['Field' => 'id', 'Key' => 'PRI']];
            }
            $queries[] = $sql;
            return [];
        };
        $source = new WpDatabaseSource($wpdb);
        $this->assertSame('id', $source->primaryKey('wp_keys'));
        $source->rows('wp_keys', 'id', null, 500);
        $source->rows('wp_keys', 'id', '18446744073709551610', 500);
        $source->rows('wp_keys', 'id', 'a0000000-0000-0000-0000-000000000001', 500);
        $this->assertStringContainsString('AS mudrava_row_bytes FROM `wp_keys`', $queries[0]);
        $this->assertStringContainsString("`id` > '18446744073709551610'", $queries[1]);
        $this->assertStringContainsString("`id` > 'a0000000-0000-0000-0000-000000000001'", $queries[2]);
    }

    public function testCompositePrimaryKeyUsesOrderedOffsetPagination(): void
    {
        $wpdb = new \wpdb();
        $queries = [];
        $wpdb->resultsCallback = static function (string $sql) use (&$queries): array {
            if (strpos($sql, 'SHOW COLUMNS') === 0) {
                return [
                    ['Field' => 'language', 'Key' => 'PRI'],
                    ['Field' => 'term_id', 'Key' => 'PRI'],
                    ['Field' => 'value', 'Key' => ''],
                ];
            }
            $queries[] = $sql;
            return [];
        };
        $source = new WpDatabaseSource($wpdb);
        $this->assertNull($source->primaryKey('wp_translations'));
        $source->rows('wp_translations', null, '500', 500);
        $this->assertSame(
            'SELECT (COALESCE(OCTET_LENGTH(`language`), 0) + COALESCE(OCTET_LENGTH(`term_id`), 0) + COALESCE(OCTET_LENGTH(`value`), 0)) AS mudrava_row_bytes FROM `wp_translations` ORDER BY `language` ASC, `term_id` ASC LIMIT 500, 500',
            $queries[0]
        );
    }

    public function testSourceDoesNotTreatFailedRowQueryAsEmptyTable(): void
    {
        $wpdb = new \wpdb();
        $wpdb->resultsCallback = static function () use ($wpdb): array {
            $wpdb->last_error = 'connection interrupted';
            return [];
        };
        $source = new WpDatabaseSource($wpdb);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MUDRAVA_DB_QUERY_FAILED');
        $source->rows('wp_posts', 'ID', null, 500);
    }

    public function testSourceBoundsPayloadQueryUsingMeasuredRowLengths(): void
    {
        $wpdb = new \wpdb();
        $queries = [];
        $wpdb->resultsCallback = static function (string $sql) use (&$queries): array {
            if (strpos($sql, 'SHOW COLUMNS') === 0) {
                return [
                    ['Field' => 'id', 'Key' => 'PRI'],
                    ['Field' => 'payload', 'Key' => ''],
                ];
            }
            $queries[] = $sql;
            if (strpos($sql, 'AS mudrava_row_bytes') !== false) {
                return [[2097152], [2097152], [2097152]];
            }
            return [['1', 'payload']];
        };
        $source = new WpDatabaseSource($wpdb);

        $this->assertSame([['1', 'payload']], $source->rows('wp_large', 'id', null, 500));
        $this->assertCount(2, $queries);
        $this->assertStringContainsString('OCTET_LENGTH(`payload`)', $queries[0]);
        $this->assertSame('SELECT * FROM `wp_large` ORDER BY `id` ASC LIMIT 1', $queries[1]);
    }

    public function testSourceRejectsOversizedRowBeforeFetchingPayload(): void
    {
        $wpdb = new \wpdb();
        $payloadFetched = false;
        $wpdb->resultsCallback = static function (string $sql) use (&$payloadFetched): array {
            if (strpos($sql, 'SHOW COLUMNS') === 0) {
                return [['Field' => 'payload', 'Key' => 'PRI']];
            }
            if (strpos($sql, 'AS mudrava_row_bytes') !== false) {
                return [[268435456]];
            }
            $payloadFetched = true;
            return [];
        };
        $source = new WpDatabaseSource($wpdb);

        try {
            $source->rows('wp_large', 'payload', null, 500);
            $this->fail('oversized row must be rejected before SELECT *');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('MUDRAVA_DB_ROW_TOO_LARGE', $error->getMessage());
            $this->assertFalse($payloadFetched);
        }
    }

    public function testSourceDoesNotCapSingleRowAtBatchTarget(): void
    {
        $wpdb = new \wpdb();
        $queries = [];
        $wpdb->resultsCallback = static function (string $sql) use (&$queries): array {
            if (strpos($sql, 'SHOW COLUMNS') === 0) {
                return [['Field' => 'payload', 'Key' => 'PRI']];
            }
            $queries[] = $sql;
            return strpos($sql, 'AS mudrava_row_bytes') !== false ? [[8388608]] : [['data']];
        };
        $source = new WpDatabaseSource($wpdb);

        $this->assertSame([['data']], $source->rows('wp_large', 'payload', null, 500));
        $this->assertSame('SELECT * FROM `wp_large` ORDER BY `payload` ASC LIMIT 1', $queries[1]);
    }

    public function testSourceReportsInsufficientMemoryBeforeFetchingPayload(): void
    {
        $wpdb = new \wpdb();
        $payloadFetched = false;
        $wpdb->resultsCallback = static function (string $sql) use (&$payloadFetched): array {
            if (strpos($sql, 'SHOW COLUMNS') === 0) {
                return [['Field' => 'payload', 'Key' => 'PRI']];
            }
            if (strpos($sql, 'AS mudrava_row_bytes') !== false) {
                return [[20971520]];
            }
            $payloadFetched = true;
            return [];
        };
        $source = new WpDatabaseSource($wpdb);
        $oldLimit = ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage(true) + 33554432));
        try {
            $source->rows('wp_large', 'payload', null, 500);
            $this->fail('memory-limited row must be rejected before SELECT *');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('MUDRAVA_MEMORY_LIMIT', $error->getMessage());
            $this->assertFalse($payloadFetched);
        } finally {
            ini_set('memory_limit', (string) $oldLimit);
        }
    }

    public function testSourceAccountsForManySmallColumnsInMemoryBudget(): void
    {
        $wpdb = new \wpdb();
        $payloadLimit = 0;
        $wpdb->resultsCallback = static function (string $sql) use (&$payloadLimit): array {
            if (strpos($sql, 'SHOW COLUMNS') === 0) {
                $columns = [];
                for ($i = 0; $i < 1000; $i++) {
                    $columns[] = ['Field' => 'c' . $i, 'Key' => $i === 0 ? 'PRI' : ''];
                }
                return $columns;
            }
            if (strpos($sql, 'AS mudrava_row_bytes') !== false) {
                return array_fill(0, 500, [0]);
            }
            preg_match('/LIMIT ([0-9]+)$/', $sql, $matches);
            $payloadLimit = (int) ($matches[1] ?? 0);
            return array_fill(0, $payloadLimit, [null]);
        };
        $source = new WpDatabaseSource($wpdb);
        $oldLimit = ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage(true) + 33554432));
        try {
            $rows = $source->rows('wp_wide', 'c0', null, 500);
            $this->assertCount($payloadLimit, $rows);
            $this->assertGreaterThan(0, $payloadLimit);
            $this->assertLessThan(500, $payloadLimit);
        } finally {
            ini_set('memory_limit', (string) $oldLimit);
        }
    }

    public function testSourceDoesNotTreatFailedSchemaReadAsEmptyMetadata(): void
    {
        $wpdb = new \wpdb();
        $wpdb->resultsCallback = static function () use ($wpdb): array {
            $wpdb->last_error = 'connection interrupted';
            return [];
        };
        $source = new WpDatabaseSource($wpdb);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MUDRAVA_DB_QUERY_FAILED');
        $source->columns('wp_posts');
    }

    /**
     * BUG-04: the offset anchor must be re-read in exactly the order
     * rows() pages with, so a sliding PK-less window is detectable.
     */
    public function testRowAtOffsetUsesTheSameOrderingAsOffsetPaging(): void
    {
        $wpdb = new \wpdb();
        $anchorQuery = null;
        $wpdb->resultsCallback = static function (string $sql): array {
            if (strpos($sql, 'SHOW COLUMNS') === 0) {
                return [
                    ['Field' => 'language', 'Key' => 'PRI'],
                    ['Field' => 'term_id', 'Key' => 'PRI'],
                    ['Field' => 'value', 'Key' => ''],
                ];
            }
            return [];
        };
        $wpdb->rowCallback = static function (string $sql) use (&$anchorQuery) {
            if (strpos($sql, 'LIMIT 499, 1') !== false) {
                $anchorQuery = $sql;
                return ['en', '7', 'anchor'];
            }
            return null;
        };
        $source = new WpDatabaseSource($wpdb);

        $row = $source->rowAtOffset('wp_translations', 499);
        $this->assertSame(['en', '7', 'anchor'], $row);
        $this->assertStringContainsString('ORDER BY `language` ASC, `term_id` ASC LIMIT 499, 1', (string) $anchorQuery);
    }

    public function testRowAtOffsetReturnsNullPastTheEnd(): void
    {
        $wpdb = new \wpdb();
        $wpdb->rowCallback = static function (): ?array {
            return null;
        };
        $source = new WpDatabaseSource($wpdb);
        $this->assertNull($source->rowAtOffset('wp_empty', 900));
    }

    public function testRowAtOffsetDoesNotTreatFailedQueryAsMissingRow(): void
    {
        $wpdb = new \wpdb();
        $wpdb->rowCallback = static function () use ($wpdb): ?array {
            $wpdb->last_error = 'connection interrupted';
            return null;
        };
        $source = new WpDatabaseSource($wpdb);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MUDRAVA_DB_QUERY_FAILED');
        $source->rowAtOffset('wp_posts', 500);
    }
    public function testSourceRejectsUnsafeIdentifiersBeforeQuery(): void
    {
        $source = new WpDatabaseSource(new \wpdb());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsafe identifier');
        $source->columns('wp_posts; DROP TABLE wp_users');
    }

    public function testTargetCreatesTableAndRestoresForeignKeyChecks(): void
    {
        $wpdb = new \wpdb();
        $target = new WpDatabaseTarget($wpdb);
        $schema = 'CREATE TABLE `wp_demo` (`id` bigint NOT NULL) ENGINE=InnoDB';

        $target->createTable($schema);

        $this->assertSame([
            'SET FOREIGN_KEY_CHECKS = 0',
            'DROP TABLE IF EXISTS `wp_demo`',
            $schema,
            'SET FOREIGN_KEY_CHECKS = 1',
        ], $wpdb->queries);
    }

    public function testTargetReportsCreateFailureAfterRestoringChecks(): void
    {
        $wpdb = new \wpdb();
        $wpdb->last_error = 'permission denied';
        $wpdb->queryCallback = static function (string $sql) {
            return strpos($sql, 'CREATE TABLE') === 0 ? false : 1;
        };
        $target = new WpDatabaseTarget($wpdb);

        try {
            $target->createTable('CREATE TABLE `wp_demo` (`id` bigint NOT NULL)');
            $this->fail('failed CREATE must throw');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('create failed for wp_demo', $error->getMessage());
            $this->assertSame('SET FOREIGN_KEY_CHECKS = 1', end($wpdb->queries));
        }
    }

    public function testTargetBatchesRowsAndPreservesNullAndBinaryValues(): void
    {
        $wpdb = new \wpdb();
        $target = new WpDatabaseTarget($wpdb);
        $rows = [];
        for ($i = 0; $i < 51; $i++) {
            $rows[] = [(string) $i, $i === 0 ? null : "value\0" . $i];
        }

        $target->insertBatch('wp_demo', ['id', 'payload'], $rows);

        $this->assertCount(2, $wpdb->queries);
        $this->assertStringContainsString('INSERT INTO `wp_demo` (`id`, `payload`) VALUES', $wpdb->queries[0]);
        $this->assertStringContainsString("('0', NULL)", $wpdb->queries[0]);
        $this->assertStringContainsString("value\\0", $wpdb->queries[0]);
        $this->assertStringContainsString("('50', 'value\\050')", $wpdb->queries[1]);

        $target->insertBatch('wp_demo', ['id'], []);
        $target->insertBatch('wp_demo', [], [['1']]);
        $this->assertCount(2, $wpdb->queries);
    }

    public function testTargetRejectsUnsafeColumnAndReportsInsertFailure(): void
    {
        $target = new WpDatabaseTarget(new \wpdb());
        try {
            $target->insertBatch('wp_demo', ['id`) VALUES (1); --'], [['x']]);
            $this->fail('unsafe column must throw');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('unsafe identifier', $error->getMessage());
        }

        $wpdb = new \wpdb();
        $wpdb->last_error = 'packet rejected';
        $wpdb->queryCallback = static function (string $sql) {
            return false;
        };
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('insert failed into wp_demo');
        (new WpDatabaseTarget($wpdb))->insertBatch('wp_demo', ['id'], [['1']]);
    }

    public function testTableExistsUsesPreparedInformationSchemaLookup(): void
    {
        $wpdb = new \wpdb();
        $wpdb->varCallback = static function (string $sql) {
            return strpos($sql, "TABLE_NAME = 'wp_demo'") !== false ? 'wp_demo' : null;
        };
        $target = new WpDatabaseTarget($wpdb);

        $this->assertTrue($target->tableExists('wp_demo'));
        $this->assertFalse($target->tableExists('wp_missing'));
    }
}
