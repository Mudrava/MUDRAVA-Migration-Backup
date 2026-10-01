<?php

/**
 * Export must not complete when a file differs from its inventory metadata.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Contract;

use Mudrava\Migration\Archive\FrameWriter;
use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Compression\DeflateCodec;
use Mudrava\Migration\Filesystem\WpFileInventory;
use Mudrava\Migration\Migration\ExportTick;
use Mudrava\Migration\Migration\Exporter;
use Mudrava\Migration\Tests\Support\ArrayDatabaseSource;
use Mudrava\Migration\Tests\Support\ArrayFileInventory;
use PHPUnit\Framework\TestCase;

final class ExportFileSizeTest extends TestCase
{
    /** @dataProvider declaredSizes */
    public function testChangedFileCannotProduceSuccessfulExport(int $declaredSize): void
    {
        $path = $GLOBALS['MUDRAVA_TEST_TMP'] . '/changed-file-' . uniqid() . '.mudrava';
        $stream = fopen($path, 'wb');
        $writer = new FrameWriter(
            $stream,
            new Header(1, 0, random_bytes(16), 'test', 0, 0, 0, random_bytes(16), random_bytes(8)),
            new DeflateCodec(),
            null
        );
        $exporter = new Exporter(
            $writer,
            new ArrayDatabaseSource([]),
            new ArrayFileInventory(['changed.txt' => 'actual'], ['changed.txt' => ['size' => $declaredSize]]),
            []
        );
        $exporter->writeSiteMetadata();
        $caught = null;
        try {
            for ($i = 0; $i < 8 && $exporter->step() !== Exporter::STATE_DONE; $i++) {
                // Advance over the database transition and file data.
            }
        } catch (\RuntimeException $error) {
            $caught = $error;
        } finally {
            fclose($stream);
            unlink($path);
        }
        $this->assertInstanceOf(\RuntimeException::class, $caught, 'changed file was accepted');
        $this->assertStringContainsString('MUDRAVA_ARCHIVE_CHANGED', $caught->getMessage());
    }

    /** @return array<string,array{int}> */
    public function declaredSizes(): array
    {
        return ['source shrank' => [8], 'source grew' => [3]];
    }

    public function testChangedFileIsRejectedAfterCheckpointedFirstChunk(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/source-file-' . uniqid();
        mkdir($root);
        $sourcePath = $root . '/large.bin';
        file_put_contents($sourcePath, str_repeat('A', Exporter::FILE_CHUNK_BYTES + 1));
        $archive = $GLOBALS['MUDRAVA_TEST_TMP'] . '/source-file-' . uniqid() . '.mudrava';
        $db = static function (): ArrayDatabaseSource {
            return new ArrayDatabaseSource([]);
        };
        $files = static function () use ($root): WpFileInventory {
            return new WpFileInventory($root);
        };
        $cipher = static function (): ?\Mudrava\Migration\Crypto\FrameCipher {
            return null;
        };
        $header = new Header(1, 0, random_bytes(16), 'test', 0, 0, 0, random_bytes(16), random_bytes(8));
        $first = ExportTick::run($archive, null, $db, $files, [], $header, $cipher, null, microtime(true) - 1);
        $second = ExportTick::run(
            $archive,
            $first['checkpoint'],
            $db,
            $files,
            [],
            null,
            $cipher,
            null,
            microtime(true) - 1
        );
        $cursor = (array) $second['checkpoint']['cursor'];
        $this->assertSame(Exporter::FILE_CHUNK_BYTES, $cursor['file_offset']);
        file_put_contents($sourcePath, 'short');

        $caught = null;
        try {
            ExportTick::run($archive, $second['checkpoint'], $db, $files, [], null, $cipher, null, microtime(true) - 1);
        } catch (\RuntimeException $error) {
            $caught = $error;
        }
        $this->assertInstanceOf(\RuntimeException::class, $caught);
        $this->assertStringContainsString('MUDRAVA_ARCHIVE_CHANGED', $caught->getMessage());
    }

    public function testSameLengthEditOfCheckpointedChunkIsRejected(): void
    {
        [$archive, $sourcePath, $checkpoint, $db, $files, $cipher] = $this->checkpointedFile();
        $checkpoint = (array) json_decode((string) json_encode($checkpoint));
        $handle = fopen($sourcePath, 'r+b');
        fseek($handle, intdiv(Exporter::FILE_CHUNK_BYTES, 2));
        fwrite($handle, 'B');
        fclose($handle);

        $caught = null;
        try {
            ExportTick::run($archive, $checkpoint, $db, $files, [], null, $cipher, null, microtime(true) - 1);
        } catch (\RuntimeException $error) {
            $caught = $error;
        }
        $this->assertInstanceOf(\RuntimeException::class, $caught);
        $this->assertStringContainsString('MUDRAVA_ARCHIVE_CHANGED', $caught->getMessage());
    }

    public function testSameLengthReplacementOfCheckpointedFileIsRejected(): void
    {
        [$archive, $sourcePath, $checkpoint, $db, $files, $cipher] = $this->checkpointedFile();
        $old = $sourcePath . '.old';
        rename($sourcePath, $old);
        copy($old, $sourcePath);

        $caught = null;
        try {
            ExportTick::run($archive, $checkpoint, $db, $files, [], null, $cipher, null, microtime(true) - 1);
        } catch (\RuntimeException $error) {
            $caught = $error;
        }
        $this->assertInstanceOf(\RuntimeException::class, $caught);
        $this->assertStringContainsString('MUDRAVA_ARCHIVE_CHANGED', $caught->getMessage());
    }

    public function testChunkHashRejectsSameLengthEditWithoutFilesystemTimestamps(): void
    {
        $archive = $GLOBALS['MUDRAVA_TEST_TMP'] . '/source-file-' . uniqid() . '.mudrava';
        $content = str_repeat('A', Exporter::FILE_CHUNK_BYTES + 1);
        $inventory = new ArrayFileInventory(['large.bin' => $content]);
        $db = static function (): ArrayDatabaseSource {
            return new ArrayDatabaseSource([]);
        };
        $files = static function () use ($inventory): ArrayFileInventory {
            return $inventory;
        };
        $cipher = static function (): ?\Mudrava\Migration\Crypto\FrameCipher {
            return null;
        };
        $header = new Header(1, 0, random_bytes(16), 'test', 0, 0, 0, random_bytes(16), random_bytes(8));
        $first = ExportTick::run($archive, null, $db, $files, [], $header, $cipher, null, microtime(true) - 1);
        $second = ExportTick::run(
            $archive,
            $first['checkpoint'],
            $db,
            $files,
            [],
            null,
            $cipher,
            null,
            microtime(true) - 1
        );
        $this->assertSame(Exporter::FILE_CHUNK_BYTES, ((array) $second['checkpoint']['cursor'])['file_offset']);
        $inventory->replaceContent('large.bin', 'B' . substr($content, 1));

        $caught = null;
        try {
            ExportTick::run($archive, $second['checkpoint'], $db, $files, [], null, $cipher, null, microtime(true) - 1);
        } catch (\RuntimeException $error) {
            $caught = $error;
        }
        $this->assertInstanceOf(\RuntimeException::class, $caught);
        $this->assertStringContainsString('previous source file chunk changed', $caught->getMessage());
    }

    /** @return array{string,string,array<string,mixed>,callable,callable,callable} */
    private function checkpointedFile(): array
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/source-file-' . uniqid();
        mkdir($root);
        $sourcePath = $root . '/large.bin';
        file_put_contents($sourcePath, str_repeat('A', Exporter::FILE_CHUNK_BYTES + 1));
        $archive = $GLOBALS['MUDRAVA_TEST_TMP'] . '/source-file-' . uniqid() . '.mudrava';
        $db = static function (): ArrayDatabaseSource {
            return new ArrayDatabaseSource([]);
        };
        $files = static function () use ($root): WpFileInventory {
            return new WpFileInventory($root);
        };
        $cipher = static function (): ?\Mudrava\Migration\Crypto\FrameCipher {
            return null;
        };
        $header = new Header(1, 0, random_bytes(16), 'test', 0, 0, 0, random_bytes(16), random_bytes(8));
        $first = ExportTick::run($archive, null, $db, $files, [], $header, $cipher, null, microtime(true) - 1);
        $second = ExportTick::run(
            $archive,
            $first['checkpoint'],
            $db,
            $files,
            [],
            null,
            $cipher,
            null,
            microtime(true) - 1
        );
        $cursor = (array) $second['checkpoint']['cursor'];
        $this->assertSame(Exporter::FILE_CHUNK_BYTES, $cursor['file_offset']);
        return [$archive, $sourcePath, $second['checkpoint'], $db, $files, $cipher];
    }
}
