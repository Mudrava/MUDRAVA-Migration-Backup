<?php

/**
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */


declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Http\ChunkUpload;
use Mudrava\Migration\Support\Paths;
use PHPUnit\Framework\TestCase;

final class ChunkUploadTest extends TestCase
{
    public function testWordPressUploadUsesPrivateDirectoryAndRemovesFilter(): void
    {
        $id = 'test-private-upload';
        $before = $GLOBALS['MUDRAVA_STUB_FILTERS'];
        $result = ChunkUpload::put($id, 1, 1, 0, 1, $this->chunk('<?php malicious();'));
        self::assertIsArray($result);
        $upload = $GLOBALS['MUDRAVA_STUB_LAST_UPLOAD'];
        self::assertSame('chunk.mudrava', $upload['file']['name']);
        self::assertSame('', $upload['uploads']['url']);
        self::assertSame((new Paths())->uploadsDir() . '/' . $id, $upload['uploads']['path']);
        self::assertArrayNotHasKey('action', $upload['overrides']);
        self::assertArrayNotHasKey('test_size', $upload['overrides']);
        self::assertSame($before, $GLOBALS['MUDRAVA_STUB_FILTERS']);
        $path = $upload['uploads']['path'] . '/' . $id . '.p0001.part000000';
        self::assertSame(0600, fileperms($path) & 0777);
        $this->removeUpload($id);
    }

    public function testRejectedUploadDoesNotAdvanceAndRemovesFilter(): void
    {
        $id = 'test-rejected-upload';
        $source = $this->chunk('data');
        $before = $GLOBALS['MUDRAVA_STUB_FILTERS'];
        $GLOBALS['MUDRAVA_STUB_UPLOAD_ERROR'] = true;
        try {
            $result = ChunkUpload::put($id, 1, 1, 0, 1, $source);
            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertSame('MUDRAVA_UPLOAD_CHUNK_REJECTED', $result->get_error_code());
            self::assertSame($before, $GLOBALS['MUDRAVA_STUB_FILTERS']);
            self::assertFileDoesNotExist((new Paths())->uploadsDir() . '/' . $id . '/' . $id . '.meta.json');
        } finally {
            unset($GLOBALS['MUDRAVA_STUB_UPLOAD_ERROR']);
            @unlink($source);
            $this->removeUpload($id);
        }
    }

    public function testPartialUploadIsRejectedBeforeStorage(): void
    {
        $source = $this->chunk('incomplete');
        try {
            $result = ChunkUpload::put('test-partial-upload', 1, 1, 0, 1, $source, UPLOAD_ERR_PARTIAL);
            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertSame('MUDRAVA_UPLOAD_CHUNK_MISSING', $result->get_error_code());
            self::assertFileExists($source);
        } finally {
            @unlink($source);
        }
    }

    private function chunk(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mudrava-chunk-');
        self::assertIsString($path);
        file_put_contents($path, $body);
        return $path;
    }

