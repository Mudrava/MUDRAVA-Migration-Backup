<?php
/**
 * Cross-process IMPORT resume regression (bug #9).
 *
 * The livelock: a resumed import tick re-created the reader at the start of
 * the archive and "skipped forward" to the persisted cursor, but the skip
 * consumed the tick's frame budget before ever reaching the resume point, so
 * the cursor never moved and the job spun forever on the same table.
 *
 * The fix is seek-based resume: a checkpoint stores the physical boundary
 * (part + offset + sequence + root-hash) of the unit about to be processed,
 * and a resumed tick seeks there in O(1) and reprocesses the unit
 * idempotently. This test simulates real process restarts - a fresh reader,
 * database target, and file target per tick, with the deadline in the past
 * so each tick stops at the first unit boundary - and proves the import
 * converges to a complete, byte-identical restore.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Contract;

use Mudrava\Migration\Archive\Checkpoint;
use Mudrava\Migration\Archive\FrameReader;
use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Compression\DeflateCodec;
use Mudrava\Migration\Migration\Exporter;
use Mudrava\Migration\Migration\Importer;
use Mudrava\Migration\Tests\Support\ArrayDatabaseSource;
use Mudrava\Migration\Tests\Support\ArrayFileInventory;
use Mudrava\Migration\Tests\Support\LocalFileTarget;
use Mudrava\Migration\Tests\Support\RecordingDatabaseTarget;
use PHPUnit\Framework\TestCase;

final class ImportCrossProcessResumeTest extends TestCase
{
    /** @var string */
    private $tmp;

    /** @var string byte-stable big file shared by every simulated tick */
    private $bigBin;

    protected function setUp(): void
    {
        $this->tmp = $GLOBALS['MUDRAVA_TEST_TMP'] . '/import-resume-' . uniqid();
        mkdir($this->tmp, 0777, true);
        $this->bigBin = random_bytes(3 * 1024 * 1024);
    }

    private function makeDb(): ArrayDatabaseSource
    {
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = [(string) $i, 'opt' . $i, 'value ' . $i];
        }
        return new ArrayDatabaseSource([
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
                    ['3', 'mudrava'],
                ],
            ],
            'wp_users' => [
                'columns' => ['ID', 'user_login'],
                'pk'      => 'ID',
                'rows'    => [['1', 'admin']],
            ],
        ]);
    }

    private function makeFiles(): ArrayFileInventory
    {
        return new ArrayFileInventory(
            [
                'index.php'                  => "<?php // site\n",
                'wp-content/a.txt'           => str_repeat('A', 4096),
                // Symlink entry: path present, payload lives in metadata only.
                'wp-content/sym.txt'         => '',
                // Multi-chunk file: resume crosses file boundaries too.
                'wp-content/uploads/big.bin' => $this->bigBin,
            ],
            [
                'wp-content/sym.txt' => ['type' => 'symlink', 'target' => 'a.txt'],
            ]
        );
    }

    private function exportArchive(string $archivePath): void
    {
        $out = fopen($archivePath, 'wb');
        $this->assertNotFalse($out);
        $header = new Header(
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
        $writer = new \Mudrava\Migration\Archive\FrameWriter($out, $header, new DeflateCodec(), null);
        $exporter = new Exporter($writer, $this->makeDb(), $this->makeFiles(), [
            'site_url'   => 'https://old.example',
            'home_url'   => 'https://old.example',
            'wp_version' => '6.5',
            'charset'    => 'UTF-8',
        ]);
        $exporter->writeSiteMetadata();
        $guard = 0;
        while ($exporter->step() !== Exporter::STATE_DONE) {
            if (++$guard > 10000) {
                $this->fail('export did not terminate');
            }
        }
        $exporter->finalize();
        fclose($out);
    }

    public function testImportResumesAcrossSimulatedProcessRestarts(): void
    {
        $archive = $this->tmp . '/site.mudrava';
        $this->exportArchive($archive);

        $destRoot = $this->tmp . '/dest';
        $checkpoint = null;
        $ticks = 0;
        $guard = 0;
        $finalState = null;
        $finalLogicalBytes = 0;
        $lastLogicalBytes = 0;
        // Deadline in the past => every tick stops at the first unit
        // boundary after doing at least one unit, forcing many restarts.
        $deadline = microtime(true) - 10.0;

        while (true) {
            // A fresh process: fresh reader, fresh targets, nothing in memory.
            $in = fopen($archive, 'rb');
            $this->assertNotFalse($in);
            $reader = new FrameReader($in, new DeflateCodec());
            $reader->readHeader();

            $resume = $checkpoint !== null ? Checkpoint::fromArray($checkpoint) : null;
            $dest = new RecordingDatabaseTarget();
            $importer = new Importer($reader, $dest, new LocalFileTarget($destRoot), [], $resume);

            $state = $importer->step($deadline);
            $ticks++;

            if ($state === Importer::STATE_DONE) {
                $this->assertGreaterThan($lastLogicalBytes, $reader->logicalBytes());
                $finalLogicalBytes = $reader->logicalBytes();
                $finalState = $state;
                fclose($in);
                break;
            }

            $data = $importer->checkpointData();
            $this->assertNotNull($data['root_hash'] ?? null, 'checkpoint must carry chain state');
            $this->assertNotSame('', (string) $data['root_hash'], 'root hash must be non-empty');
            $this->assertGreaterThan(
                $lastLogicalBytes,
                (int) ($data['logical_bytes'] ?? 0),
                'resumed import must retain cumulative bytes at each durable boundary'
            );
            $lastLogicalBytes = (int) $data['logical_bytes'];

            // Round-trip through JSON exactly like the WP option does, so a
            // stdClass cursor and decimal-string offsets survive reality.
            $checkpoint = json_decode((string) json_encode($data), true);

            fclose($in);
            if (++$guard > 5000) {
                $this->fail('import did not converge across ticks (livelock?)');
            }
        }

        $this->assertSame(Importer::STATE_DONE, $finalState);
        $this->assertGreaterThan(1, $ticks, 'test must span multiple simulated processes');

        // Every row restored exactly once, despite replayed units.
        $in = fopen($archive, 'rb');
        $reader = new FrameReader($in, new DeflateCodec());
        $reader->readHeader();
        $dest = new RecordingDatabaseTarget();
        $importer = new Importer($reader, $dest, new LocalFileTarget($this->tmp . '/single-shot'));
        $guard = 0;
        while ($importer->step() !== Importer::STATE_DONE) {
            if (++$guard > 10000) {
                $this->fail('single-shot import did not terminate');
            }
        }
        $this->assertSame($reader->logicalBytes(), $finalLogicalBytes);
        fclose($in);
        $expectedRows = 30 + 3 + 1;
        $this->assertSame($expectedRows, $importer->restoredRows());

        // The resumed (multi-tick) restore must match the single-shot one.
        $this->assertSame($this->bigBin, file_get_contents($destRoot . '/wp-content/uploads/big.bin'));
        $this->assertSame("<?php // site\n", file_get_contents($destRoot . '/index.php'));
        $this->assertSame(str_repeat('A', 4096), file_get_contents($destRoot . '/wp-content/a.txt'));
        $this->assertFileExists($destRoot . '/wp-content/sym.txt');
    }
}
