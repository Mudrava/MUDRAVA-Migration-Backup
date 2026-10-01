<?php
/**
 * Golden archive stability: the committed fixture must decode to exactly the
 * expected frames forever. If this test fails, the on-disk format changed -
 * which requires an ADR and a container_format bump, never a silent edit.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\GoldenArchives;

use Mudrava\Migration\Archive\FrameReader;
use Mudrava\Migration\Archive\FrameType;
use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Compression\DeflateCodec;
use Mudrava\Migration\Database\RowCodec;
use PHPUnit\Framework\TestCase;

final class GoldenArchiveTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/golden_v1.golden';

    /** Expected sha256 of the frozen fixture. */
    private const SHA256 = 'a5d3c5257cd8ddce805714fafdf15b6d25d6a0cefa42af35977206ac30bc8d2d';

    public function testFixtureUnchanged(): void
    {
        if (!is_file(self::FIXTURE)) {
            $this->fail('golden fixture missing; run tests/GoldenArchives/regenerate.php');
        }
        if (self::SHA256 === 'REPLACE_AFTER_REGENERATE') {
            $this->fail('golden sha256 placeholder; regenerate then pin the hash');
        }
        $this->assertSame(self::SHA256, hash_file('sha256', self::FIXTURE), 'golden fixture bytes changed');
    }

    public function testFixtureDecodesToExpectedFrames(): void
    {
        if (!is_file(self::FIXTURE)) {
            $this->markTestSkipped('fixture not generated yet');
        }
        $stream = fopen(self::FIXTURE, 'rb');
        $reader = new FrameReader($stream, new DeflateCodec());

        $header = $reader->readHeader();
        $this->assertSame(Header::CONTAINER_FORMAT, $header->containerFormat);
        $this->assertSame('1.0.0', $header->producerVersion);
        $this->assertFalse($header->isEncrypted());

        $frames = [];
        $manifest = null;
        while (($frame = $reader->next()) !== null) {
            $frames[] = $frame;
            if ($frame->type === FrameType::MANIFEST) {
                $manifest = $frame->json();
            }
        }
        fclose($stream);

        $types = array_map(static fn ($f) => $f->type, $frames);
        $this->assertSame([
            FrameType::SITE_METADATA,
            FrameType::DB_TABLE_BEGIN,
            FrameType::DB_SCHEMA,
            FrameType::DB_ROWS,
            FrameType::DB_TABLE_END,
            FrameType::FILE_METADATA,
            FrameType::FILE_DATA,
            FrameType::MANIFEST,
            FrameType::FOOTER,
        ], $types);

        // Semantic spot checks against the frozen content.
        $meta = json_decode($frames[0]->payload, true);
        $this->assertSame('https://golden.example', $meta['site_url']);

        $rows = RowCodec::decode($frames[3]->payload);
        $this->assertCount(2, $rows);
        $this->assertSame('1', $rows[0][0]);
        $this->assertSame('https://golden.example', $rows[0][2]);
        $this->assertSame("\x00\x01\xff binary", $rows[1][2], 'binary cell must survive the format');

        $this->assertSame("<?php // golden\n", $frames[6]->payload);

        $this->assertNotNull($manifest);
        $reader->verifyAgainstManifest($manifest);
        $this->assertTrue($reader->isEof());
    }
}