    private function removeUpload(string $id): void
    {
        $dir = (new Paths())->uploadsDir() . '/' . $id;
        foreach ((array) glob($dir . '/*') as $path) {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testConcurrentSafeCountsAndRetry(): void
    {
        $id = 'test-' . bin2hex(random_bytes(8));
        $first = ChunkUpload::put($id, 1, 2, 0, 2, $this->chunk('A'));
        self::assertIsArray($first);
        self::assertSame(1, $first['received']);
        $retry = ChunkUpload::put($id, 1, 2, 0, 2, $this->chunk('A'));
        self::assertIsArray($retry);
        self::assertSame(1, $retry['received']);
        $changed = ChunkUpload::put($id, 1, 2, 1, 3, $this->chunk('wrong'));
        self::assertInstanceOf(\WP_Error::class, $changed);
        self::assertSame('MUDRAVA_UPLOAD_CHUNK_RANGE', $changed->get_error_code());
        $second = ChunkUpload::put($id, 2, 2, 0, 1, $this->chunk('C'));
        self::assertIsArray($second);
        self::assertSame(2, $second['received']);
        $done = ChunkUpload::put($id, 1, 2, 1, 2, $this->chunk('B'));
        self::assertIsArray($done);
        self::assertFalse($done['done']);
        $step = ChunkUpload::finalize($id);
        self::assertIsArray($step);
        self::assertSame(1, $step['assembled']);
        $blocked = ChunkUpload::put($id, 1, 2, 0, 2, $this->chunk('different'));
        self::assertInstanceOf(\WP_Error::class, $blocked);
        self::assertSame('MUDRAVA_UPLOAD_ASSEMBLING', $blocked->get_error_code());
        $partOneDone = ChunkUpload::finalize($id);
        self::assertIsArray($partOneDone);
        self::assertSame(2, $partOneDone['assembled']);
        $checkpoint = json_decode((string) file_get_contents(
            (new Paths())->uploadsDir() . '/' . $id . '/' . $id . '.meta.json'
        ), true);
        self::assertIsArray($checkpoint);
        self::assertSame(2, $checkpoint['current_part']);
        self::assertSame(2, $checkpoint['assembled_total']);
        for ($i = 0; $i < 3; $i++) {
            $done = ChunkUpload::finalize($id);
        }
        self::assertIsArray($done);
        self::assertTrue($done['done']);
        $base = (new Paths())->storageDir() . '/' . $id . '.mudrava';
        self::assertSame('AB', file_get_contents($base));
        self::assertSame('C', file_get_contents($base . '.part0002'));
        $retryChunk = $this->chunk('B');
        $completedRetry = ChunkUpload::put($id, 1, 2, 1, 2, $retryChunk);
        self::assertIsArray($completedRetry);
        self::assertTrue($completedRetry['done']);
        self::assertSame($id, $completedRetry['archive_id']);
        @unlink($retryChunk);
        $this->removeUpload($id);
        @unlink($base);
        @unlink($base . '.part0002');
    }

    public function testRejectsUnboundedMetadata(): void
    {
        $tooManyParts = ChunkUpload::put('test-limits', 1, 4097, 0, 1, $this->chunk('A'));
        self::assertInstanceOf(\WP_Error::class, $tooManyParts);
        self::assertSame('MUDRAVA_UPLOAD_PART_RANGE', $tooManyParts->get_error_code());
        $tooManyChunks = ChunkUpload::put('test-limits', 1, 1, 0, 65537, $this->chunk('A'));
        self::assertInstanceOf(\WP_Error::class, $tooManyChunks);
        self::assertSame('MUDRAVA_UPLOAD_CHUNK_RANGE', $tooManyChunks->get_error_code());
    }

    public function testResumeStatusTracksContiguousChunksAndRejectsGaps(): void
    {
        $id = 'test-' . bin2hex(random_bytes(8));
        $paths = new Paths();
        try {
            self::assertSame(['found' => false], ChunkUpload::status($id));
            $gap = ChunkUpload::put($id, 1, 1, 1, 2, $this->chunk('second'));
            self::assertInstanceOf(\WP_Error::class, $gap);
            self::assertSame('MUDRAVA_UPLOAD_CHUNK_RANGE', $gap->get_error_code());
            $first = ChunkUpload::put($id, 1, 1, 0, 2, $this->chunk('first'));
            self::assertIsArray($first);
            $status = ChunkUpload::status($id);
            self::assertIsArray($status);
            self::assertTrue($status['found']);
            self::assertSame(1, $status['received'][1]);
            $retry = ChunkUpload::put($id, 1, 1, 0, 2, $this->chunk('first'));
            self::assertIsArray($retry);
            self::assertSame(1, $retry['received']);
            ChunkUpload::put($id, 1, 1, 1, 2, $this->chunk('second'));
            self::assertIsArray(ChunkUpload::finalize($id));
            $assembling = ChunkUpload::status($id);
            self::assertIsArray($assembling);
            self::assertTrue($assembling['assembling']);
            ChunkUpload::finalize($id);
            ChunkUpload::finalize($id);
            $done = ChunkUpload::status($id);
            self::assertIsArray($done);
            self::assertTrue($done['done']);
        } finally {
            @unlink($paths->storageDir() . '/' . $id . '.mudrava');
            $this->removeUpload($id);
        }
    }

    public function testFailedSecondPartPublicationCanResumeWithoutReuploadingFirstPart(): void
    {
        $id = 'test-' . bin2hex(random_bytes(8));
        $paths = new Paths();
        $base = $paths->ensureStorage() . '/' . $id . '.mudrava';
        $secondPath = $base . '.part0002';
        mkdir($secondPath);
        try {
            $first = ChunkUpload::put($id, 1, 2, 0, 1, $this->chunk('first'));
            self::assertIsArray($first);
            $uploaded = ChunkUpload::put($id, 2, 2, 0, 1, $this->chunk('second'));
            self::assertIsArray($uploaded);
            $assembledFirst = ChunkUpload::finalize($id);
            self::assertIsArray($assembledFirst);
            $failed = ChunkUpload::finalize($id);
            self::assertInstanceOf(\WP_Error::class, $failed);
            self::assertSame('MUDRAVA_PERMISSION_DENIED', $failed->get_error_code());
            rmdir($secondPath);
            $resumed = ChunkUpload::finalize($id);
            self::assertIsArray($resumed);
            $retry = ChunkUpload::finalize($id);
            self::assertIsArray($retry);
            self::assertTrue($retry['done']);
            self::assertSame('first', file_get_contents($base));
            self::assertSame('second', file_get_contents($secondPath));
        } finally {
            if (is_dir($secondPath)) {
                rmdir($secondPath);
            }
            @unlink($base);
            @unlink($secondPath);
            $this->removeUpload($id);
        }
    }

    public function testInterruptedAppendIsTruncatedToDurableCheckpoint(): void
    {
        $id = 'test-' . bin2hex(random_bytes(8));
        $paths = new Paths();
        $dir = $paths->uploadsDir() . '/' . $id;
        $target = $paths->storageDir() . '/' . $id . '.mudrava';
        try {
            ChunkUpload::put($id, 1, 1, 0, 2, $this->chunk('first'));
            ChunkUpload::put($id, 1, 1, 1, 2, $this->chunk('second'));
            $first = ChunkUpload::finalize($id);
            self::assertIsArray($first);
            self::assertSame(1, $first['assembled']);
            file_put_contents($dir . '/' . $id . '.p0001.final', 'partial', FILE_APPEND);
            $second = ChunkUpload::finalize($id);
            self::assertIsArray($second);
            $done = ChunkUpload::finalize($id);
            self::assertIsArray($done);
            self::assertTrue($done['done']);
            self::assertSame('firstsecond', file_get_contents($target));
        } finally {
            @unlink($target);
            $this->removeUpload($id);
        }
    }

    /** @dataProvider unsafeAssemblyLinks */
    public function testFinalizeRejectsLinkedAssemblyFileBeforeWriting(string $kind): void
    {
        $id = 'test-' . bin2hex(random_bytes(6));
        $paths = new Paths();
        $outside = $GLOBALS['MUDRAVA_TEST_TMP'] . '/assembly-outside-' . uniqid();
        $staging = $paths->uploadsDir() . '/' . $id . '/' . $id . '.p0001.final';
        $archive = $paths->storageDir() . '/' . $id . '.mudrava';
        file_put_contents($outside, 'outside content');
        try {
            self::assertIsArray(ChunkUpload::put($id, 1, 1, 0, 1, $this->chunk('incoming')));
            if ($kind === 'symlink') {
                symlink($outside, $staging);
            } else {
                link($outside, $staging);
            }
            $result = ChunkUpload::finalize($id);
            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertSame('MUDRAVA_UPLOAD_STATE_INVALID', $result->get_error_code());
            self::assertSame('outside content', file_get_contents($outside));
            self::assertFileDoesNotExist($archive);
        } finally {
            @unlink($archive);
            $this->removeUpload($id);
            @unlink($outside);
        }
    }

    /** @return array<string,array{string}> */
    public static function unsafeAssemblyLinks(): array
    {
        return ['symlink' => ['symlink'], 'hardlink' => ['hardlink']];
    }

    public function testCleanupRemovesStaleChunkButPreservesActiveUpload(): void
    {
        $dir = (new Paths())->uploadsDir();
        $stale = 'test-stale-' . bin2hex(random_bytes(5));
        $active = 'test-active-' . bin2hex(random_bytes(5));
        file_put_contents($dir . '/' . $stale . '.lock', '');
        file_put_contents($dir . '/' . $stale . '.p0001.part000000', 'old');
        touch($dir . '/' . $stale . '.lock', time() - DAY_IN_SECONDS - 10);
        file_put_contents($dir . '/' . $active . '.lock', '');
        file_put_contents($dir . '/' . $active . '.p0001.part000000', 'new');

        ChunkUpload::cleanupStale();

        self::assertFileDoesNotExist($dir . '/' . $stale . '.p0001.part000000');
        self::assertFileExists($dir . '/' . $active . '.p0001.part000000');
        @unlink($dir . '/' . $stale . '.lock');
        @unlink($dir . '/' . $active . '.lock');
        @unlink($dir . '/' . $active . '.p0001.part000000');
    }

    public function testCleanupIsBoundedAndResumesInsideUploadDirectory(): void
    {
        $id = 'test-stale-' . bin2hex(random_bytes(5));
        $dir = (new Paths())->uploadsDir() . '/' . $id;
        mkdir($dir, 0700);
        for ($i = 0; $i < 600; $i++) {
            file_put_contents($dir . '/' . $id . '.' . $i, 'x');
        }
        $lock = $dir . '/' . $id . '.lock';
        file_put_contents($lock, '');
        touch($lock, time() - DAY_IN_SECONDS - 10);
        ChunkUpload::cleanupStale();
        self::assertDirectoryExists($dir);
        self::assertFileExists($lock);
        self::assertGreaterThan(0, count((array) glob($dir . '/*')));
        ChunkUpload::cleanupStale();
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testRejectsInvalidIdsRangesMissingAndOversizedChunks(): void
    {
        $missing = $GLOBALS['MUDRAVA_TEST_TMP'] . '/missing-upload-chunk';
        $large = tempnam(sys_get_temp_dir(), 'mudrava-large-chunk-');
        self::assertIsString($large);
        $handle = fopen($large, 'c+b');
        self::assertIsResource($handle);
        ftruncate($handle, 16 * 1048576 + 1);
        fclose($handle);
        try {
            $cases = [
                [ChunkUpload::put('../bad', 1, 1, 0, 1, $missing), 'MUDRAVA_UPLOAD_ID_INVALID'],
                [ChunkUpload::put('valid-id', 0, 1, 0, 1, $missing), 'MUDRAVA_UPLOAD_PART_RANGE'],
                [ChunkUpload::put('valid-id', 2, 1, 0, 1, $missing), 'MUDRAVA_UPLOAD_PART_RANGE'],
                [ChunkUpload::put('valid-id', 1, 1, -1, 1, $missing), 'MUDRAVA_UPLOAD_CHUNK_RANGE'],
                [ChunkUpload::put('valid-id', 1, 1, 1, 1, $missing), 'MUDRAVA_UPLOAD_CHUNK_RANGE'],
                [ChunkUpload::put('valid-id', 1, 1, 0, 1, $missing), 'MUDRAVA_UPLOAD_CHUNK_MISSING'],
                [ChunkUpload::put('valid-id', 1, 1, 0, 1, $large), 'MUDRAVA_UPLOAD_CHUNK_TOO_LARGE'],
            ];
            foreach ($cases as [$result, $code]) {
                self::assertInstanceOf(\WP_Error::class, $result);
                self::assertSame($code, $result->get_error_code());
            }
            self::assertSame('MUDRAVA_UPLOAD_ID_INVALID', ChunkUpload::finalize('bad')->get_error_code());
            self::assertSame('MUDRAVA_UPLOAD_ID_INVALID', ChunkUpload::status('bad')->get_error_code());
        } finally {
            @unlink($large);
        }
    }

    public function testPutRejectsDamagedOrContradictoryState(): void
    {
        $paths = new Paths();
        $id = 'test-' . bin2hex(random_bytes(6));
        $dir = $paths->uploadsDir() . '/' . $id;
        mkdir($dir, 0700);
        file_put_contents($dir . '/' . $id . '.meta.json', 'not-json');
        try {
            $damaged = ChunkUpload::put($id, 1, 1, 0, 1, $this->chunk('a'));
            self::assertInstanceOf(\WP_Error::class, $damaged);
            self::assertSame('MUDRAVA_UPLOAD_STATE_INVALID', $damaged->get_error_code());
        } finally {
            $this->removeUpload($id);
        }

        $id = 'test-' . bin2hex(random_bytes(6));
        try {
            self::assertIsArray(ChunkUpload::put($id, 1, 2, 0, 2, $this->chunk('a')));
            $partsChanged = ChunkUpload::put($id, 1, 3, 1, 2, $this->chunk('b'));
            self::assertInstanceOf(\WP_Error::class, $partsChanged);
            self::assertSame('MUDRAVA_UPLOAD_PART_RANGE', $partsChanged->get_error_code());
            $chunksChanged = ChunkUpload::put($id, 1, 2, 1, 3, $this->chunk('b'));
            self::assertInstanceOf(\WP_Error::class, $chunksChanged);
            self::assertSame('MUDRAVA_UPLOAD_CHUNK_RANGE', $chunksChanged->get_error_code());

            $dir = $paths->uploadsDir() . '/' . $id;
            unlink($dir . '/' . $id . '.p0001.part000000');
            $missingPrevious = ChunkUpload::put($id, 1, 2, 0, 2, $this->chunk('a'));
            self::assertInstanceOf(\WP_Error::class, $missingPrevious);
            self::assertSame('MUDRAVA_UPLOAD_STATE_INVALID', $missingPrevious->get_error_code());
        } finally {
            $this->removeUpload($id);
        }
    }

    /** @dataProvider invalidFinalizeStates */
    public function testFinalizeRejectsInvalidDurableState(array $state, string $code, string $fixture): void
    {
        $id = 'test-' . bin2hex(random_bytes(6));
        $paths = new Paths();
        $dir = $paths->uploadsDir() . '/' . $id;
        mkdir($dir, 0700);
        file_put_contents($dir . '/' . $id . '.lock', '');
        file_put_contents($dir . '/' . $id . '.meta.json', (string) json_encode($state));
        $target = $paths->storageDir() . '/' . $id . '.mudrava';
        if ($fixture === 'chunk') {
            file_put_contents($dir . '/' . $id . '.p0001.part000000', 'a');
        } elseif ($fixture === 'target') {
            file_put_contents($dir . '/' . $id . '.p0001.part000000', 'a');
            file_put_contents($target, 'existing');
        } elseif ($fixture === 'directory') {
            file_put_contents($dir . '/' . $id . '.p0001.part000000', 'a');
            mkdir($target);
        }
        try {
            $result = ChunkUpload::finalize($id);
            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertSame($code, $result->get_error_code());
        } finally {
            if (is_dir($target)) {
                rmdir($target);
            } else {
                @unlink($target);
            }
            $this->removeUpload($id);
        }
    }

    /** @return array<string,array{array<string,mixed>,string,string}> */
    public static function invalidFinalizeStates(): array
    {
        return [
            'missing parts' => [[], 'MUDRAVA_UPLOAD_STATE_INVALID', 'none'],
            'incomplete' => [[
                'parts' => 1, 'expected' => [1 => 2], 'received' => [1 => 1],
            ], 'MUDRAVA_UPLOAD_INCOMPLETE', 'none'],
            'invalid checkpoint' => [[
                'parts' => 1, 'expected' => [1 => 1], 'received' => [1 => 1],
                'assembling' => true, 'total_chunks' => 1, 'current_part' => 3,
            ], 'MUDRAVA_UPLOAD_STATE_INVALID', 'none'],
            'negative chunk index' => [[
                'parts' => 1, 'expected' => [1 => 1], 'received' => [1 => 1],
                'assembling' => true, 'total_chunks' => 1, 'current_part' => 1,
                'assembled' => [1 => -1],
            ], 'MUDRAVA_UPLOAD_STATE_INVALID', 'none'],
            'missing chunk' => [[
                'parts' => 1, 'expected' => [1 => 1], 'received' => [1 => 1],
            ], 'MUDRAVA_UPLOAD_CHUNK_MISSING', 'none'],
            'target exists' => [[
                'parts' => 1, 'expected' => [1 => 1], 'received' => [1 => 1],
            ], 'MUDRAVA_UPLOAD_STATE_INVALID', 'target'],
            'target directory' => [[
                'parts' => 1, 'expected' => [1 => 1], 'received' => [1 => 1],
            ], 'MUDRAVA_PERMISSION_DENIED', 'directory'],
        ];
    }

    public function testFinalizeAndStatusRejectMissingOrDamagedReceipts(): void
    {
        $id = 'test-' . bin2hex(random_bytes(6));
        $paths = new Paths();
        $missing = ChunkUpload::finalize($id);
        self::assertInstanceOf(\WP_Error::class, $missing);
        self::assertSame('MUDRAVA_UPLOAD_STATE_INVALID', $missing->get_error_code());

        $dir = $paths->uploadsDir() . '/' . $id;
        mkdir($dir, 0700);
        file_put_contents($dir . '/' . $id . '.lock', '');
        file_put_contents($dir . '/' . $id . '.done.json', 'broken');
        try {
            $finalize = ChunkUpload::finalize($id);
            $status = ChunkUpload::status($id);
            self::assertInstanceOf(\WP_Error::class, $finalize);
            self::assertSame('MUDRAVA_UPLOAD_STATE_INVALID', $finalize->get_error_code());
            self::assertInstanceOf(\WP_Error::class, $status);
            self::assertSame('MUDRAVA_UPLOAD_STATE_INVALID', $status->get_error_code());
            file_put_contents($dir . '/' . $id . '.done.json', '{"parts":4097,"total":1}');
            $finalize = ChunkUpload::finalize($id);
            $status = ChunkUpload::status($id);
            self::assertInstanceOf(\WP_Error::class, $finalize);
            self::assertSame('MUDRAVA_UPLOAD_STATE_INVALID', $finalize->get_error_code());
            self::assertInstanceOf(\WP_Error::class, $status);
            self::assertSame('MUDRAVA_UPLOAD_STATE_INVALID', $status->get_error_code());
        } finally {
            $this->removeUpload($id);
        }
    }

    public function testCompletedReceiptRejectsChangedPartCountAndMissingPublishedPart(): void
    {
        $id = 'test-' . bin2hex(random_bytes(6));
        $paths = new Paths();
        $base = $paths->storageDir() . '/' . $id . '.mudrava';
        try {
            self::assertIsArray(ChunkUpload::put($id, 1, 1, 0, 1, $this->chunk('done')));
            self::assertIsArray(ChunkUpload::finalize($id));
            self::assertTrue(ChunkUpload::finalize($id)['done']);

            $changed = ChunkUpload::put($id, 1, 2, 0, 1, $this->chunk('retry'));
            self::assertInstanceOf(\WP_Error::class, $changed);
            self::assertSame('MUDRAVA_UPLOAD_PART_RANGE', $changed->get_error_code());

            unlink($base);
            $missing = ChunkUpload::put($id, 1, 1, 0, 1, $this->chunk('retry'));
            self::assertInstanceOf(\WP_Error::class, $missing);
            self::assertSame('MUDRAVA_UPLOAD_CHUNK_MISSING', $missing->get_error_code());
            $status = ChunkUpload::status($id);
            self::assertInstanceOf(\WP_Error::class, $status);
            self::assertSame('MUDRAVA_UPLOAD_CHUNK_MISSING', $status->get_error_code());
            $finalize = ChunkUpload::finalize($id);
            self::assertInstanceOf(\WP_Error::class, $finalize);
            self::assertSame('MUDRAVA_UPLOAD_CHUNK_MISSING', $finalize->get_error_code());
        } finally {
            @unlink($base);
            $this->removeUpload($id);
        }
    }

    public function testFinalizePublishesACompletedStagingCheckpoint(): void
    {
        $id = 'test-' . bin2hex(random_bytes(6));
        $paths = new Paths();
        $dir = $paths->uploadsDir() . '/' . $id;
        mkdir($dir, 0700);
        file_put_contents($dir . '/' . $id . '.lock', '');
        file_put_contents($dir . '/' . $id . '.p0001.final', 'assembled');
        file_put_contents($dir . '/' . $id . '.meta.json', (string) json_encode([
            'parts' => 1,
            'expected' => [1 => 1],
            'received' => [1 => 1],
            'assembling' => true,
            'total_chunks' => 1,
            'current_part' => 1,
            'assembled' => [1 => 1],
            'bytes' => [1 => 9],
            'assembled_total' => 1,
        ]));
        $target = $paths->storageDir() . '/' . $id . '.mudrava';
        try {
            $published = ChunkUpload::finalize($id);
            self::assertIsArray($published);
            self::assertTrue($published['done']);
            self::assertSame('assembled', file_get_contents($target));
        } finally {
            @unlink($target);
            $this->removeUpload($id);
        }
    }

    /** @dataProvider damagedAssemblyCheckpointProvider */
    public function testFinalizeRejectsUnreadableChunkAndImpossibleStagedLength(string $fixture): void
    {
        $id = 'test-' . bin2hex(random_bytes(6));
        $paths = new Paths();
        $dir = $paths->uploadsDir() . '/' . $id;
        mkdir($dir, 0700);
        file_put_contents($dir . '/' . $id . '.lock', '');
        $chunk = $dir . '/' . $id . '.p0001.part000000';
        $final = $dir . '/' . $id . '.p0001.final';
        if ($fixture === 'chunk-directory') {
            mkdir($chunk);
        } else {
            file_put_contents($chunk, 'x');
            file_put_contents($final, 'tiny');
        }
        file_put_contents($dir . '/' . $id . '.meta.json', (string) json_encode([
            'parts' => 1,
            'expected' => [1 => 1],
            'received' => [1 => 1],
            'assembling' => true,
            'total_chunks' => 1,
            'current_part' => 1,
            'assembled' => [1 => 0],
            'bytes' => [1 => $fixture === 'short-staging' ? 99 : 0],
            'assembled_total' => 0,
        ]));
        try {
            $result = ChunkUpload::finalize($id);
            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertContains($result->get_error_code(), ['MUDRAVA_UPLOAD_CHUNK_MISSING', 'MUDRAVA_UPLOAD_STATE_INVALID']);
        } finally {
            is_dir($chunk) ? @rmdir($chunk) : @unlink($chunk);
            @unlink($final);
            $this->removeUpload($id);
        }
    }

    /** @return array<string,array{string}> */
    public static function damagedAssemblyCheckpointProvider(): array
    {
        return ['unreadable chunk' => ['chunk-directory'], 'short staging' => ['short-staging']];
    }

    public function testStatusHandlesLegacyLayoutAndDirectoryWithoutMetadata(): void
    {
        $id = 'legacy-' . bin2hex(random_bytes(5));
        $root = (new Paths())->uploadsDir();
        file_put_contents($root . '/' . $id . '.lock', '');
        file_put_contents($root . '/' . $id . '.meta.json', (string) json_encode([
            'parts' => 1, 'expected' => [1 => 2], 'received' => [1 => 1],
        ]));
        try {
            $legacy = ChunkUpload::status($id);
            self::assertIsArray($legacy);
            self::assertTrue($legacy['found']);
            self::assertSame([1 => 1], $legacy['received']);
        } finally {
            @unlink($root . '/' . $id . '.meta.json');
            @unlink($root . '/' . $id . '.lock');
        }

        $id = 'empty-' . bin2hex(random_bytes(5));
        $dir = $root . '/' . $id;
        mkdir($dir, 0700);
        file_put_contents($dir . '/' . $id . '.lock', '');
        try {
            self::assertSame(['found' => false], ChunkUpload::status($id));
        } finally {
            $this->removeUpload($id);
        }
    }

    public function testInvalidStagingNodesAndStaleOrphanCleanup(): void
    {
        $paths = new Paths();
        $root = $paths->uploadsDir();
        $id = 'node-' . bin2hex(random_bytes(5));
        file_put_contents($root . '/' . $id, 'not-a-directory');
        try {
            $result = ChunkUpload::finalize($id);
            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertSame('MUDRAVA_UPLOAD_STATE_INVALID', $result->get_error_code());
        } finally {
            unlink($root . '/' . $id);
        }

        $id = 'orphan-' . bin2hex(random_bytes(5));
        $dir = $root . '/' . $id;
        mkdir($dir, 0700);
        touch($dir, time() - DAY_IN_SECONDS - 10);
        ChunkUpload::cleanupStale();
        self::assertDirectoryDoesNotExist($dir);
    }

    public function testCleanupSkipsAStaleUploadWhoseLockIsHeld(): void
    {
        $id = 'held-' . bin2hex(random_bytes(5));
        $dir = (new Paths())->uploadsDir() . '/' . $id;
        mkdir($dir, 0700);
        $lockPath = $dir . '/' . $id . '.lock';
        file_put_contents($lockPath, '');
        touch($lockPath, time() - DAY_IN_SECONDS - 10);
        $handle = fopen($lockPath, 'c+');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        try {
            ChunkUpload::cleanupStale();
            self::assertFileExists($lockPath);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            @unlink($lockPath);
            @rmdir($dir);
        }
    }

    public function testFinalizeRejectsDirectoryUsedAsAssemblyFile(): void
    {
        $id = 'test-' . bin2hex(random_bytes(6));
        $paths = new Paths();
        $dir = $paths->uploadsDir() . '/' . $id;
        mkdir($dir, 0700);
        file_put_contents($dir . '/' . $id . '.lock', '');
        file_put_contents($dir . '/' . $id . '.p0001.part000000', 'chunk');
        mkdir($dir . '/' . $id . '.p0001.final');
        file_put_contents($dir . '/' . $id . '.meta.json', (string) json_encode([
            'parts' => 1,
            'expected' => [1 => 1],
            'received' => [1 => 1],
            'assembling' => true,
            'total_chunks' => 1,
            'current_part' => 1,
            'assembled' => [1 => 0],
            'bytes' => [1 => 0],
            'assembled_total' => 0,
        ]));
        try {
            $result = ChunkUpload::finalize($id);
            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame('MUDRAVA_UPLOAD_STATE_INVALID', $result->get_error_code());
        } finally {
            @rmdir($dir . '/' . $id . '.p0001.final');
            $this->removeUpload($id);
        }
    }

    public function testStatusReturnsInvalidStagingNodeError(): void
    {
        $id = 'status-' . bin2hex(random_bytes(5));
        $root = (new Paths())->uploadsDir();
        $outside = $GLOBALS['MUDRAVA_TEST_TMP'] . '/status-target-' . bin2hex(random_bytes(4));
        mkdir($outside, 0700);
        symlink($outside, $root . '/' . $id);
        try {
            $result = ChunkUpload::status($id);
            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame('MUDRAVA_UPLOAD_STATE_INVALID', $result->get_error_code());
        } finally {
            unlink($root . '/' . $id);
            rmdir($outside);
        }
    }

    public function testCleanupSkipsValidNamedOrdinaryFile(): void
    {
        $path = (new Paths())->uploadsDir() . '/ordinary-valid-id';
        file_put_contents($path, 'keep');
        try {
            ChunkUpload::cleanupStale();
            $this->assertFileExists($path);
        } finally {
            unlink($path);
        }
    }
}
