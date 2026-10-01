<?php

/**
 * Export tick boundary and recovery failure contracts.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Archive\Checkpoint;
use Mudrava\Migration\Archive\FrameReader;
use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Compression\DeflateCodec;
use Mudrava\Migration\Migration\ArchiveVerifier;
use Mudrava\Migration\Migration\ExportTick;
use Mudrava\Migration\Tests\Support\ArrayDatabaseSource;
use Mudrava\Migration\Tests\Support\ArrayFileInventory;
use PHPUnit\Framework\TestCase;

final class ExportTickBoundaryTest extends TestCase
{
    /** @return callable():never */
    private function unusedFactory(): callable
    {
        return static function () {
            throw new \LogicException('factory must not be called');
        };
    }

    public function testFreshTickRequiresHeaderBeforeOpeningDestination(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('requires a header');
        ExportTick::run(
            $GLOBALS['MUDRAVA_TEST_TMP'] . '/unused.mudrava',
            null,
            $this->unusedFactory(),
            $this->unusedFactory(),
            [],
            null,
            static function (): void {
            },
            null,
            microtime(true)
        );
    }

    public function testFreshTickReportsUncreatableArchiveDestination(): void
    {
        $base = $GLOBALS['MUDRAVA_TEST_TMP'] . '/missing-' . bin2hex(random_bytes(4)) . '/site.mudrava';
        $header = new \Mudrava\Migration\Archive\Header(
            1,
            0,
            random_bytes(16),
            'test',
            0,
            0,
            0,
            str_repeat("\0", 16),
            str_repeat("\0", 8)
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MUDRAVA_PERMISSION_DENIED');
        ExportTick::run(
            $base,
            null,
            $this->unusedFactory(),
            $this->unusedFactory(),
            [],
            $header,
            static function (): void {
            },
            null,
            microtime(true)
        );
    }

    public function testTruncateRemovesUncommittedBytesAndLaterParts(): void
    {
        $base = $GLOBALS['MUDRAVA_TEST_TMP'] . '/truncate-' . bin2hex(random_bytes(4)) . '.mudrava';
        file_put_contents($base, '1234567890');
        file_put_contents($base . '.part0002', 'orphan');
        file_put_contents($base . '.part0003', 'orphan');
        $checkpoint = new Checkpoint('files', 'file_stream', 1, '0', [], '4', 1);

        ExportTick::truncateToCheckpoint($base, $checkpoint);

        $this->assertSame('1234', file_get_contents($base));
        $this->assertFileDoesNotExist($base . '.part0002');
        $this->assertFileDoesNotExist($base . '.part0003');
    }

    public function testScanSetReportsMissingFirstPart(): void
    {
        $base = $GLOBALS['MUDRAVA_TEST_TMP'] . '/absent-' . bin2hex(random_bytes(4)) . '.mudrava';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MUDRAVA_PART_MISSING');
        ExportTick::scanSet($base, 1);
    }

    public function testTruncateRejectsPartShorterThanDurableCheckpoint(): void
    {
        $base = $GLOBALS['MUDRAVA_TEST_TMP'] . '/short-' . bin2hex(random_bytes(4)) . '.mudrava';
        file_put_contents($base, '123');
        $checkpoint = new Checkpoint('files', 'file_stream', 1, '0', [], '4', 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MUDRAVA_ARCHIVE_CHANGED');
        ExportTick::truncateToCheckpoint($base, $checkpoint);
    }

    public function testResumeRejectsCheckpointThatDoesNotMatchSurvivingFrames(): void
    {
        $base = $GLOBALS['MUDRAVA_TEST_TMP'] . '/hash-' . bin2hex(random_bytes(4)) . '.mudrava';
        $db = static function (): ArrayDatabaseSource {
            return new ArrayDatabaseSource([]);
        };
        $files = static function (): ArrayFileInventory {
            return new ArrayFileInventory([]);
        };
        $cipher = static function (): ?\Mudrava\Migration\Crypto\FrameCipher {
            return null;
        };
        $header = new Header(1, 0, random_bytes(16), 'test', 0, 0, 0, str_repeat("\0", 16), str_repeat("\0", 8));
        $first = ExportTick::run($base, null, $db, $files, [], $header, $cipher, null, microtime(true) - 1);
        $this->assertNotNull($first['checkpoint']);
        $altered = $first['checkpoint'];
        $altered['root_hash'] = str_repeat('0', 64);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MUDRAVA_ARCHIVE_CHANGED');
        ExportTick::run($base, $altered, $db, $files, [], null, $cipher, null, microtime(true) - 1);
    }

    public function testFastResumeDefersOldPayloadScanButFinalVerificationRejectsCorruption(): void
    {
        $base = $GLOBALS['MUDRAVA_TEST_TMP'] . '/deferred-' . bin2hex(random_bytes(4)) . '.mudrava';
        $db = static function (): ArrayDatabaseSource {
            return new ArrayDatabaseSource([]);
        };
        $files = static function (): ArrayFileInventory {
            return new ArrayFileInventory([]);
        };
        $cipher = static function (): ?\Mudrava\Migration\Crypto\FrameCipher {
            return null;
        };
        $header = new Header(1, 0, random_bytes(16), 'test', 0, 0, 0, str_repeat("\0", 16), str_repeat("\0", 8));
        $first = ExportTick::run($base, null, $db, $files, ['site_url' => 'https://example.test'], $header, $cipher, null, microtime(true) - 1);
        $this->assertNotNull($first['checkpoint']);
        $this->assertArrayHasKey('writer_state', (array) $first['checkpoint']['cursor']);

        $handle = fopen($base, 'r+b');
        $this->assertIsResource($handle);
        fseek($handle, strlen($header->encode()) + 26);
        fwrite($handle, 'X');
        fclose($handle);

        $checkpoint = $first['checkpoint'];
        for ($i = 0; $i < 10; $i++) {
            $next = ExportTick::run($base, $checkpoint, $db, $files, [], null, $cipher, null, microtime(true) + 1);
            if ($next['state'] === 'done') {
                break;
            }
            $checkpoint = $next['checkpoint'];
        }
        $this->assertSame('done', $next['state']);
        $input = fopen($base, 'rb');
        $this->assertIsResource($input);
        try {
            $this->expectException(\RuntimeException::class);
            (new ArchiveVerifier(new FrameReader($input, new DeflateCodec())))->step(microtime(true) + 10);
        } finally {
            fclose($input);
        }
    }

    public function testLegacyCheckpointStillScansExistingFrames(): void
    {
        $base = $GLOBALS['MUDRAVA_TEST_TMP'] . '/legacy-' . bin2hex(random_bytes(4)) . '.mudrava';
        $db = static function (): ArrayDatabaseSource {
            return new ArrayDatabaseSource([]);
        };
        $files = static function (): ArrayFileInventory {
            return new ArrayFileInventory([]);
        };
        $cipher = static function (): ?\Mudrava\Migration\Crypto\FrameCipher {
            return null;
        };
        $header = new Header(1, 0, random_bytes(16), 'test', 0, 0, 0, str_repeat("\0", 16), str_repeat("\0", 8));
        $first = ExportTick::run($base, null, $db, $files, [], $header, $cipher, null, microtime(true) - 1);
        $legacy = $first['checkpoint'];
        $cursor = (array) $legacy['cursor'];
        unset($cursor['writer_state']);
        $legacy['cursor'] = $cursor;

        $handle = fopen($base, 'r+b');
        $this->assertIsResource($handle);
        fseek($handle, strlen($header->encode()) + 26);
        fwrite($handle, 'X');
        fclose($handle);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MUDRAVA_ARCHIVE_CHANGED');
        ExportTick::run($base, $legacy, $db, $files, [], null, $cipher, null, microtime(true) - 1);
    }

    public function testLegacyResumeProducesAUsableFastCheckpoint(): void
    {
        $base = $GLOBALS['MUDRAVA_TEST_TMP'] . '/legacy-clean-' . bin2hex(random_bytes(4)) . '.mudrava';
        $db = static function (): ArrayDatabaseSource {
            return new ArrayDatabaseSource([]);
        };
        $files = static function (): ArrayFileInventory {
            return new ArrayFileInventory([]);
        };
        $cipher = static function (): ?\Mudrava\Migration\Crypto\FrameCipher {
            return null;
        };
        $header = new Header(1, 0, random_bytes(16), 'test', 0, 0, 0, str_repeat("\0", 16), str_repeat("\0", 8));
        $first = ExportTick::run($base, null, $db, $files, [], $header, $cipher, null, microtime(true) - 1);
        $legacy = $first['checkpoint'];
        $cursor = (array) $legacy['cursor'];
        unset($cursor['writer_state']);
        $legacy['cursor'] = $cursor;

        $second = ExportTick::run($base, $legacy, $db, $files, [], null, $cipher, null, microtime(true) - 1);
        $this->assertArrayHasKey('writer_state', (array) $second['checkpoint']['cursor']);
        $third = ExportTick::run($base, $second['checkpoint'], $db, $files, [], null, $cipher, null, microtime(true) - 1);
        $this->assertSame('done', $third['state']);
    }
}
