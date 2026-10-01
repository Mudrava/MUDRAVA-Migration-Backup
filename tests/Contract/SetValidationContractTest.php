<?php

/**
 * The restore gate: an incomplete or mis-slotted split set must be
 * refused BEFORE the first destructive tick. assertSetReadable is the
 * server-side promise that PART_MISSING can never first surface in the
 * middle of a half-restored site; FrameReader continuity is the same
 * promise enforced at the frame level for anything that slips past the
 * file gate (concatenated streams, hand-spliced sets).
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
use Mudrava\Migration\Migration\JobRunner;
use Mudrava\Migration\Storage\SplitSetSource;
use PHPUnit\Framework\TestCase;

final class SetValidationContractTest extends TestCase
{
    /** @var string */
    private $tmp;

    protected function setUp(): void
    {
        $this->tmp = $GLOBALS['MUDRAVA_TEST_TMP'] . '/setvalid-' . uniqid();
        mkdir($this->tmp, 0777, true);
    }

    private function header(int $flags = 0, ?string $uuid = null): Header
    {
        return new Header(
            Header::CONTAINER_FORMAT,
            $flags,
            $uuid ?? random_bytes(16),
            '1.0.0',
            0,
            0,
            0,
            str_repeat("\0", 16),
            str_repeat("\0", 8)
        );
    }

    /**
     * Write a real split set with the given part-1 flags.
     *
     * @return array{header:Header,found:int}
     */
    private function writeSplitSet(string $base, int $splitBytes, int $rows, int $flags): array
    {
        $header = $this->header($flags);
        $stream = fopen($base, 'wb');
        $this->assertNotFalse($stream);
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), null, $splitBytes, $base);
        $writer->writeJson(FrameType::SITE_METADATA, ['site' => 'gate']);
        for ($i = 0; $i < $rows; $i++) {
            $writer->writeFrame(FrameType::FILE_DATA, 'row-' . $i . '-' . random_bytes(180));
        }
        $writer->finalize();
        $this->assertGreaterThan(1, $writer->currentPart(), 'fixture must produce several parts');
        fclose($stream);
        $inspect = SplitSetSource::inspect($base);
        return ['header' => $header, 'found' => $inspect['found']];
    }

    public function testCompleteSetPassesGate(): void
    {
        $base = $this->tmp . '/good.mudrava';
        $set = $this->writeSplitSet($base, 1200, 30, Header::FLAG_SPLIT_SET);
        $inspect = SplitSetSource::assertSetReadable($base, $set['found']);
        $this->assertSame($set['found'], $inspect['found']);
        $this->assertSame([], $inspect['missing']);
    }

    public function testSingleFileWithFooterPasses(): void
    {
        $base = $this->tmp . '/single.mudrava';
        $stream = fopen($base, 'wb');
        $writer = new FrameWriter($stream, $this->header(), new DeflateCodec(), null);
        $writer->writeJson(FrameType::SITE_METADATA, ['s' => 1]);
        $writer->finalize();
        fclose($stream);
        $inspect = SplitSetSource::assertSetReadable($base);
        $this->assertSame(1, $inspect['found']);
    }

    public function testLonePart1OfSplitSetIsRefused(): void
    {
        $base = $this->tmp . '/lone.mudrava';
        $this->writeSplitSet($base, 900, 24, Header::FLAG_SPLIT_SET);
        $found = glob($base . '.part*');
        $this->assertNotEmpty($found);
        foreach ($found as $part) {
            unlink($part);
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/MUDRAVA_PART_MISSING: part 0002 of lone\.mudrava/');
        SplitSetSource::assertSetReadable($base);
    }

    public function testHoleInSetNamesTheMissingPart(): void
    {
        $base = $this->tmp . '/gap.mudrava';
        $this->writeSplitSet($base, 1100, 30, Header::FLAG_SPLIT_SET);
        unlink($base . '.part0002');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/MUDRAVA_PART_MISSING: part 0002 .*holes in the uploaded set: 0002/');
        SplitSetSource::assertSetReadable($base);
    }

    public function testTruncatedLastPartHasNoFooterAndIsRefused(): void
    {
        $base = $this->tmp . '/trunc.mudrava';
        $this->writeSplitSet($base, 1000, 26, Header::FLAG_SPLIT_SET);
        $parts = glob($base . '.part*');
        $last = (string) end($parts);
        $bytes = (string) file_get_contents($last);
        file_put_contents($last, substr($bytes, 0, -4));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no end-of-archive marker/');
        SplitSetSource::assertSetReadable($base);
    }

    public function testExpectedPartsAboveStoredSetIsRefused(): void
    {
        $base = $this->tmp . '/expected.mudrava';
        $stream = fopen($base, 'wb');
        $writer = new FrameWriter($stream, $this->header(), new DeflateCodec(), null);
        $writer->writeJson(FrameType::SITE_METADATA, ['s' => 1]);
        $writer->finalize();
        fclose($stream);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/MUDRAVA_PART_MISSING: part 0002 .*expects 3 parts/');
        SplitSetSource::assertSetReadable($base, 3);
    }

    /**
     * Forge a part-2 file claiming the wrong slot or archive, then expect
     * the gate to reject it naming the mismatch. The tail magic is present
     * on purpose: completeness passes, so the rejection MUST come from the
     * header checks - proving those checks actually run.
     */
    private function forgePart(string $base, int $slot, string $cont): void
    {
        $path = sprintf('%s.part%04d', $base, $slot);
        file_put_contents($path, $cont . random_bytes(64) . Header::MAGIC_TAIL);
    }

    public function testForeignContinuationUuidIsRefused(): void
    {
        $base = $this->tmp . '/foreign.mudrava';
        $stream = fopen($base, 'wb');
        $writer = new FrameWriter($stream, $this->header(Header::FLAG_SPLIT_SET), new DeflateCodec(), null);
        $writer->writeJson(FrameType::SITE_METADATA, ['s' => 1]);
        $writer->finalize();
        fclose($stream);
        $this->forgePart($base, 2, Header::encodeContinuation(random_bytes(16), 2));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/MUDRAVA_PART_MISMATCH: wrong archive UUID in part 0002/');
        SplitSetSource::assertSetReadable($base);
    }

    public function testMisSlotContinuationIsRefusedByGate(): void
    {
        $base = $this->tmp . '/misslot.mudrava';
        $header = $this->header(Header::FLAG_SPLIT_SET);
        $stream = fopen($base, 'wb');
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), null);
        $writer->writeJson(FrameType::SITE_METADATA, ['s' => 1]);
        $writer->finalize();
        fclose($stream);
        $this->forgePart($base, 2, Header::encodeContinuation($header->archiveUuid, 3));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/MUDRAVA_PART_MISMATCH: expected part 0002, got 0003/');
        SplitSetSource::assertSetReadable($base);
    }

    public function testFrameReaderRefusesSkippedSlotAtRotation(): void
    {
        $base = $this->tmp . '/rotation.mudrava';
        $header = $this->header(Header::FLAG_SPLIT_SET);
        $stream = fopen($base, 'wb');
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), null, 700, $base);
        $writer->writeJson(FrameType::SITE_METADATA, ['s' => 1]);
        for ($i = 0; $i < 14; $i++) {
            $writer->writeFrame(FrameType::FILE_DATA, random_bytes(260));
        }
        $writer->finalize();
        fclose($stream);
        // Replace the real part 2 with a continuation claiming part 0003.
        $real = (string) file_get_contents($base . '.part0002');
        $offset = 28; // real continuation header size
        file_put_contents($base . '.part0002', Header::encodeContinuation($header->archiveUuid, 3) . substr($real, $offset));

        $source = new SplitSetSource($base);
        $reader = new FrameReader($source->stream(), new DeflateCodec(), null, $source->nextPartProvider());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/MUDRAVA_PART_MISMATCH: expected part 0002, got 0003/');
        while ($reader->next() !== null) {
            // Drain until the forged rotation boundary explodes.
        }
    }

    public function testSafeErrorKeepsPartDetailButNeverPaths(): void
    {
        $this->assertSame(
            'MUDRAVA_PART_MISSING: part 0003 of set.mudrava',
            JobRunner::safeError(new \RuntimeException('MUDRAVA_PART_MISSING: part 0003 of set.mudrava'))
        );
        $this->assertSame(
            'MUDRAVA_PART_MISMATCH: expected part 0002, got 0005',
            JobRunner::safeError(new \RuntimeException('MUDRAVA_PART_MISMATCH: expected part 0002, got 0005'))
        );
        // A path-bearing detail keeps only the code: the path never travels.
        $this->assertSame(
            'MUDRAVA_PERMISSION_DENIED',
            JobRunner::safeError(new \RuntimeException('MUDRAVA_PERMISSION_DENIED: /var/www/secret/wp-content/x'))
        );
        $this->assertSame(
            'MUDRAVA_INTERNAL',
            JobRunner::safeError(new \RuntimeException('MUDRAVA_PERMISSION_DENIED password=private-secret'))
        );
        $this->assertSame('MUDRAVA_INTERNAL', JobRunner::safeError(new \RuntimeException('db exploded')));
    }
}
