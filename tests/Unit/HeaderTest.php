<?php

/**
 * Header encode/decode round-trip and continuation headers.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Archive\Header;
use PHPUnit\Framework\TestCase;

final class HeaderTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $header = new Header(
            Header::CONTAINER_FORMAT,
            Header::FLAG_ENCRYPTED | Header::FLAG_SPLIT_SET,
            random_bytes(16),
            '1.0.0',
            1,
            3,
            67108864,
            random_bytes(16),
            random_bytes(8)
        );
        $bytes = $header->encode();
        $decoded = Header::decode($bytes);

        $this->assertSame(Header::CONTAINER_FORMAT, $decoded->containerFormat);
        $this->assertTrue($decoded->isEncrypted());
        $this->assertTrue($decoded->isSplitSet());
        $this->assertSame($header->archiveUuid, $decoded->archiveUuid);
        $this->assertSame('1.0.0', $decoded->producerVersion);
        $this->assertSame(1, $decoded->kdf);
        $this->assertSame(3, $decoded->kdfOpslimit);
        $this->assertSame(67108864, $decoded->kdfMemlimit);
        $this->assertSame($header->kdfSalt, $decoded->kdfSalt);
        $this->assertSame($header->noncePrefix, $decoded->noncePrefix);
    }

    public function testMagicBytes(): void
    {
        $header = new Header(1, 0, random_bytes(16), '1.0.0', 0, 0, 0, str_repeat("\0", 16), str_repeat("\0", 8));
        $bytes = $header->encode();
        $this->assertSame("MUDRAVA\0", substr($bytes, 0, 8));
    }

    public function testUnencryptedNoFlags(): void
    {
        $header = new Header(1, 0, random_bytes(16), '1.0.0', 0, 0, 0, str_repeat("\0", 16), str_repeat("\0", 8));
        $decoded = Header::decode($header->encode());
        $this->assertFalse($decoded->isEncrypted());
        $this->assertFalse($decoded->isSplitSet());
    }

    public function testBadMagicThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        Header::decode('NOTMAGIC' . str_repeat("\0", 30));
    }

    public function testFutureFormatRejected(): void
    {
        $header = new Header(99, 0, random_bytes(16), '1.0.0', 0, 0, 0, str_repeat("\0", 16), str_repeat("\0", 8));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MUDRAVA_FORMAT_UNSUPPORTED');
        Header::decode($header->encode());
    }

    public function testReservedZeroFormatRejectedByAllReaders(): void
    {
        $header = new Header(0, 0, random_bytes(16), '1.0.0', 0, 0, 0, str_repeat("\0", 16), str_repeat("\0", 8));
        $bytes = $header->encode();
        try {
            Header::decode($bytes);
            $this->fail('reserved container format accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MUDRAVA_FORMAT_UNSUPPORTED', $e->getMessage());
        }

        $stream = fopen('php://memory', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);
        try {
            \Mudrava\Migration\Archive\FrameReader::scanPrefix($stream);
            $this->fail('prefix scanner accepted reserved container format');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MUDRAVA_FORMAT_UNSUPPORTED', $e->getMessage());
        } finally {
            fclose($stream);
        }
    }

    public function testContinuationRoundTrip(): void
    {
        $uuid = random_bytes(16);
        $bytes = Header::encodeContinuation($uuid, 7);
        $this->assertSame("MUDRAVAP", substr($bytes, 0, 8));
        $decoded = Header::decodeContinuation($bytes);
        $this->assertSame($uuid, $decoded['archive_uuid']);
        $this->assertSame(7, $decoded['part_number']);
    }

    public function testContinuationBadMagicThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        Header::decodeContinuation("XXXXXXXX" . random_bytes(20));
    }

    public function testHeaderSizeFieldMatchesLength(): void
    {
        $header = new Header(1, 0, random_bytes(16), '1.2.3-beta', 2, 600000, 0, random_bytes(16), random_bytes(8));
        $bytes = $header->encode();
        $un = unpack('vheaderSize', substr($bytes, 10, 2));
        $this->assertSame((int) $un['headerSize'], strlen($bytes));
    }

    /**
     * Password hint (spec §9): plaintext, optional, appended after the fixed
     * tail and bounded by header_size, so older readers skip it safely.
     */
    public function testPasswordHintRoundTrip(): void
    {
        $header = new Header(1, Header::FLAG_ENCRYPTED, random_bytes(16), '1.0.0', 1, 3, 1024, random_bytes(16), random_bytes(8), 'company vault / migration');
        $bytes = $header->encode();
        $un = unpack('vheaderSize', substr($bytes, 10, 2));
        $this->assertSame((int) $un['headerSize'], strlen($bytes));
        $decoded = Header::decode($bytes);
        $this->assertSame('company vault / migration', $decoded->passwordHint);
    }

    public function testEmptyHintKeepsPreHintByteLayout(): void
    {
        // The golden fixture predates hints; an empty hint must not add a
        // single byte, otherwise every existing archive would diff.
        $fixed = new Header(1, 0, str_repeat("\x11", 16), '1.0.0', 0, 0, 0, str_repeat("\x22", 16), str_repeat("\x33", 8));
        $withEmpty = new Header(1, 0, str_repeat("\x11", 16), '1.0.0', 0, 0, 0, str_repeat("\x22", 16), str_repeat("\x33", 8), '');
        $this->assertSame($fixed->encode(), $withEmpty->encode());
        $this->assertSame('', Header::decode($fixed->encode())->passwordHint);
    }

    public function testUnicodeHintRoundTrip(): void
    {
        $hint = "\u{043a}\u{043b}\u{044e}\u{0447} \u{0431}\u{0430}\u{043d}\u{043a}, \u{1F510}";
        $header = new Header(1, Header::FLAG_ENCRYPTED, random_bytes(16), '1.0.0', 1, 3, 1024, random_bytes(16), random_bytes(8), $hint);
        $this->assertSame($hint, Header::decode($header->encode())->passwordHint);
    }

    public function testHintCannotOverflowTwoByteHeaderSize(): void
    {
        $header = new Header(1, 0, random_bytes(16), '1.0.0', 0, 0, 0, random_bytes(16), random_bytes(8));
        $maxHintBytes = 65535 - strlen($header->encode()) - 2;
        $header->passwordHint = str_repeat('h', $maxHintBytes);
        $encoded = $header->encode();
        $this->assertSame(65535, strlen($encoded));
        $this->assertSame($header->passwordHint, Header::decode($encoded)->passwordHint);

        $header->passwordHint .= 'h';
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('password_hint too long');
        $header->encode();
    }

    public function testTruncatedHeaderFailsClosed(): void
    {
        // A header whose bytes are cut short of header_size is a corrupt
        // archive, hint or no hint: fail closed with the stable code, never
        // half-parse the KDF parameters and silently produce a wrong key.
        $header = new Header(1, 0, random_bytes(16), '1.0.0', 0, 0, 0, random_bytes(16), random_bytes(8), 'a-long-hint-value');
        $bytes = $header->encode();
        $truncated = substr($bytes, 0, strlen($bytes) - 5);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MUDRAVA_ARCHIVE_CORRUPT');
        Header::decode($truncated);
    }
}
