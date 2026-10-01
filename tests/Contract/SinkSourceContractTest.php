<?php

/**
 * Contract tests: LocalFileSink + SplitSetSource must satisfy the same
 * stream contract the engine relies on - write via FrameWriter, read back
 * via FrameReader through the source, byte-for-byte, across splits.
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
use Mudrava\Migration\Archive\Manifest;
use Mudrava\Migration\Compression\DeflateCodec;
use Mudrava\Migration\Storage\LocalFileSink;
use Mudrava\Migration\Storage\SplitSetSource;
use PHPUnit\Framework\TestCase;

final class SinkSourceContractTest extends TestCase
{
    /** @var string */
    private $tmp;

    protected function setUp(): void
    {
        $this->tmp = $GLOBALS['MUDRAVA_TEST_TMP'] . '/contract-' . uniqid();
        mkdir($this->tmp, 0777, true);
    }

    private function header(int $flags = 0): Header
    {
        return new Header(Header::CONTAINER_FORMAT, $flags, random_bytes(16), '1.0.0', 0, 0, 0, str_repeat("\0", 16), str_repeat("\0", 8));
    }

    public function testSingleFileRoundTripThroughSinkAndSource(): void
    {
        $base = $this->tmp . '/single.mudrava';
        $stream = $this->openWriter($base);
        $writer = new FrameWriter($stream, $this->header(), new DeflateCodec(), null);
        $writer->writeJson(FrameType::SITE_METADATA, ['site' => 'one']);
        $writer->writeFrame(FrameType::FILE_DATA, 'hello-contract');
        $writer->finalize();
        fclose($stream);

        $source = new SplitSetSource($base);
        $reader = new FrameReader($source->stream(), new DeflateCodec(), null, $source->nextPartProvider());
        $got = null;
        $manifest = null;
        while (($frame = $reader->next()) !== null) {
            if ($frame->type === FrameType::FILE_DATA) {
                $got = $frame->payload;
            }
            if ($frame->type === FrameType::MANIFEST) {
                $manifest = $frame->json();
            }
        }
        $source->close();
        $this->assertSame('hello-contract', $got);
        $this->assertNotNull($manifest);
        $reader->verifyAgainstManifest($manifest);
    }

    /**
     * Open a writer stream for a base path (part 1). Kept separate so the
     * contract test drives FrameWriter directly while the sink validates the
     * physical file layout.
     *
     * @return resource
     */
    private function openWriter(string $base)
    {
        $h = fopen($base, 'wb');
        $this->assertNotFalse($h);
        return $h;
    }

    public function testSplitSetSourceReassemblesParts(): void
    {
        $base = $this->tmp . '/set.mudrava';
        $header = $this->header(Header::FLAG_SPLIT_SET);
        $stream = $this->openWriter($base);
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), null, 1500, $base);
        $writer->writeJson(FrameType::SITE_METADATA, ['site' => 'contract']);
        $expected = [];
        for ($i = 0; $i < 30; $i++) {
            $payload = 'row-' . $i . '-' . random_bytes(200);
            $expected[] = $payload;
            $writer->writeFrame(FrameType::FILE_DATA, $payload);
        }
        $writer->finalize(['row_count' => 30]);
        fclose($stream);

        // The sink must be able to enumerate the same parts the source finds.
        $inspect = SplitSetSource::inspect($base);
        $this->assertGreaterThanOrEqual(2, $inspect['found']);
        $this->assertSame([], $inspect['missing']);

        $source = new SplitSetSource($base);
        $reader = new FrameReader($source->stream(), new DeflateCodec(), null, $source->nextPartProvider());
        $got = [];
        $manifest = null;
        while (($frame = $reader->next()) !== null) {
            if ($frame->type === FrameType::FILE_DATA) {
                $got[] = $frame->payload;
            }
            if ($frame->type === FrameType::MANIFEST) {
                $manifest = $frame->json();
            }
        }
        $source->close();
        $this->assertSame($expected, $got);
        $this->assertNotNull($manifest);
        $reader->verifyAgainstManifest($manifest);
        $this->assertTrue($reader->isEof());
    }

    public function testMissingPartReportedByInspect(): void
    {
        $base = $this->tmp . '/gap.mudrava';
        $stream = $this->openWriter($base);
        $writer = new FrameWriter($stream, $this->header(Header::FLAG_SPLIT_SET), new DeflateCodec(), null, 900, $base);
        $writer->writeJson(FrameType::SITE_METADATA, ['s' => 1]);
        for ($i = 0; $i < 30; $i++) {
            $writer->writeFrame(FrameType::FILE_DATA, random_bytes(300));
        }
        $writer->finalize();
        fclose($stream);

        $found = glob($base . '.part*');
        $this->assertNotEmpty($found);
        // Remove a middle part to create a gap.
        unlink($base . '.part0002');

        $inspect = SplitSetSource::inspect($base);
        // part0002 gone → found stops at 1, and the gap is reported.
        $this->assertSame(1, $inspect['found']);
        $this->assertContains(2, $inspect['missing']);
    }

    public function testSinkRotatePartWritesSeparateFiles(): void
    {
        $base = $this->tmp . '/rotate.mudrava';
        $sink = new LocalFileSink($base);
        $sink->open('X');
        $sink->append(str_repeat('a', 100));
        $sink->rotatePart();
        $sink->append(str_repeat('b', 100));
        $sink->close();

        $this->assertFileExists($base);
        $this->assertFileExists($base . '.part0002');
        $this->assertSame(str_repeat('a', 100), file_get_contents($base));
        $this->assertSame(str_repeat('b', 100), file_get_contents($base . '.part0002'));
    }

    public function testSinkLifecycleMethodsFlushFinalizeAndExposePath(): void
    {
        $base = $this->tmp . '/lifecycle.mudrava';
        $sink = new LocalFileSink($base);
        $this->assertSame($base, $sink->path());
        try {
            $sink->append('closed');
            $this->fail('closed sink must reject append');
        } catch (\LogicException $error) {
            $this->assertSame('Sink not open.', $error->getMessage());
        }

        $sink->open('ignored-id');
        $sink->append('payload');
        $sink->checkpoint(new \Mudrava\Migration\Archive\Checkpoint('running', 'files', 0, '0'));
        $sink->finalize(new Manifest([]));
        $sink->close();
        $this->assertSame('payload', file_get_contents($base));
    }

    public function testSinkReportsUnwritableFileAndPartLocations(): void
    {
        $asDirectory = $this->tmp . '/directory-target';
        mkdir($asDirectory);
        try {
            (new LocalFileSink($asDirectory))->open('x');
            $this->fail('directory cannot be opened as archive');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('cannot open', $error->getMessage());
        }

        $base = $this->tmp . '/rotation-target.mudrava';
        mkdir($base . '.part0002');
        $sink = new LocalFileSink($base);
        $sink->open('x');
        try {
            $sink->rotatePart();
            $this->fail('directory cannot be opened as next part');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('cannot open', $error->getMessage());
        }
    }

    public function testMissingSplitSetOperationsFailWithStableCodes(): void
    {
        $base = $this->tmp . '/missing.mudrava';
        $this->assertSame(['parts' => [], 'missing' => [], 'found' => 0], SplitSetSource::inspect($base));
        $this->assertFalse(SplitSetSource::tailHasFooter($base));

        foreach (['identity', 'assert'] as $operation) {
            try {
                if ($operation === 'identity') {
                    SplitSetSource::identity($base);
                } else {
                    SplitSetSource::assertSetReadable($base, 1);
                }
                $this->fail('missing archive must fail');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('MUDRAVA_PART_MISSING', $error->getMessage());
            }
        }

        $source = new SplitSetSource($base);
        try {
            $source->stream();
            $this->fail('missing part 1 must fail');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('MUDRAVA_PART_MISSING', $error->getMessage());
        }

        file_put_contents($base, 'short');
        $source = new SplitSetSource($base);
        $handle = $source->stream();
        $this->assertIsResource($handle);
        $provider = $source->nextPartProvider();
        try {
            $provider();
            $this->fail('missing next part must fail');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('part 0002', $error->getMessage());
            $this->assertTrue($source->eof());
        } finally {
            $source->close();
        }
    }
}
