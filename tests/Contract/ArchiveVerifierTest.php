<?php

/**
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
use Mudrava\Migration\Database\RowCodec;
use Mudrava\Migration\Migration\ArchiveVerifier;
use PHPUnit\Framework\TestCase;

final class ArchiveVerifierTest extends TestCase
{
    private function archive(): string
    {
        $path = $GLOBALS['MUDRAVA_TEST_TMP'] . '/verify-' . uniqid() . '.mudrava';
        $stream = fopen($path, 'wb');
        $header = new Header(
            Header::CONTAINER_FORMAT,
            0,
            random_bytes(16),
            'test',
            0,
            0,
            0,
            str_repeat("\0", 16),
            str_repeat("\0", 8)
        );
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), null);
        $writer->writeJson(FrameType::SITE_METADATA, ['site_url' => 'https://source.test']);
        $writer->writeJson(FrameType::FILE_METADATA, ['path' => 'wp-content/test.txt', 'size' => 5]);
        $writer->writeFrame(FrameType::FILE_DATA, 'hello');
        $writer->finalize(['file_count' => 1, 'row_count' => 0]);
        fclose($stream);
        return $path;
    }

    public function testVerificationResumesAcrossFrameBoundaries(): void
    {
        $path = $this->archive();
        $checkpoint = null;
        $ticks = 0;
        do {
            $stream = fopen($path, 'rb');
            $reader = new FrameReader($stream, new DeflateCodec());
            $verifier = new ArchiveVerifier($reader, $checkpoint);
            $done = $verifier->step(microtime(true) - 1);
            $checkpoint = $done ? null : $verifier->checkpoint();
            fclose($stream);
            $ticks++;
            $this->assertLessThan(20, $ticks);
        } while (!$done);
        $this->assertGreaterThan(1, $ticks);
    }

    public function testCorruptedArchiveFailsVerification(): void
    {
        $path = $this->archive();
        $stream = fopen($path, 'r+b');
        fseek($stream, -9, SEEK_END);
        fwrite($stream, "X");
        fclose($stream);

        $stream = fopen($path, 'rb');
        try {
            $verifier = new ArchiveVerifier(new FrameReader($stream, new DeflateCodec()));
            $this->expectException(\RuntimeException::class);
            $verifier->step(microtime(true) + 10);
        } finally {
            fclose($stream);
        }
    }

    public function testDestinationValidationRunsBeforeArchiveIsAccepted(): void
    {
        $path = $this->archive();
        $seen = [];
        $stream = fopen($path, 'rb');
        try {
            $verifier = new ArchiveVerifier(
                new FrameReader($stream, new DeflateCodec()),
                null,
                static function (string $relative, bool $symlink) use (&$seen): void {
                    $seen[] = [$relative, $symlink];
                    throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: test destination');
                }
            );
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('MUDRAVA_PERMISSION_DENIED');
            $verifier->step(microtime(true) + 10);
        } finally {
            fclose($stream);
            self::assertSame([['wp-content/test.txt', false]], $seen);
        }
    }

    /** @dataProvider mismatchedLengths */
    public function testValidFrameHashesCannotHideWrongFileLength(int $declared, string $body): void
    {
        $path = $GLOBALS['MUDRAVA_TEST_TMP'] . '/wrong-size-' . uniqid() . '.mudrava';
        $stream = fopen($path, 'wb');
        $header = new Header(
            Header::CONTAINER_FORMAT,
            0,
            random_bytes(16),
            'test',
            0,
            0,
            0,
            str_repeat("\0", 16),
            str_repeat("\0", 8)
        );
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), null);
        $writer->writeJson(FrameType::SITE_METADATA, ['site_url' => 'https://source.test']);
        $writer->writeJson(FrameType::FILE_METADATA, ['path' => 'wp-content/test.txt', 'size' => $declared]);
        $writer->writeFrame(FrameType::FILE_DATA, $body);
        $writer->finalize(['file_count' => 1, 'row_count' => 0]);
        fclose($stream);

        $input = fopen($path, 'rb');
        try {
            $verifier = new ArchiveVerifier(new FrameReader($input, new DeflateCodec()));
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('MUDRAVA_ARCHIVE_CORRUPT');
            $verifier->step(microtime(true) + 10);
        } finally {
            fclose($input);
        }
    }

    /** @return array<string,array{int,string}> */
    public static function mismatchedLengths(): array
    {
        return ['short' => [5, 'four'], 'long' => [3, 'four']];
    }

    /**
     * @param list<array{int,string}> $frames
     * @dataProvider invalidStructures
     */
    public function testRejectsStructurallyInvalidArchives(array $frames, string $message): void
    {
        $path = $GLOBALS['MUDRAVA_TEST_TMP'] . '/invalid-structure-' . uniqid() . '.mudrava';
        $stream = fopen($path, 'wb');
        $header = new Header(
            Header::CONTAINER_FORMAT,
            0,
            random_bytes(16),
            'test',
            0,
            0,
            0,
            str_repeat("\0", 16),
            str_repeat("\0", 8)
        );
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), null);
        foreach ($frames as [$type, $payload]) {
            $writer->writeFrame($type, $payload);
        }
        $writer->finalize();
        fclose($stream);

        $input = fopen($path, 'rb');
        try {
            $verifier = new ArchiveVerifier(new FrameReader($input, new DeflateCodec()));
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage($message);
            $verifier->step(microtime(true) + 10);
        } finally {
            fclose($input);
        }
    }

    /** @return array<string,array{list<array{int,string}>,string}> */
    public static function invalidStructures(): array
    {
        $site = [FrameType::SITE_METADATA, '{}'];
        $begin = [FrameType::DB_TABLE_BEGIN, '{"table":"wp_demo","columns":["id","name"]}'];
        $schema = [FrameType::DB_SCHEMA, 'CREATE TABLE `wp_demo` (`id` bigint, `name` text)'];
        return [
            'site metadata missing' => [[[FrameType::CHECKPOINT, '{}']], 'site metadata missing'],
            'invalid site json' => [[[FrameType::SITE_METADATA, 'x']], 'invalid site metadata'],
            'duplicate site metadata' => [[$site, $site], 'invalid site metadata'],
            'bad table json' => [[$site, [FrameType::DB_TABLE_BEGIN, 'x']], 'invalid table metadata'],
            'unsafe table' => [[$site, [FrameType::DB_TABLE_BEGIN, '{"table":"wp;bad","columns":["id"]}']], 'invalid table metadata'],
            'empty columns' => [[$site, [FrameType::DB_TABLE_BEGIN, '{"table":"wp_demo","columns":[]}']], 'invalid table metadata'],
            'unsafe column' => [[$site, [FrameType::DB_TABLE_BEGIN, '{"table":"wp_demo","columns":["id;bad"]}']], 'invalid column name'],
            'schema outside table' => [[$site, $schema], 'invalid table schema'],
            'schema table mismatch' => [[$site, $begin, [FrameType::DB_SCHEMA, 'CREATE TABLE `wp_other` (`id` bigint)']], 'invalid table schema'],
            'rows before schema' => [[$site, $begin, [FrameType::DB_ROWS, RowCodec::encode([['1', 'x']])]], 'rows outside table'],
            'row width mismatch' => [[$site, $begin, $schema, [FrameType::DB_ROWS, RowCodec::encode([['1']])]], 'row column count mismatch'],
            'wrong table end' => [[$site, $begin, $schema, [FrameType::DB_TABLE_END, '{"table":"wp_other"}']], 'invalid table end'],
            'wrong cumulative row count' => [[$site, $begin, $schema, [FrameType::DB_ROWS, RowCodec::encode([['1', 'x']])], [FrameType::DB_TABLE_END, '{"table":"wp_demo","row_count":0}']], 'invalid table end'],
            'file metadata inside table' => [[$site, $begin, $schema, [FrameType::FILE_METADATA, '{"path":"a.txt"}']], 'invalid file metadata'],
            'negative file size' => [[$site, [FrameType::FILE_METADATA, '{"path":"a.txt","size":-1}']], 'invalid file size'],
            'unsafe symlink' => [[$site, [FrameType::FILE_METADATA, '{"path":"link","type":"symlink","target":"../../etc/passwd"}']], 'invalid symlink target'],
            'orphan file data' => [[$site, [FrameType::FILE_DATA, 'x']], 'file data without metadata'],
            'footer without manifest' => [[$site, [FrameType::FOOTER, '{}']], 'invalid footer'],
            'duplicate manifest' => [[$site, [FrameType::MANIFEST, '{}'], [FrameType::MANIFEST, '{}']], 'invalid manifest'],
            'frame after malformed footer' => [[$site, [FrameType::MANIFEST, '{}'], [FrameType::FOOTER, '{}'], [0x80, '']], 'invalid footer'],
            'unknown required frame' => [[$site, [0x55, '']], 'MUDRAVA_FORMAT_UNSUPPORTED'],
        ];
    }

    public function testUnknownOptionalFrameIsSkipped(): void
    {
        $path = $GLOBALS['MUDRAVA_TEST_TMP'] . '/optional-' . uniqid() . '.mudrava';
        $stream = fopen($path, 'wb');
        $header = new Header(
            Header::CONTAINER_FORMAT,
            0,
            random_bytes(16),
            'test',
            0,
            0,
            0,
            str_repeat("\0", 16),
            str_repeat("\0", 8)
        );
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), null);
        $writer->writeJson(FrameType::SITE_METADATA, []);
        $writer->writeFrame(0x80, 'future extension');
        $writer->finalize();
        fclose($stream);

        $input = fopen($path, 'rb');
        try {
            $this->assertTrue((new ArchiveVerifier(new FrameReader($input, new DeflateCodec())))
                ->step(microtime(true) + 10));
        } finally {
            fclose($input);
        }
    }

    public function testValidTableEndClosesTheVerifiedTable(): void
    {
        $path = $GLOBALS['MUDRAVA_TEST_TMP'] . '/verified-table-' . uniqid() . '.mudrava';
        $stream = fopen($path, 'wb');
        $header = new Header(1, 0, random_bytes(16), 'test', 0, 0, 0,
            str_repeat("\0", 16), str_repeat("\0", 8));
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), null);
        $writer->writeJson(FrameType::SITE_METADATA, []);
        $writer->writeJson(FrameType::DB_TABLE_BEGIN, ['table' => 'wp_demo', 'columns' => ['id']]);
        $writer->writeFrame(FrameType::DB_SCHEMA, 'CREATE TABLE `wp_demo` (`id` bigint)');
        $writer->writeJson(FrameType::DB_TABLE_END, ['table' => 'wp_demo']);
        $writer->finalize();
        fclose($stream);

        $input = fopen($path, 'rb');
        try {
            $this->assertTrue((new ArchiveVerifier(new FrameReader($input, new DeflateCodec())))
                ->step(microtime(true) + 10));
        } finally {
            fclose($input);
        }
    }

    public function testManifestWithoutFooterIsIncomplete(): void
    {
        $stream = fopen('php://memory', 'w+b');
        $header = new Header(1, 0, random_bytes(16), 'test', 0, 0, 0,
            str_repeat("\0", 16), str_repeat("\0", 8));
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), null);
        $writer->writeJson(FrameType::SITE_METADATA, []);
        $writer->writeJson(FrameType::MANIFEST, []);
        $writer->flush();
        fwrite($stream, Header::MAGIC_TAIL);
        rewind($stream);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('incomplete archive structure');
        (new ArchiveVerifier(new FrameReader($stream, new DeflateCodec())))->step(microtime(true) + 10);
    }

    /** @dataProvider invalidFooterFields */
    public function testRejectsFooterThatDisagreesWithVerifiedFrames(string $field, $value): void
    {
        $stream = fopen('php://memory', 'w+b');
        $header = new Header(1, 0, random_bytes(16), 'test', 0, 0, 0,
            str_repeat("\0", 16), str_repeat("\0", 8));
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), null);
        $writer->writeJson(FrameType::SITE_METADATA, []);
        $writer->writeJson(FrameType::MANIFEST, [
            'frame_count' => $writer->sequence() + 1,
            'root_hash' => $writer->rootHashHex(),
        ]);
        $footer = [
            'manifest_seq' => $writer->sequence(),
            'total_frames' => $writer->sequence() + 1,
            'logical_bytes' => (string) $writer->logicalBytesEstimate(),
            'parts_expected' => 1,
        ];
        $footer[$field] = $value;
        $writer->writeJson(FrameType::FOOTER, $footer);
        $writer->flush();
        fwrite($stream, Header::MAGIC_TAIL);
        rewind($stream);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('MUDRAVA_ARCHIVE_CORRUPT: invalid footer');
            (new ArchiveVerifier(new FrameReader($stream, new DeflateCodec())))->step(microtime(true) + 10);
        } finally {
            fclose($stream);
        }
    }

    /** @return array<string,array{string,int|string|null}> */
    public static function invalidFooterFields(): array
    {
        return [
            'wrong manifest sequence' => ['manifest_seq', 1],
            'wrong frame count' => ['total_frames', 1],
            'wrong byte count' => ['logical_bytes', '0'],
            'wrong part count' => ['parts_expected', 2],
            'missing required value' => ['logical_bytes', null],
        ];
    }

    public function testValidFooterCannotHideAFrameAfterIt(): void
    {
        $stream = fopen('php://memory', 'w+b');
        $header = new Header(
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
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), null);
        $writer->writeJson(FrameType::SITE_METADATA, []);
        $writer->writeJson(FrameType::MANIFEST, [
            'frame_count' => $writer->sequence() + 1,
            'root_hash' => $writer->rootHashHex(),
        ]);
        $writer->writeJson(FrameType::FOOTER, [
            'manifest_seq' => $writer->sequence(),
            'total_frames' => $writer->sequence() + 1,
            'logical_bytes' => (string) $writer->logicalBytesEstimate(),
            'parts_expected' => 1,
        ]);
        $writer->writeFrame(0x80, 'extra');
        $writer->flush();
        fwrite($stream, Header::MAGIC_TAIL);
        rewind($stream);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('frame after footer');
            (new ArchiveVerifier(new FrameReader($stream, new DeflateCodec())))->step(microtime(true) + 10);
        } finally {
            fclose($stream);
        }
    }

    public function testAcceptsLegacyFooterFrameCount(): void
    {
        $stream = fopen('php://memory', 'w+b');
        $header = new Header(
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
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), null);
        $writer->writeJson(FrameType::SITE_METADATA, []);
        $writer->writeJson(FrameType::MANIFEST, [
            'frame_count' => $writer->sequence() + 1,
            'root_hash' => $writer->rootHashHex(),
        ]);
        $writer->writeJson(FrameType::FOOTER, [
            'manifest_seq' => $writer->sequence(),
            'total_frames' => $writer->sequence(),
            'logical_bytes' => (string) $writer->logicalBytesEstimate(),
            'parts_expected' => 1,
        ]);
        $writer->flush();
        fwrite($stream, Header::MAGIC_TAIL);
        rewind($stream);

        try {
            $this->assertTrue((new ArchiveVerifier(new FrameReader($stream, new DeflateCodec())))
                ->step(microtime(true) + 10));
        } finally {
            fclose($stream);
        }
    }

    /** @dataProvider forgedManifestCounts */
    public function testRejectsManifestCountThatDisagreesWithFrames(string $field): void
    {
        $stream = fopen('php://memory', 'w+b');
        $header = new Header(
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
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), null);
        $writer->writeJson(FrameType::SITE_METADATA, []);
        $writer->writeJson(FrameType::FILE_METADATA, ['path' => 'one.txt', 'size' => 0]);
        $writer->finalize([$field => 2]);
        rewind($stream);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('invalid manifest');
            (new ArchiveVerifier(new FrameReader($stream, new DeflateCodec())))->step(microtime(true) + 10);
        } finally {
            fclose($stream);
        }
    }

    /** @return array<string,array{string}> */
    public static function forgedManifestCounts(): array
    {
        return ['rows' => ['row_count'], 'files' => ['file_count']];
    }
}
