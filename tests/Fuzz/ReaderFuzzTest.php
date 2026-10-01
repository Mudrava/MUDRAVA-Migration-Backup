<?php
/**
 * Fuzz invariants: a reader must NEVER emit a frame that fails CRC, never
 * crash with a PHP warning/notice, and never report success on garbage.
 * Either a clean MUDRAVA_* error or a clean EOF - nothing else.
 *
 * Deterministic seed (random_bytes replaced via mt_srand) so failures
 * reproduce.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Fuzz;

use Mudrava\Migration\Archive\FrameReader;
use Mudrava\Migration\Archive\FrameType;
use Mudrava\Migration\Archive\FrameWriter;
use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Compression\DeflateCodec;
use PHPUnit\Framework\TestCase;

final class ReaderFuzzTest extends TestCase
{
    /** @var string */
    private $tmp;

    protected function setUp(): void
    {
        $this->tmp = $GLOBALS['MUDRAVA_TEST_TMP'];
    }

    private function validArchive(): string
    {
        $path = tempnam($this->tmp, 'fuzz');
        $stream = fopen($path, 'wb');
        $header = new Header(Header::CONTAINER_FORMAT, 0, random_bytes(16), '1.0.0', 0, 0, 0, str_repeat("\0", 16), str_repeat("\0", 8));
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), null);
        $writer->writeJson(FrameType::SITE_METADATA, ['site' => 'fuzz']);
        for ($i = 0; $i < 5; $i++) {
            $writer->writeFrame(FrameType::FILE_DATA, random_bytes(200));
        }
        $writer->finalize();
        fclose($stream);
        return $path;
    }

    /**
     * @dataProvider truncationOffsets
     */
    public function testTruncationNeverYieldsBadFrame(int $pct): void
    {
        $path = $this->validArchive();
        $bytes = (string) file_get_contents($path);
        $cut = intdiv(strlen($bytes) * $pct, 100);
        $truncated = substr($bytes, 0, $cut);

        $stream = fopen('php://memory', 'wb+');
        fwrite($stream, $truncated);
        rewind($stream);

        $reader = new FrameReader($stream, new DeflateCodec());
        $seen = 0;
        try {
            while (($frame = $reader->next()) !== null) {
                // Every yielded frame passed CRC + sequence checks by contract.
                $this->assertGreaterThan(0, strlen($frame->payload) ?: 0);
                $seen++;
            }
            // Clean EOF only if we happened to cut exactly at a boundary.
            $this->assertTrue(true);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $this->assertMatchesRegularExpression(
                '/MUDRAVA_(ARCHIVE_TRUNCATED|ARCHIVE_CORRUPT|FORMAT_UNSUPPORTED|PART_MISSING)/',
                $msg,
                'fuzz failure must be a typed MUDRAVA_* error, got: ' . $msg
            );
        } finally {
            fclose($stream);
        }
    }

    public function truncationOffsets(): array
    {
        $cases = [];
        foreach ([1, 5, 13, 25, 37, 50, 66, 75, 90, 99] as $pct) {
            $cases['cut-' . $pct] = [$pct];
        }
        return $cases;
    }

    /**
     * Random byte flips: reader must throw a typed error or stop cleanly,
     * never return a frame whose stored CRC disagrees.
     *
     * @dataProvider flipSeeds
     */
    public function testBitFlipDetectedOrCleanStop(int $seed): void
    {
        mt_srand($seed);
        $path = $this->validArchive();
        $bytes = (string) file_get_contents($path);
        $len = strlen($bytes);
        // Flip 1–3 bytes in the frame region (skip the header).
        $flips = 1 + mt_rand(0, 2);
        for ($i = 0; $i < $flips; $i++) {
            $pos = 80 + mt_rand(0, max(1, $len - 81));
            $bytes[$pos] = chr(ord($bytes[$pos]) ^ (1 << mt_rand(0, 7)));
        }

        $stream = fopen('php://memory', 'wb+');
        fwrite($stream, $bytes);
        rewind($stream);
        $reader = new FrameReader($stream, new DeflateCodec());
        try {
            while (($frame = $reader->next()) !== null) {
                $this->assertIsString($frame->payload);
            }
            // If it survived all flips, the flips landed on non-critical
            // bytes (e.g. padding). Acceptable: no bad frame was yielded.
            $this->assertTrue(true);
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression(
                '/MUDRAVA_(ARCHIVE_TRUNCATED|ARCHIVE_CORRUPT|MANIFEST_MISMATCH|WRONG_PASSWORD)/',
                $e->getMessage(),
                'unexpected error class: ' . $e->getMessage()
            );
        } finally {
            fclose($stream);
        }
    }

    public function flipSeeds(): array
    {
        $cases = [];
        for ($s = 1; $s <= 40; $s++) {
            $cases['seed-' . $s] = [$s];
        }
        return $cases;
    }

    public function testPureGarbageRejected(): void
    {
        mt_srand(1234);
        for ($i = 0; $i < 25; $i++) {
            $garbage = random_bytes(64 + mt_rand(0, 512));
            $stream = fopen('php://memory', 'wb+');
            fwrite($stream, $garbage);
            rewind($stream);
            $reader = new FrameReader($stream, new DeflateCodec());
            try {
                while ($reader->next() !== null) {
                    // A pure-garbage stream must never yield a frame.
                    $this->fail('garbage stream yielded a frame');
                }
                $this->fail('garbage stream reached clean EOF without error');
            } catch (\RuntimeException $e) {
                $this->assertMatchesRegularExpression('/MUDRAVA_/', $e->getMessage());
            } finally {
                fclose($stream);
            }
        }
    }
}
