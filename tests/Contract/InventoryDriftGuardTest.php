<?php

/**
 * BUG-03: the inventory re-walks the tree on every resume tick and skips
 * completed entries by COUNT. A file added before the resume position
 * consumes a skip and lands the cursor on an already-emitted path, which
 * would duplicate FILE_METADATA (and the whole file body) inside a
 * "verified" archive. The checkpoint remembers the last emitted path and
 * the resume refuses such a collision. Deletions before the position are
 * a forward jump and stay undetectable - that limit is asserted too.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Contract;

use Mudrava\Migration\Archive\FrameReader;
use Mudrava\Migration\Archive\FrameType;
use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Compression\DeflateCodec;
use Mudrava\Migration\Migration\ExportTick;
use Mudrava\Migration\Migration\Exporter;
use Mudrava\Migration\Tests\Support\ArrayDatabaseSource;
use Mudrava\Migration\Tests\Support\ArrayFileInventory;
use PHPUnit\Framework\TestCase;

final class InventoryDriftGuardTest extends TestCase
{
    /** @var list<string> */
    private $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->paths = [];
        parent::tearDown();
    }

    /** @return array{0:string,1:callable,2:callable,3:callable} */
    private function harness(ArrayFileInventory $inventory): array
    {
        $archive = $GLOBALS['MUDRAVA_TEST_TMP'] . '/drift-' . uniqid() . '.mudrava';
        $this->paths[] = $archive;
        $db = static function (): ArrayDatabaseSource {
            return new ArrayDatabaseSource([]);
        };
        $files = static function () use ($inventory): ArrayFileInventory {
            return $inventory;
        };
        $cipher = static function (): ?\Mudrava\Migration\Crypto\FrameCipher {
            return null;
        };
        return [$archive, $db, $files, $cipher];
    }

    private function header(): Header
    {
        return new Header(1, 0, random_bytes(16), 'test', 0, 0, 0, random_bytes(16), random_bytes(8));
    }

    /**
     * A.3e: a file added to a not-yet-listed directory before the resume
     * position shifts the skip count onto the already-emitted current
     * file. The resume must refuse instead of archiving it twice.
     */
    public function testAddedFileBeforeResumePositionIsRefused(): void
    {
        $big = str_repeat('A', Exporter::FILE_CHUNK_BYTES + 1);
        $inventory = new ArrayFileInventory([
            'wp-content/uploads/2026/09/big.bin' => $big,
            'wp-content/uploads/2026/09/z.txt' => 'z',
        ]);
        [$archive, $db, $files, $cipher] = $this->harness($inventory);

        // Drive ticks until the cursor is mid-way through big.bin: the
        // first tick only performs the DB -> FILES transition, the second
        // emits FILE_METADATA and the first chunk.
        $checkpoint = null;
        $cursor = [];
        for ($i = 0; $i < 10; $i++) {
            $next = ExportTick::run(
                $archive,
                $checkpoint,
                $db,
                $files,
                [],
                $checkpoint === null ? $this->header() : null,
                $cipher,
                null,
                microtime(true) - 1
            );
            $checkpoint = $next['checkpoint'];
            $cursor = (array) $checkpoint['cursor'];
            if (($cursor['path'] ?? null) === 'wp-content/uploads/2026/09/big.bin'
                && (int) $cursor['file_offset'] > 0
            ) {
                break;
            }
        }
        $this->assertSame('wp-content/uploads/2026/09/big.bin', $cursor['path']);
        $this->assertSame(Exporter::FILE_CHUNK_BYTES, (int) $cursor['file_offset']);
        $this->assertSame('wp-content/uploads/2026/09/big.bin', $cursor['last_file_path']);

        // The A.3e mutation: sorts before big.bin, so the re-walk inserts
        // it ahead of the resume position.
        $inventory->addFile('wp-content/uploads/2026/09/added-between.txt', "added-after-scan\n");

        // Tick: finish reading big.bin's tail (no inventory entry reached).
        $next = ExportTick::run($archive, $checkpoint, $db, $files, [], null, $cipher, null, microtime(true) - 1);
        $cursor = (array) $next['checkpoint']['cursor'];
        $this->assertSame('wp-content/uploads/2026/09/big.bin', $cursor['path']);

        // Tick: big.bin completes; the cursor is now between files.
        $next = ExportTick::run($archive, $next['checkpoint'], $db, $files, [], null, $cipher, null, microtime(true) - 1);
        $cursor = (array) $next['checkpoint']['cursor'];
        $this->assertNull($cursor['path']);
        $this->assertSame(1, (int) $cursor['file_count']);

        // Next tick must refuse: the skip count now lands on big.bin again.
        $caught = null;
        try {
            ExportTick::run($archive, $next['checkpoint'], $db, $files, [], null, $cipher, null, microtime(true) - 1);
        } catch (\RuntimeException $error) {
            $caught = $error;
        }
        $this->assertInstanceOf(\RuntimeException::class, $caught);
        $this->assertStringContainsString('MUDRAVA_ARCHIVE_CHANGED', $caught->getMessage());
        $this->assertStringContainsString('collided', $caught->getMessage());
    }

    /**
     * The same guard must not fire for an honest mixed-time resume: a new
     * file that sorts after the resume position is simply included.
     */
    public function testAddedFileAfterResumePositionIsIncludedHonestly(): void
    {
        $big = str_repeat('A', Exporter::FILE_CHUNK_BYTES + 1);
        $inventory = new ArrayFileInventory([
            'wp-content/uploads/2026/09/big.bin' => $big,
            'wp-content/uploads/2026/09/z.txt' => 'z',
        ]);
        [$archive, $db, $files, $cipher] = $this->harness($inventory);

        $first = ExportTick::run($archive, null, $db, $files, [], $this->header(), $cipher, null, microtime(true) - 1);
        $inventory->addFile('wp-content/uploads/2026/09/zzz-late.txt', 'late');
        $second = ExportTick::run($archive, $first['checkpoint'], $db, $files, [], null, $cipher, null, microtime(true) - 1);

        $state = 'files';
        for ($i = 0; $i < 10 && $state !== Exporter::STATE_DONE; $i++) {
            $next = ExportTick::run($archive, $second['checkpoint'], $db, $files, [], null, $cipher, null, microtime(true) - 1);
            $second = $next;
            $state = (string) $next['state'];
        }
        $this->assertSame(Exporter::STATE_DONE, $state);

        $paths = $this->metadataPaths($archive);
        $this->assertSame([
            'wp-content/uploads/2026/09/big.bin',
            'wp-content/uploads/2026/09/z.txt',
            'wp-content/uploads/2026/09/zzz-late.txt',
        ], $paths);
    }

    /**
     * A.3d: deleting a file the walk had not reached is a forward jump.
     * No checkpoint-only check can see it; the export completes and the
     * archive honestly reflects the mixed-time tree. This test pins that
     * documented limitation so it can never be claimed as detected.
     */
    public function testDeletedUnvisitedFileIsSilentlyOmittedKnownLimitation(): void
    {
        $big = str_repeat('A', Exporter::FILE_CHUNK_BYTES + 1);
        $inventory = new ArrayFileInventory([
            'wp-content/uploads/2026/09/big.bin' => $big,
            'wp-content/uploads/2026/09/stable.txt' => 'stable',
        ]);
        [$archive, $db, $files, $cipher] = $this->harness($inventory);

        $first = ExportTick::run($archive, null, $db, $files, [], $this->header(), $cipher, null, microtime(true) - 1);
        $inventory->removeFile('wp-content/uploads/2026/09/stable.txt');
        $second = ExportTick::run($archive, $first['checkpoint'], $db, $files, [], null, $cipher, null, microtime(true) - 1);

        $state = 'files';
        for ($i = 0; $i < 10 && $state !== Exporter::STATE_DONE; $i++) {
            $next = ExportTick::run($archive, $second['checkpoint'], $db, $files, [], null, $cipher, null, microtime(true) - 1);
            $second = $next;
            $state = (string) $next['state'];
        }
        $this->assertSame(Exporter::STATE_DONE, $state);
        $this->assertSame(['wp-content/uploads/2026/09/big.bin'], $this->metadataPaths($archive));
    }

    /**
     * Nested directories must not false-positive: the walk visits a
     * directory's children before its later siblings, so "a/x" legitimately
     * precedes "a.txt". A normal interrupted export across nested dirs
     * resumes cleanly and produces every path exactly once.
     */
    public function testNestedDirectoryResumeOrderIsNotFalsePositive(): void
    {
        $big = str_repeat('B', Exporter::FILE_CHUNK_BYTES + 1);
        $inventory = new ArrayFileInventory([
            'wp-content/uploads/a.txt' => 'a',
            'wp-content/uploads/a/x.txt' => 'x',
            'wp-content/uploads/big.bin' => $big,
        ]);
        [$archive, $db, $files, $cipher] = $this->harness($inventory);

        $checkpoint = null;
        $state = 'files';
        for ($i = 0; $i < 30 && $state !== Exporter::STATE_DONE; $i++) {
            $next = ExportTick::run(
                $archive,
                $checkpoint,
                $db,
                $files,
                [],
                $checkpoint === null ? $this->header() : null,
                $cipher,
                null,
                microtime(true) - 1
            );
            $checkpoint = $next['checkpoint'];
            $state = (string) $next['state'];
        }
        $this->assertSame(Exporter::STATE_DONE, $state);
        // Segment-wise walk order: the directory "a" sorts before the
        // sibling "a.txt" (strcmp('a','a.txt') < 0) and its children are
        // visited in place, so a/x.txt is legitimately emitted first.
        $this->assertSame([
            'wp-content/uploads/a/x.txt',
            'wp-content/uploads/a.txt',
            'wp-content/uploads/big.bin',
        ], $this->metadataPaths($archive));
    }

    /**
     * A checkpoint written before this guard has no last_file_path. The
     * check stays off and the resume behaves exactly as before.
     */
    public function testLegacyCheckpointWithoutLastPathStillResumes(): void
    {
        $big = str_repeat('C', Exporter::FILE_CHUNK_BYTES + 1);
        $inventory = new ArrayFileInventory([
            'wp-content/uploads/2026/09/big.bin' => $big,
            'wp-content/uploads/2026/09/z.txt' => 'z',
        ]);
        [$archive, $db, $files, $cipher] = $this->harness($inventory);

        // Drive to a mid-file checkpoint on big.bin (first tick is only
        // the DB -> FILES transition).
        $checkpoint = null;
        $cursor = [];
        for ($i = 0; $i < 10; $i++) {
            $next = ExportTick::run(
                $archive,
                $checkpoint,
                $db,
                $files,
                [],
                $checkpoint === null ? $this->header() : null,
                $cipher,
                null,
                microtime(true) - 1
            );
            $checkpoint = $next['checkpoint'];
            $cursor = (array) $checkpoint['cursor'];
            if (($cursor['path'] ?? null) === 'wp-content/uploads/2026/09/big.bin'
                && (int) $cursor['file_offset'] > 0
            ) {
                break;
            }
        }
        $this->assertArrayHasKey('last_file_path', $cursor);

        // Simulate a checkpoint written before the guard existed.
        $legacy = json_decode((string) json_encode($checkpoint), true);
        $legacyCursor = (array) $legacy['cursor'];
        unset($legacyCursor['last_file_path']);
        $legacy['cursor'] = $legacyCursor;
        $this->assertArrayNotHasKey('last_file_path', (array) $legacy['cursor']);

        $second = ExportTick::run($archive, $legacy, $db, $files, [], null, $cipher, null, microtime(true) - 1);
        $state = (string) $second['state'];
        for ($i = 0; $i < 10 && $state !== Exporter::STATE_DONE; $i++) {
            $next = ExportTick::run($archive, $second['checkpoint'], $db, $files, [], null, $cipher, null, microtime(true) - 1);
            $second = $next;
            $state = (string) $next['state'];
        }
        $this->assertSame(Exporter::STATE_DONE, $state);
        $this->assertSame([
            'wp-content/uploads/2026/09/big.bin',
            'wp-content/uploads/2026/09/z.txt',
        ], $this->metadataPaths($archive));
    }

    /** @return list<string> */
    private function metadataPaths(string $archive): array
    {
        $stream = fopen($archive, 'rb');
        $this->assertIsResource($stream);
        $reader = new FrameReader($stream, new DeflateCodec());
        $reader->readHeader();
        $paths = [];
        while (true) {
            $frame = $reader->next();
            if ($frame === null) {
                break;
            }
            if ($frame->type === FrameType::FILE_METADATA) {
                $json = json_decode((string) $frame->payload, true);
                $this->assertIsArray($json);
                $paths[] = (string) $json['path'];
            }
        }
        fclose($stream);
        return $paths;
    }
}
