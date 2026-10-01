<?php

/**
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */


declare(strict_types=1);

namespace Mudrava\Migration\Tests\Contract;

use Mudrava\Migration\Archive\Checkpoint;
use Mudrava\Migration\Archive\FrameReader;
use Mudrava\Migration\Archive\FrameType;
use Mudrava\Migration\Archive\FrameWriter;
use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Compression\DeflateCodec;
use Mudrava\Migration\Database\RowCodec;
use Mudrava\Migration\Migration\Importer;
use Mudrava\Migration\Tests\Support\LocalFileTarget;
use Mudrava\Migration\Tests\Support\RecordingDatabaseTarget;
use PHPUnit\Framework\TestCase;

final class PrefixMigrationTest extends TestCase
{
    public function testCustomPrefixMapsTablesAndWordPressKeysAcrossTicks(): void
    {
        $path = $GLOBALS['MUDRAVA_TEST_TMP'] . '/prefix-' . uniqid() . '.mudrava';
        $out = fopen($path, 'wb');
        self::assertNotFalse($out);
        $header = new Header(Header::CONTAINER_FORMAT, 0, random_bytes(16), 'test', 0, 0, 0,
            str_repeat("\0", 16), str_repeat("\0", 8));
        $writer = new FrameWriter($out, $header, new DeflateCodec(), null);
        $writer->writeJson(FrameType::SITE_METADATA, ['site_url' => 'https://source.test', 'db_prefix' => 'src_']);
        $this->writeTable($writer, 'src_options', ['option_name', 'option_value'], [
            ['src_user_roles', 'roles'],
            ['home', 'https://source.test'],
        ]);
        $this->writeTable($writer, 'src_usermeta', ['meta_key', 'meta_value'], [
            ['src_capabilities', 'a:1:{s:13:"administrator";b:1;}'],
        ]);
        $this->writeTable($writer, 'external_metrics', ['name', 'value'], [['x', '1']]);
        $writer->finalize(['row_count' => 4, 'file_count' => 0]);
        fclose($out);

        $target = new RecordingDatabaseTarget();
        $checkpoint = null;
        $ticks = 0;
        do {
            $in = fopen($path, 'rb');
            self::assertNotFalse($in);
            $importer = new Importer(
                new FrameReader($in, new DeflateCodec()),
                $target,
                new LocalFileTarget($GLOBALS['MUDRAVA_TEST_TMP'] . '/prefix-dest-' . getmypid()),
                ['target' => 'https://target.test', 'target_prefix' => 'dst_'],
                $checkpoint
            );
            $state = $importer->step(microtime(true) - 1);
            $checkpoint = $state === Importer::STATE_DONE ? null : Checkpoint::fromArray($importer->checkpointData());
            fclose($in);
            $ticks++;
            self::assertLessThan(20, $ticks);
        } while ($state !== Importer::STATE_DONE);

        self::assertGreaterThan(1, $ticks);
        self::assertSame(['dst_options', 'dst_usermeta', 'external_metrics'], array_keys($target->rows));
        self::assertSame('dst_user_roles', $target->rows['dst_options'][0][0]);
        self::assertSame('https://target.test', $target->rows['dst_options'][1][1]);
        self::assertSame('dst_capabilities', $target->rows['dst_usermeta'][0][0]);
        self::assertSame('a:1:{s:13:"administrator";b:1;}', $target->rows['dst_usermeta'][0][1]);
        self::assertSame([['x', '1']], $target->rows['external_metrics']);
    }

    /** @param list<string> $columns @param list<list<?string>> $rows */
    private function writeTable(FrameWriter $writer, string $table, array $columns, array $rows): void
    {
        $writer->writeJson(FrameType::DB_TABLE_BEGIN, ['table' => $table, 'columns' => $columns]);
        $fields = [];
        foreach ($columns as $column) {
            $fields[] = '`' . $column . '` text';
        }
        $writer->writeFrame(FrameType::DB_SCHEMA, 'CREATE TABLE `' . $table . '` (' . implode(', ', $fields) . ')');
        $writer->writeFrame(FrameType::DB_ROWS, RowCodec::encode($rows));
        $writer->writeJson(FrameType::DB_TABLE_END, ['table' => $table]);
    }
}
