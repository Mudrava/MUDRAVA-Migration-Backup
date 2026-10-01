<?php

/**
 * Cross-process resume regression.
 *
 * The vertical migration test drives the whole export in ONE process, so it
 * never exercises the path that actually runs on a shared host: a fresh
 * process per tick, each resuming from the persisted checkpoint. A past bug
 * made the first tick fail to leave a durable checkpoint, so every later
 * tick re-truncated the archive and the export never advanced. This test
 * simulates process restarts (a new Exporter + writer per tick, deadline in
 * the past so each tick does exactly one batch) and proves the archive
 * converges to a valid, complete, importable result.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Contract;

use Mudrava\Migration\Archive\FrameReader;
use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Compression\DeflateCodec;
use Mudrava\Migration\Migration\ExportTick;
use Mudrava\Migration\Migration\Exporter;
use Mudrava\Migration\Migration\Importer;
use Mudrava\Migration\Tests\Support\ArrayDatabaseSource;
use Mudrava\Migration\Tests\Support\ArrayFileInventory;
use Mudrava\Migration\Tests\Support\LocalFileTarget;
use Mudrava\Migration\Tests\Support\RecordingDatabaseTarget;
use PHPUnit\Framework\TestCase;

final class CrossProcessResumeTest extends TestCase
{
    /** @var string */
    private $tmp;

    /** @var string stable big-file content shared by every simulated tick */
    private $bigBin;

    protected function setUp(): void
    {
        $this->tmp = $GLOBALS['MUDRAVA_TEST_TMP'] . '/resume-' . uniqid();
        mkdir($this->tmp, 0777, true);
        // The source file must be byte-stable across simulated restarts,
        // exactly like a real file on disk; otherwise reassembled chunks
        // would not match and the test would not prove byte-identity.
        $this->bigBin = random_bytes(9 * 1024 * 1024);
    }

    private function makeDb(): ArrayDatabaseSource
    {
        $rows = [];
        $uuidRows = [];
        $bigintRows = [];
        // More than one DB batch is essential: a resumed export previously
        // treated the saved PK cursor as an offset and lost the second batch.
        for ($i = 1; $i <= 600; $i++) {
            $rows[] = [(string) $i, 'opt' . $i, 'value ' . $i];
            $uuidRows[] = [sprintf('a0000000-0000-0000-0000-%012d', $i), 'uuid ' . $i];
            $bigintRows[] = ['18446744073709551' . sprintf('%03d', $i), 'bigint ' . $i];
        }
        return new ArrayDatabaseSource([
            'wp_bigint' => [
                'columns' => ['id', 'value'],
                'pk'      => 'id',
                'rows'    => $bigintRows,
            ],
            'wp_options' => [
                'columns' => ['option_id', 'option_name', 'option_value'],
                'pk'      => 'option_id',
                'rows'    => $rows,
            ],
            'wp_posts' => [
                'columns' => ['ID', 'post_content'],
                'pk'      => 'ID',
                'rows'    => [
                    ['1', 'hello'],
                    ['2', 'world'],
                ],
            ],
            'wp_uuid' => [
                'columns' => ['id', 'value'],
                'pk'      => 'id',
                'rows'    => $uuidRows,
            ],
        ]);
    }

    private function makeFiles(): ArrayFileInventory
    {
        return new ArrayFileInventory([
            'index.php'                  => "<?php // site\n",
            'wp-content/a.txt'           => str_repeat('A', 4096),
            // > 8 MiB forces a multi-chunk file, so resume happens mid-file.
            'wp-content/uploads/big.bin' => $this->bigBin,
        ]);
    }

    /** @dataProvider splitProvider */
    public function testExportResumesAcrossSimulatedProcessRestarts(?int $splitBytes): void
    {
        $archive = $this->tmp . '/site.mudrava';
        $siteMeta = ['site_url' => 'https://old.example', 'home_url' => 'https://old.example'];

        $freshHeader = static function (): Header {
            return new Header(
                Header::CONTAINER_FORMAT,
                0,
                random_bytes(16),
                '1.0.0-test',
                0,
                0,
                0,
                str_repeat("\0", 16),
                str_repeat("\0", 8)
            );
        };
        $noCipher = static function (): ?\Mudrava\Migration\Crypto\FrameCipher {
            return null;
        };

        $checkpoint = null;
        $ticks = 0;
        $guard = 0;
        // Deadline in the past => each tick performs exactly one batch,
        // forcing many simulated restarts through the resume path.
        $elapsed = microtime(true) - 10.0;

        while (true) {
            $result = ExportTick::run(
                $archive,
                $checkpoint,
                fn (): ArrayDatabaseSource => $this->makeDb(),
                fn (): ArrayFileInventory => $this->makeFiles(),
                $siteMeta,
                $checkpoint === null ? $freshHeader() : null,
                $noCipher,
                $splitBytes,
                $elapsed
            );
            $ticks++;

            // The bug: the first tick left no checkpoint, so the next tick
            // truncated the file and restarted forever.
            $this->assertNotNull(
                $result['checkpoint'],
                'every non-final tick must persist a durable checkpoint (tick ' . $ticks . ')'
            );

            $checkpoint = $result['checkpoint'];
            if ($result['state'] === Exporter::STATE_DONE) {
                break;
            }
            if (++$guard > 5000) {
                $this->fail('export did not converge across ticks (stuck resuming?)');
            }
        }

        $this->assertGreaterThan(1, $ticks, 'test must span multiple simulated processes');
        $this->assertFileExists($archive);
        if ($splitBytes !== null) {
            $this->assertFileExists($archive . '.part0002');
        }

        // The converged archive must import cleanly with full verification.
        $destRoot = $this->tmp . '/dest';
        $in = fopen($archive, 'rb');
        $this->assertNotFalse($in);
        $nextPart = 1;
        $provider = static function () use (&$nextPart, $archive) {
            $nextPart++;
            $path = sprintf('%s.part%04d', $archive, $nextPart);
            return is_file($path) ? fopen($path, 'rb') : null;
        };
        $reader = new FrameReader($in, new DeflateCodec(), null, $provider);
        $dest = new RecordingDatabaseTarget();
        $importer = new Importer($reader, $dest, new LocalFileTarget($destRoot));
        $guard = 0;
        while ($importer->step() !== Importer::STATE_DONE) {
            if (++$guard > 10000) {
                $this->fail('import did not terminate');
            }
        }
        fclose($in);

        $this->assertSame(1802, $importer->restoredRows(), 'all rows restored exactly once');
        $this->assertCount(600, $dest->rows['wp_options']);
        $this->assertCount(2, $dest->rows['wp_posts']);
        $this->assertCount(600, $dest->rows['wp_bigint']);
        $this->assertCount(600, $dest->rows['wp_uuid']);
        $this->assertSame('value 600', $dest->rows['wp_options'][599][2]);
        $this->assertSame('bigint 600', $dest->rows['wp_bigint'][599][1]);
        $this->assertSame('uuid 600', $dest->rows['wp_uuid'][599][1]);

        // Multi-chunk file survived mid-file resume byte-identically.
        $big = file_get_contents($destRoot . '/wp-content/uploads/big.bin');
        $this->assertSame($this->bigBin, $big, 'big file restored byte-identical');
        $this->assertSame(
            "<?php // site\n",
            file_get_contents($destRoot . '/index.php')
        );
    }

    /** @return array<string,array{?int}> */
    public function splitProvider(): array
    {
        return ['single part' => [null], 'split parts' => [2097152]];
    }
}
