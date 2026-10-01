<?php

/**
 * Database query batches are divided into valid archive frames.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Contract;

use Mudrava\Migration\Archive\FrameReader;
use Mudrava\Migration\Archive\FrameType;
use Mudrava\Migration\Archive\FrameWriter;
use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Compression\DeflateCodec;
use Mudrava\Migration\Database\RowCodec;
use Mudrava\Migration\Migration\Exporter;
use Mudrava\Migration\Tests\Support\ArrayDatabaseSource;
use Mudrava\Migration\Tests\Support\ArrayFileInventory;
use PHPUnit\Framework\TestCase;

final class DatabaseBatchFrameTest extends TestCase
{
    /** @dataProvider cursorProvider */
    public function testLargeQueryBatchIsSplitAcrossRowFrames(bool $withPrimaryKey): void
    {
        $blob = random_bytes(1048576);
        $rows = [];
        for ($i = 1; $i <= 8; $i++) {
            $rows[] = [(string) $i, $blob];
        }
        $source = new ArrayDatabaseSource([
            'wp_stream' => [
                'columns' => ['id', 'payload'],
                'pk' => $withPrimaryKey ? 'id' : null,
                'rows' => $rows,
            ],
        ]);

        $path = $GLOBALS['MUDRAVA_TEST_TMP'] . '/db-batch-' . uniqid() . '.mudrava';
        $out = fopen($path, 'wb');
        $header = new Header(1, 0, random_bytes(16), '1.0.0', 0, 0, 0, random_bytes(16), random_bytes(8));
        $writer = new FrameWriter($out, $header, new DeflateCodec(), null);
        $exporter = new Exporter($writer, $source, new ArrayFileInventory([]), ['site_url' => 'https://old.test']);
        $exporter->writeSiteMetadata();
        for ($i = 0; $i < 8 && $exporter->step() !== Exporter::STATE_DONE; $i++) {
            // One table begin, one bounded query batch, table end and files end.
        }
        $this->assertSame(Exporter::STATE_DONE, $exporter->state());
        $exporter->finalize();
        fclose($out);

        $in = fopen($path, 'rb');
        $reader = new FrameReader($in, new DeflateCodec());
        $batchCount = 0;
        $rowCount = 0;
        while (($frame = $reader->next()) !== null) {
            if ($frame->type !== FrameType::DB_ROWS) {
                continue;
            }
            $batchCount++;
            $decoded = RowCodec::decode($frame->payload);
            $this->assertLessThan(Exporter::DB_FRAME_TARGET_BYTES + 1, strlen($frame->payload));
            foreach ($decoded as $row) {
                $rowCount++;
                $this->assertSame((string) $rowCount, $row[0]);
                $this->assertSame(hash('sha256', $blob), hash('sha256', (string) $row[1]));
            }
        }
        fclose($in);
        unlink($path);

        $this->assertGreaterThan(1, $batchCount);
        $this->assertSame(8, $rowCount);
    }

    /** @return array<string,array{bool}> */
    public function cursorProvider(): array
    {
        return ['primary key' => [true], 'offset' => [false]];
    }

    /** @dataProvider stringCursorProvider */
    public function testStringPrimaryKeyCursorExportsEveryRow(string $kind): void
    {
        $rows = [];
        if ($kind === 'empty string') {
            $rows[] = ['', 'last'];
        } else {
            for ($i = 0; $i <= 500; $i++) {
                $key = $kind === 'UUID'
                    ? sprintf('a0000000-0000-0000-0000-%012d', $i)
                    : '18446744073709551' . sprintf('%03d', $i);
                $rows[] = [$key, $i === 500 ? 'last' : 'repeat-' . $i];
            }
        }
        $source = new ArrayDatabaseSource([
            'wp_keys' => ['columns' => ['id', 'value'], 'pk' => 'id', 'rows' => $rows],
        ]);
        $path = $GLOBALS['MUDRAVA_TEST_TMP'] . '/db-cursor-' . uniqid() . '.mudrava';
        $out = fopen($path, 'wb');
        $writer = new FrameWriter(
            $out,
            new Header(1, 0, random_bytes(16), '1.0.0', 0, 0, 0, random_bytes(16), random_bytes(8)),
            new DeflateCodec(),
            null
        );
        $exporter = new Exporter($writer, $source, new ArrayFileInventory([]), []);
        $exporter->writeSiteMetadata();
        for ($i = 0; $i < 8 && $exporter->step() !== Exporter::STATE_DONE; $i++) {
            // Advance over the table and final file stage.
        }
        $this->assertSame(Exporter::STATE_DONE, $exporter->state());
        $exporter->finalize();
        fclose($out);

        $in = fopen($path, 'rb');
        $reader = new FrameReader($in, new DeflateCodec());
        $values = [];
        while (($frame = $reader->next()) !== null) {
            if ($frame->type === FrameType::DB_ROWS) {
                foreach (RowCodec::decode($frame->payload) as $row) {
                    $values[] = $row[1];
                }
            }
        }
        fclose($in);
        unlink($path);
        $this->assertCount(count($rows), $values);
        $this->assertSame('last', $values[count($rows) - 1]);
    }

    /** @return array<string,array{string}> */
    public function stringCursorProvider(): array
    {
        return [
            'UUID' => ['UUID'],
            'unsigned bigint' => ['unsigned bigint'],
            'empty string' => ['empty string'],
        ];
    }
}
