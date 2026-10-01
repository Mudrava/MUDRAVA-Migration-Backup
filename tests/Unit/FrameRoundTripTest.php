<?php

/**
 * FrameWriter → FrameReader round-trips: plain, compressed, encrypted,
 * split sets, truncation, corruption, wrong password, sequence gaps.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Archive\FrameReader;
use Mudrava\Migration\Archive\FrameType;
use Mudrava\Migration\Archive\FrameWriter;
use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Compression\DeflateCodec;
use Mudrava\Migration\Crypto\CryptoCapability;
use Mudrava\Migration\Crypto\FrameCipher;
use Mudrava\Migration\Crypto\KeyDerivation;
use PHPUnit\Framework\TestCase;

final class FrameRoundTripTest extends TestCase
{
    /** @var string */
    private $tmp;

    protected function setUp(): void
    {
        $this->tmp = $GLOBALS['MUDRAVA_TEST_TMP'];
    }

    private function makeHeader(int $flags = 0): Header
    {
        return new Header(
            Header::CONTAINER_FORMAT,
            $flags,
            random_bytes(16),
            '1.0.0-test',
            0,
            0,
            0,
            str_repeat("\0", 16),
            str_repeat("\0", 8)
        );
    }

    private function writeArchive(Header $header, ?FrameCipher $cipher, array $frames): string
    {
        $path = tempnam($this->tmp, 'arch');
        $stream = fopen($path, 'wb');
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), $cipher);
        $writer->writeJson(FrameType::SITE_METADATA, ['site' => 'test', 'url' => 'https://old.example']);
        foreach ($frames as [$type, $payload]) {
            $writer->writeFrame($type, $payload);
        }
        $writer->finalize(['file_count' => 1, 'row_count' => 2]);
        fflush($stream);
        fclose($stream);
        return $path;
    }

    public function testPlainRoundTrip(): void
    {
        $header = $this->makeHeader();
        $path = $this->writeArchive($header, null, [
            [FrameType::FILE_DATA, 'chunk-one'],
            [FrameType::FILE_DATA, 'chunk-two'],
        ]);

        $stream = fopen($path, 'rb');
        $reader = new FrameReader($stream, new DeflateCodec());
        $got = [];
        while (($frame = $reader->next()) !== null) {
            $got[$frame->type][] = $frame->payload;
        }
        fclose($stream);

        $this->assertSame('chunk-one', $got[FrameType::FILE_DATA][0]);
        $this->assertSame('chunk-two', $got[FrameType::FILE_DATA][1]);
        $this->assertTrue($reader->isEof());
    }

    public function testCompressedFrameCannotExpandPastDeclaredLogicalLength(): void
    {
        $path = $this->writeArchive($this->makeHeader(), null, [
            [FrameType::FILE_DATA, str_repeat('A', 2 * 1024 * 1024)],
        ]);
        $bytes = (string) file_get_contents($path);
        $first = strpos($bytes, FrameReader::SYNC);
        $second = strpos($bytes, FrameReader::SYNC, $first + FrameReader::FRAME_HEADER_BYTES);
        $this->assertNotFalse($second);
        $bytes = substr_replace($bytes, pack('N', 1), $second + 18, 4);
        file_put_contents($path, $bytes);

        $stream = fopen($path, 'rb');
        try {
            $reader = new FrameReader($stream, new DeflateCodec());
            $this->assertSame(FrameType::SITE_METADATA, $reader->next()->type);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('MUDRAVA_ARCHIVE_CORRUPT');
            $reader->next();
        } finally {
            fclose($stream);
            unlink($path);
        }
    }

    public function testFrameRejectsLogicalLengthAboveFormatCeiling(): void
    {
        $path = $this->writeArchive($this->makeHeader(), null, []);
        $bytes = (string) file_get_contents($path);
        $first = strpos($bytes, FrameReader::SYNC);
        $this->assertNotFalse($first);
        $bytes = substr_replace($bytes, pack('N', FrameWriter::MAX_FRAME_BYTES + 1), $first + 18, 4);
        file_put_contents($path, $bytes);

        $stream = fopen($path, 'rb');
        try {
            $reader = new FrameReader($stream, new DeflateCodec());
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('implausible logical_len');
            $reader->next();
        } finally {
            fclose($stream);
            unlink($path);
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testLargeFrameFailsBeforeInflationUnderSmallMemoryLimit(): void
    {
        $path = $this->writeArchive($this->makeHeader(), null, [
            [FrameType::FILE_DATA, str_repeat('A', 12 * 1024 * 1024)],
        ]);
        $previousLimit = (string) ini_get('memory_limit');
        $stream = fopen($path, 'rb');
        try {
            ini_set('memory_limit', '32M');
            $reader = new FrameReader($stream, new DeflateCodec());
            $this->assertSame(FrameType::SITE_METADATA, $reader->next()->type);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('MUDRAVA_MEMORY_LIMIT');
            $reader->next();
        } finally {
            ini_set('memory_limit', $previousLimit);
            fclose($stream);
            unlink($path);
        }
    }

    public function testManifestVerificationPasses(): void
    {
        $header = $this->makeHeader();
        $path = $this->writeArchive($header, null, [[FrameType::FILE_DATA, str_repeat('A', 5000)]]);

        $stream = fopen($path, 'rb');
        $reader = new FrameReader($stream, new DeflateCodec());
        $manifest = null;
        while (($frame = $reader->next()) !== null) {
            if ($frame->type === FrameType::MANIFEST) {
                $manifest = $frame->json();
            }
        }
        $this->assertNotNull($manifest);
        $reader->verifyAgainstManifest($manifest); // must not throw
        fclose($stream);
        $this->assertTrue(true);
    }

    public function testCompressibleFrameIsCompressed(): void
    {
        $header = $this->makeHeader();
        $path = $this->writeArchive($header, null, [[FrameType::FILE_DATA, str_repeat('ABCDEF', 10000)]]);
        $size = filesize($path);

        $stream = fopen($path, 'rb');
        $reader = new FrameReader($stream, new DeflateCodec());
        $found = null;
        while (($frame = $reader->next()) !== null) {
            if ($frame->type === FrameType::FILE_DATA) {
                $found = $frame;
            }
        }
        fclose($stream);
        $this->assertNotNull($found);
        $this->assertTrue($found->compressed, 'highly compressible frame should set the compressed flag');
        $this->assertSame(str_repeat('ABCDEF', 10000), $found->payload);
        $this->assertLessThan(60000, $size, 'archive should be much smaller than 60 KB raw');
    }

    public function testEncryptedRoundTripSodium(): void
    {
        $cap = CryptoCapability::detect();
        if ($cap->stack !== CryptoCapability::STACK_SODIUM) {
            $this->markTestSkipped('sodium required');
        }
        $params = KeyDerivation::parameters($cap);
        $key = KeyDerivation::derive('correct horse battery staple', $params['kdf'], $params['opslimit'], $params['memlimit'], $params['salt']);
        $noncePrefix = random_bytes(8);

        $header = new Header(
            Header::CONTAINER_FORMAT,
            Header::FLAG_ENCRYPTED,
            random_bytes(16),
            '1.0.0-test',
            $params['kdf'],
            $params['opslimit'],
            $params['memlimit'],
            $params['salt'],
            $noncePrefix
        );
        $cipher = new FrameCipher($key, $noncePrefix, $cap->stack);
        $path = $this->writeArchive($header, $cipher, [[FrameType::FILE_DATA, 'secret payload 数据 🗄']]);

        $stream = fopen($path, 'rb');
        $reader = new FrameReader($stream, new DeflateCodec(), $cipher);
        $found = null;
        while (($frame = $reader->next()) !== null) {
            if ($frame->type === FrameType::FILE_DATA) {
                $found = $frame;
            }
        }
        fclose($stream);
        $this->assertTrue($found->encrypted);
        $this->assertSame('secret payload 数据 🗄', $found->payload);
    }

    public function testWrongPasswordRejected(): void
    {
        $cap = CryptoCapability::detect();
        if ($cap->stack !== CryptoCapability::STACK_SODIUM) {
            $this->markTestSkipped('sodium required');
        }
        $params = KeyDerivation::parameters($cap);
        $key = KeyDerivation::derive('right-password', $params['kdf'], $params['opslimit'], $params['memlimit'], $params['salt']);
        $noncePrefix = random_bytes(8);
        $header = new Header(
            Header::CONTAINER_FORMAT,
            Header::FLAG_ENCRYPTED,
            random_bytes(16),
            '1.0.0',
            $params['kdf'],
            $params['opslimit'],
            $params['memlimit'],
            $params['salt'],
            $noncePrefix
        );
        $cipher = new FrameCipher($key, $noncePrefix, $cap->stack);
        $path = $this->writeArchive($header, $cipher, [[FrameType::FILE_DATA, 'top secret']]);

        $wrongKey = KeyDerivation::derive('wrong-password', $params['kdf'], $params['opslimit'], $params['memlimit'], $params['salt']);
        $wrongCipher = new FrameCipher($wrongKey, $noncePrefix, $cap->stack);

        $stream = fopen($path, 'rb');
        $reader = new FrameReader($stream, new DeflateCodec(), $wrongCipher);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MUDRAVA_WRONG_PASSWORD');
        while ($reader->next() !== null) {
            // keep reading until the encrypted frame rejects
        }
    }

    public function testTruncatedArchiveDetected(): void
    {
        $header = $this->makeHeader();
        $path = $this->writeArchive($header, null, [[FrameType::FILE_DATA, str_repeat('X', 1000)]]);
        $size = filesize($path);
        $truncated = $path . '.trunc';
        file_put_contents($truncated, substr((string) file_get_contents($path), 0, (int) $size - 40));

        $stream = fopen($truncated, 'rb');
        $reader = new FrameReader($stream, new DeflateCodec());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MUDRAVA_ARCHIVE_TRUNCATED');
        while ($reader->next() !== null) {
            // read to truncation point
        }
    }

    public function testCorruptedFrameDetected(): void
    {
        $header = $this->makeHeader();
        $path = $this->writeArchive($header, null, [[FrameType::FILE_DATA, str_repeat('Y', 200)]]);
        $bytes = (string) file_get_contents($path);
        // Flip one byte inside the first FILE_DATA payload region.
        $pos = strpos($bytes, 'MUDF' . chr(FrameType::FILE_DATA));
        $this->assertNotFalse($pos);
        $bytes[$pos + 30] = chr(ord($bytes[$pos + 30]) ^ 0xFF);
        file_put_contents($path, $bytes);

        $stream = fopen($path, 'rb');
        $reader = new FrameReader($stream, new DeflateCodec());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MUDRAVA_ARCHIVE_CORRUPT');
        while ($reader->next() !== null) {
            // read until corruption detected
        }
    }

    public function testManifestMismatchDetected(): void
    {
        $header = $this->makeHeader();
        $path = $this->writeArchive($header, null, [[FrameType::FILE_DATA, 'data']]);

        $stream = fopen($path, 'rb');
        $reader = new FrameReader($stream, new DeflateCodec());
        while ($reader->next() !== null) {
            // drain
        }
        fclose($stream);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MUDRAVA_MANIFEST_MISMATCH');
        $reader->verifyAgainstManifest(['root_hash' => str_repeat('0', 64), 'frame_count' => 999]);
    }

    public function testSplitSetRoundTrip(): void
    {
        $header = new Header(Header::CONTAINER_FORMAT, Header::FLAG_SPLIT_SET, random_bytes(16), '1.0.0', 0, 0, 0, str_repeat("\0", 16), str_repeat("\0", 8));
        $base = $this->tmp . '/split-' . uniqid();
        $stream = fopen($base, 'wb');
        // Tiny split threshold forces multiple parts.
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), null, 2048, $base);
        $writer->writeJson(FrameType::SITE_METADATA, ['site' => 'split-test']);
        for ($i = 0; $i < 40; $i++) {
            $writer->writeFrame(FrameType::FILE_DATA, random_bytes(512));
        }
        $writer->finalize();
        fclose($stream);

        // Physical parts must exist.
        $this->assertFileExists($base . '.part0002');

        // Reader with part rotation must reconstruct everything.
        $s1 = fopen($base, 'rb');
        $partNum = 1;
        $provider = function () use (&$partNum, $base) {
            $partNum++;
            $p = sprintf('%s.part%04d', $base, $partNum);
            return is_file($p) ? fopen($p, 'rb') : null;
        };
        $reader = new FrameReader($s1, new DeflateCodec(), null, $provider);
        $count = 0;
        $manifest = null;
        while (($frame = $reader->next()) !== null) {
            if ($frame->type === FrameType::FILE_DATA) {
                $count++;
            }
            if ($frame->type === FrameType::MANIFEST) {
                $manifest = $frame->json();
            }
        }
        fclose($s1);
        $this->assertSame(40, $count);
        $this->assertNotNull($manifest);
        $this->assertTrue($reader->isEof());
    }

    public function testMissingPartExplicitError(): void
    {
        $header = new Header(Header::CONTAINER_FORMAT, Header::FLAG_SPLIT_SET, random_bytes(16), '1.0.0', 0, 0, 0, str_repeat("\0", 16), str_repeat("\0", 8));
        $base = $this->tmp . '/missing-' . uniqid();
        $stream = fopen($base, 'wb');
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), null, 1024, $base);
        $writer->writeJson(FrameType::SITE_METADATA, ['site' => 'x']);
        for ($i = 0; $i < 30; $i++) {
            $writer->writeFrame(FrameType::FILE_DATA, random_bytes(512));
        }
        $writer->finalize();
        fclose($stream);

        // Delete part 2 to simulate a lost file.
        $this->assertFileExists($base . '.part0002');
        unlink($base . '.part0002');

        $s1 = fopen($base, 'rb');
        $partNum = 1;
        $provider = function () use (&$partNum, $base) {
            $partNum++;
            $p = sprintf('%s.part%04d', $base, $partNum);
            return is_file($p) ? fopen($p, 'rb') : null;
        };
        $reader = new FrameReader($s1, new DeflateCodec(), null, $provider);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MUDRAVA_ARCHIVE_TRUNCATED');
        while ($reader->next() !== null) {
            // read until missing part
        }
    }

    public function testScanPrefixMatchesFullChain(): void
    {
        $header = $this->makeHeader();
        $path = $this->writeArchive($header, null, [
            [FrameType::FILE_DATA, 'aaa'],
            [FrameType::FILE_DATA, 'bbb'],
        ]);

        // Full reader chain.
        $s = fopen($path, 'rb');
        $reader = new FrameReader($s, new DeflateCodec());
        while ($reader->next() !== null) {
            // drain
        }
        $fullHash = $reader->rootHashHex();
        fclose($s);

        // Scan chain (no decryption needed).
        $s = fopen($path, 'rb');
        $scan = FrameReader::scanPrefix($s);
        fclose($s);
        $this->assertSame($fullHash, $scan['root_hash']);
    }

    public function testReaderRejectsInvalidStreamAndReturnsNullAfterTail(): void
    {
        try {
            new FrameReader('not-a-stream', new DeflateCodec());
            $this->fail('invalid stream accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Stream resource required', $e->getMessage());
        }

        $path = $this->writeArchive($this->makeHeader(), null, []);
        $stream = fopen($path, 'rb');
        $reader = new FrameReader($stream, new DeflateCodec());
        while ($reader->next() !== null) {
            // Drain through the tail marker.
        }
        $this->assertNull($reader->next());
        $this->assertNotEmpty($reader->typeCounts());
        fclose($stream);
        unlink($path);
    }

    public function testReaderRejectsBytesAfterTail(): void
    {
        $path = $this->writeArchive($this->makeHeader(), null, []);
        file_put_contents($path, 'unexpected', FILE_APPEND);
        $this->assertArchiveFailure($path, 'data after tail');
    }

    /**
     * @dataProvider malformedFrameProvider
     */
    public function testMalformedFrameHeadersAreRejected(string $field, int $value, string $message): void
    {
        $path = $this->writeArchive($this->makeHeader(), null, []);
        $bytes = (string) file_get_contents($path);
        $frame = strpos($bytes, FrameReader::SYNC);
        $this->assertNotFalse($frame);
        $offsets = ['sequence' => 6, 'stored' => 14, 'logical' => 18];
        $packed = $field === 'sequence' ? pack('P', $value) : pack('N', $value);
        $bytes = substr_replace($bytes, $packed, $frame + $offsets[$field], strlen($packed));
        file_put_contents($path, $bytes);

        $stream = fopen($path, 'rb');
        try {
            $reader = new FrameReader($stream, new DeflateCodec());
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage($message);
            $reader->next();
        } finally {
            fclose($stream);
            unlink($path);
        }
    }

    /** @return array<string,array{string,int,string}> */
    public function malformedFrameProvider(): array
    {
        return [
            'sequence gap' => ['sequence', 2, 'sequence gap'],
            'stored length ceiling' => ['stored', FrameReader::MAX_STORED_BYTES + 1, 'implausible stored_len'],
            'logical length ceiling' => ['logical', FrameWriter::MAX_FRAME_BYTES + 1, 'implausible logical_len'],
        ];
    }

    public function testBadFrameSyncAndMissingEncryptionKeyAreRejected(): void
    {
        $path = $this->writeArchive($this->makeHeader(), null, []);
        $bytes = (string) file_get_contents($path);
        $frame = strpos($bytes, FrameReader::SYNC);
        $this->assertNotFalse($frame);
        file_put_contents($path, substr_replace($bytes, 'NOPE', $frame, 4));
        $this->assertArchiveFailure($path, 'bad frame sync');

        $cap = CryptoCapability::detect();
        if (!$cap->available) {
            return;
        }
        $params = KeyDerivation::parameters($cap);
        $key = KeyDerivation::derive('reader-key', $params['kdf'], $params['opslimit'], $params['memlimit'], $params['salt']);
        $nonce = random_bytes(8);
        $header = new Header(
            Header::CONTAINER_FORMAT,
            Header::FLAG_ENCRYPTED,
            random_bytes(16),
            '1.0.0',
            $params['kdf'],
            $params['opslimit'],
            $params['memlimit'],
            $params['salt'],
            $nonce
        );
        $encrypted = $this->writeArchive($header, new FrameCipher($key, $nonce, $cap->stack), []);
        $this->assertArchiveFailure($encrypted, 'encrypted frame but no key provided');
    }

    public function testManifestFrameCountMismatchIsRejected(): void
    {
        $path = $this->writeArchive($this->makeHeader(), null, []);
        $stream = fopen($path, 'rb');
        $reader = new FrameReader($stream, new DeflateCodec());
        $manifest = [];
        while (($frame = $reader->next()) !== null) {
            if ($frame->type === FrameType::MANIFEST) {
                $manifest = $frame->json();
            }
        }
        $manifest['frame_count'] = 999;
        try {
            $reader->verifyAgainstManifest($manifest);
            $this->fail('incorrect frame count accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('frame_count', $e->getMessage());
        } finally {
            fclose($stream);
            unlink($path);
        }
    }

    public function testResumeRejectsMissingPartAndBadRootHash(): void
    {
        $path = $this->writeArchive($this->makeHeader(), null, []);
        $stream = fopen($path, 'rb');
        $reader = new FrameReader($stream, new DeflateCodec());
        try {
            $reader->resumeTo(2, 0, 0, str_repeat('0', 64));
            $this->fail('missing resume part accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MUDRAVA_PART_MISSING', $e->getMessage());
        }
        fclose($stream);

        $stream = fopen($path, 'rb');
        $reader = new FrameReader($stream, new DeflateCodec());
        try {
            $reader->resumeTo(1, 0, 0, str_repeat('a', 62));
            $this->fail('bad resume hash accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('bad resume root_hash', $e->getMessage());
        } finally {
            fclose($stream);
            unlink($path);
        }
    }

    public function testRotatedPartWithForeignUuidIsRejected(): void
    {
        $header = $this->makeHeader(Header::FLAG_SPLIT_SET);
        $path = $this->writeArchive($header, null, []);
        $stream = fopen($path, 'rb');
        $foreign = Header::encodeContinuation(random_bytes(16), 2);
        $provider = static function () use ($foreign) {
            $part = fopen('php://memory', 'wb+');
            fwrite($part, $foreign);
            rewind($part);
            return $part;
        };
        $reader = new FrameReader($stream, new DeflateCodec(), null, $provider);
        try {
            $reader->resumeTo(2, 28, 0, str_repeat('0', 64));
            $this->fail('foreign part accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('wrong archive UUID', $e->getMessage());
        } finally {
            fclose($stream);
            unlink($path);
        }
    }

    /**
     * @dataProvider malformedScanProvider
     */
    public function testPrefixScanRejectsInvalidHeaders(string $bytes, string $message): void
    {
        $stream = fopen('php://memory', 'wb+');
        fwrite($stream, $bytes);
        rewind($stream);
        try {
            FrameReader::scanPrefix($stream);
            $this->fail('malformed scan input accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        } finally {
            fclose($stream);
        }
    }

    /** @return array<string,array{string,string}> */
    public function malformedScanProvider(): array
    {
        $header = $this->makeHeader()->encode();
        return [
            'empty' => ['', 'bad magic in scan'],
            'bad magic' => [str_repeat('X', 12), 'bad magic in scan'],
            'short header' => [substr($header, 0, 12), 'header in scan'],
        ];
    }

    public function testPrefixScanStopsAtEveryDamagedFrameBoundary(): void
    {
        $path = $this->writeArchive($this->makeHeader(), null, []);
        $bytes = (string) file_get_contents($path);
        $frame = strpos($bytes, FrameReader::SYNC);
        $this->assertNotFalse($frame);
        $cases = [
            substr($bytes, 0, $frame) . 'JUNK',
            substr($bytes, 0, $frame + 8),
            substr_replace($bytes, pack('P', 2), $frame + 6, 8),
            substr_replace($bytes, pack('N', FrameReader::MAX_STORED_BYTES + 1), $frame + 14, 4),
            substr_replace($bytes, pack('N', FrameWriter::MAX_FRAME_BYTES + 1), $frame + 18, 4),
            substr_replace($bytes, "\x42", $frame + 4, 1), // unknown required type
            substr($bytes, 0, $frame + FrameReader::FRAME_HEADER_BYTES + 1),
        ];
        $corrupt = $bytes;
        $storedLength = unpack('N', substr($corrupt, $frame + 14, 4))[1];
        $corrupt[$frame + FrameReader::FRAME_HEADER_BYTES + max(0, $storedLength - 1)] = "\x00";
        $cases[] = $corrupt;

        foreach ($cases as $case) {
            $stream = fopen('php://memory', 'wb+');
            fwrite($stream, $case);
            rewind($stream);
            $scan = FrameReader::scanPrefix($stream);
            fclose($stream);
            $this->assertSame(0, $scan['sequence']);
        }
        unlink($path);
    }

    public function testOptionalUnknownFrameRemainsSkippableDuringPrefixScan(): void
    {
        $path = $this->writeArchive($this->makeHeader(), null, [[0x80, 'future extension']]);
        $stream = fopen($path, 'rb');
        $scan = FrameReader::scanPrefix($stream);
        fclose($stream);
        $this->assertSame(4, $scan['sequence']);
        $this->assertSame(1, $scan['type_counts'][0x80]);

        $stream = fopen($path, 'rb');
        $reader = new FrameReader($stream, new DeflateCodec());
        $reader->next(); // SITE_METADATA
        $optional = $reader->next();
        fclose($stream);
        unlink($path);
        $this->assertSame(0x80, $optional->type);
        $this->assertSame('', $optional->payload);
    }

    public function testPrefixScanKeepsOnlyCompleteFramesAtEveryByteTruncation(): void
    {
        $path = $this->writeArchive($this->makeHeader(), null, [
            [FrameType::FILE_DATA, 'first payload'],
            [FrameType::FILE_DATA, 'second payload'],
        ]);
        $bytes = (string) file_get_contents($path);
        $stream = fopen($path, 'rb');
        $reader = new FrameReader($stream, new DeflateCodec());
        $headerEnd = strlen($reader->readHeader()->encode());
        $boundaries = [];
        while ($reader->next() !== null) {
            $boundaries[] = $reader->position()['offset'];
        }
        fclose($stream);

        for ($length = $headerEnd; $length < strlen($bytes); $length++) {
            $prefix = fopen('php://memory', 'w+b');
            fwrite($prefix, substr($bytes, 0, $length));
            rewind($prefix);
            $scan = FrameReader::scanPrefix($prefix);
            fclose($prefix);
            $expected = count(array_filter($boundaries, static function (int $end) use ($length): bool {
                return $end <= $length;
            }));
            $this->assertSame($expected, $scan['sequence'], 'truncation at byte ' . $length);
        }
        unlink($path);
    }

    /** @runInSeparateProcess */
    public function testPrefixScanStreamsLargeStoredFrameWithBoundedMemory(): void
    {
        $path = tempnam($this->tmp, 'scan-memory-');
        $stream = fopen($path, 'wb');
        $chunk = random_bytes(65536);
        $chunks = 256; // 16 MiB of actual stored bytes, never a sparse file.
        $length = strlen($chunk) * $chunks;
        $hash = hash_init('crc32b');
        for ($i = 0; $i < $chunks; $i++) {
            hash_update($hash, $chunk);
        }
        $crc = (int) hexdec(hash_final($hash));
        fwrite($stream, $this->makeHeader()->encode());
        fwrite($stream, FrameReader::SYNC . chr(FrameType::FILE_DATA) . "\0"
            . pack('P', 1) . pack('N', $length) . pack('N', $length) . pack('N', $crc));
        for ($i = 0; $i < $chunks; $i++) {
            fwrite($stream, $chunk);
        }
        fwrite($stream, Header::MAGIC_TAIL);
        fclose($stream);

        $stream = fopen($path, 'rb');
        $before = memory_get_peak_usage(true);
        $scan = FrameReader::scanPrefix($stream);
        $growth = memory_get_peak_usage(true) - $before;
        fclose($stream);
        unlink($path);

        $this->assertSame(1, $scan['sequence']);
        $this->assertSame($length, $scan['logical_bytes']);
        $this->assertLessThan(8 * 1048576, $growth);
    }

    private function assertArchiveFailure(string $path, string $message): void
    {
        $stream = fopen($path, 'rb');
        try {
            $reader = new FrameReader($stream, new DeflateCodec());
            while ($reader->next() !== null) {
                // Drain until the expected parser rejection.
            }
            $this->fail('archive unexpectedly accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        } finally {
            fclose($stream);
            unlink($path);
        }
    }

    public function testInlineContinuationHeadersAreValidated(): void
    {
        $header = $this->makeHeader(Header::FLAG_SPLIT_SET);
        $stream = fopen('php://memory', 'wb+');
        fwrite($stream, $header->encode() . Header::encodeContinuation($header->archiveUuid, 2) . Header::MAGIC_TAIL);
        rewind($stream);
        $reader = new FrameReader($stream, new DeflateCodec());
        $this->assertNull($reader->next());
        $this->assertSame(2, $reader->position()['part']);
        fclose($stream);

        $stream = fopen('php://memory', 'wb+');
        fwrite($stream, $header->encode() . Header::encodeContinuation(random_bytes(16), 2));
        rewind($stream);
        try {
            (new FrameReader($stream, new DeflateCodec()))->next();
            $this->fail('foreign inline continuation accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('wrong archive UUID', $e->getMessage());
        } finally {
            fclose($stream);
        }
    }

    public function testPrefixScanHandlesContinuationTailAndDamagedContinuation(): void
    {
        $header = $this->makeHeader(Header::FLAG_SPLIT_SET);
        $stream = fopen('php://memory', 'wb+');
        fwrite($stream, $header->encode() . Header::encodeContinuation($header->archiveUuid, 2) . Header::MAGIC_TAIL);
        rewind($stream);
        $scan = FrameReader::scanPrefix($stream);
        fclose($stream);
        $this->assertSame(2, $scan['part']);
        $this->assertSame(28, $scan['part_offset']);

        foreach (
            [
                substr(Header::encodeContinuation($header->archiveUuid, 2), 0, 10),
                Header::encodeContinuation(random_bytes(16), 2),
                Header::encodeContinuation($header->archiveUuid, 7),
            ] as $continuation
        ) {
            $stream = fopen('php://memory', 'wb+');
            fwrite($stream, $header->encode() . $continuation);
            rewind($stream);
            $damaged = FrameReader::scanPrefix($stream);
            fclose($stream);
            $this->assertSame(1, $damaged['part']);
        }

        try {
            FrameReader::scanPrefix('not-a-stream');
            $this->fail('non-stream prefix accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Stream resource required', $e->getMessage());
        }
    }

    public function testWriterLifecycleAndResumeInputValidation(): void
    {
        $header = $this->makeHeader();
        try {
            new FrameWriter('not-a-stream', $header, new DeflateCodec(), null);
            $this->fail('writer accepted a non-stream');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Stream resource required', $e->getMessage());
        }

        $stream = fopen('php://memory', 'wb+');
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), null);
        $this->assertSame($header->encode(), $writer->headerBytes());
        $writer->writeHeader();
        try {
            $writer->writeHeader();
            $this->fail('second header accepted');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('already written', $e->getMessage());
        }
        $writer->finalize();
        $this->assertTrue($writer->isFinalized());
        $this->assertGreaterThan(0, $writer->physicalBytes());
        $this->assertGreaterThan(0, $writer->currentPartBytes());
        $this->assertSame(1, $writer->currentPart());
        foreach (['frame', 'finalize'] as $operation) {
            try {
                $operation === 'frame'
                    ? $writer->writeFrame(FrameType::CHECKPOINT, 'x')
                    : $writer->finalize();
                $this->fail('finalized writer accepted ' . $operation);
            } catch (\LogicException $e) {
                $this->assertStringContainsString('finalized', strtolower($e->getMessage()));
            }
        }
        fclose($stream);

        $stream = fopen('php://memory', 'wb+');
        try {
            FrameWriter::resumeFrom($stream, $header, new DeflateCodec(), null, [
                'sequence' => 0, 'root_hash' => str_repeat('a', 62), 'logical_bytes' => 0,
                'type_counts' => [], 'part' => 1, 'part_offset' => 0, 'physical_bytes' => 0,
            ]);
            $this->fail('invalid resume hash accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('bad root_hash', $e->getMessage());
        } finally {
            fclose($stream);
        }
    }

    public function testWriterRejectsRecursiveJsonAndUnwritableSplitPart(): void
    {
        $stream = fopen('php://memory', 'wb+');
        $writer = new FrameWriter($stream, $this->makeHeader(), new DeflateCodec(), null);
        $recursive = [];
        $recursive['self'] = &$recursive;
        try {
            $writer->writeJson(FrameType::CHECKPOINT, $recursive);
            $this->fail('recursive JSON accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('JSON encode failed', $e->getMessage());
        }
        fclose($stream);

        $stream = fopen('php://memory', 'wb+');
        $base = $this->tmp . '/missing-parent-' . uniqid() . '/archive';
        try {
            $writer = new FrameWriter($stream, $this->makeHeader(), new DeflateCodec(), null, 1, $base);
            $writer->writeHeader();
            $this->fail('writer opened a split part in a missing directory');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MUDRAVA_PERMISSION_DENIED', $e->getMessage());
        } finally {
            fclose($stream);
        }
    }

    public function testSplitWriterNotifiesRotationAndClosesOwnedStreams(): void
    {
        $header = $this->makeHeader(Header::FLAG_SPLIT_SET);
        $base = $this->tmp . '/writer-parts-' . uniqid();
        $stream = fopen($base, 'wb');
        $rotated = [];
        $writer = new FrameWriter(
            $stream,
            $header,
            new DeflateCodec(),
            null,
            128,
            $base,
            static function (int $part) use (&$rotated): void {
                $rotated[] = $part;
            }
        );
        $writer->writeFrame(FrameType::FILE_DATA, random_bytes(256));
        $writer->flush();
        $writer->closeOwnedStreams();
        fclose($stream);
        $this->assertNotEmpty($rotated);
        $this->assertFileExists($base . '.part0002');
        @unlink($base);
        foreach ((array) glob($base . '.part*') as $part) {
            @unlink($part);
        }
    }

    public function testPrefixScanTraversesPhysicalSplitParts(): void
    {
        $header = $this->makeHeader(Header::FLAG_SPLIT_SET);
        $base = $this->tmp . '/scan-parts-' . uniqid();
        $stream = fopen($base, 'wb');
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), null, 256, $base);
        for ($i = 0; $i < 5; $i++) {
            $writer->writeFrame(FrameType::FILE_DATA, random_bytes(300));
        }
        $writer->finalize();
        $expectedSequence = $writer->sequence();
        $writer->closeOwnedStreams();
        fclose($stream);

        $first = fopen($base, 'rb');
        $part = 1;
        $provider = static function () use (&$part, $base) {
            ++$part;
            $path = sprintf('%s.part%04d', $base, $part);
            return is_file($path) ? fopen($path, 'rb') : null;
        };
        $scan = FrameReader::scanPrefix($first, $provider);
        fclose($first);

        $this->assertSame($expectedSequence, $scan['sequence']);
        $this->assertGreaterThan(1, $scan['part']);
        foreach ((array) glob($base . '.part*') as $path) {
            @unlink($path);
        }
        @unlink($base);
    }

    public function testPrefixScanStopsSafelyAtEveryIncompletePartBoundary(): void
    {
        $header = $this->makeHeader(Header::FLAG_SPLIT_SET);

        $first = fopen('php://memory', 'w+b');
        fwrite($first, $header->encode());
        rewind($first);
        $scan = FrameReader::scanPrefix($first);
        fclose($first);
        $this->assertSame(0, $scan['sequence']);

        foreach (['short', 'wrong', 'magic', 'number'] as $case) {
            $first = fopen('php://memory', 'w+b');
            fwrite($first, $header->encode());
            rewind($first);
            $provider = static function () use ($case, $header) {
                $part = fopen('php://memory', 'w+b');
                $continuation = Header::encodeContinuation(random_bytes(16), 2);
                if ($case === 'short') {
                    $continuation = 'short';
                } elseif ($case === 'magic') {
                    $continuation = str_repeat('X', 28);
                } elseif ($case === 'number') {
                    $continuation = Header::encodeContinuation($header->archiveUuid, 7);
                }
                fwrite($part, $continuation);
                rewind($part);
                return $part;
            };
            $scan = FrameReader::scanPrefix($first, $provider);
            fclose($first);
            $this->assertSame(1, $scan['part']);
        }

        foreach (['MUDR', 'MUDRXXXX'] as $suffix) {
            $first = fopen('php://memory', 'w+b');
            fwrite($first, $header->encode() . $suffix);
            rewind($first);
            $scan = FrameReader::scanPrefix($first);
            fclose($first);
            $this->assertSame(0, $scan['sequence']);
        }
    }
}
