<?php

/**
 * Archive-card metadata reads, including cross-stack encrypted archives.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Archive\ArchiveInfo;
use Mudrava\Migration\Archive\FrameType;
use Mudrava\Migration\Archive\FrameWriter;
use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Compression\DeflateCodec;
use Mudrava\Migration\Crypto\CryptoCapability;
use Mudrava\Migration\Crypto\FrameCipher;
use Mudrava\Migration\Crypto\KeyDerivation;
use PHPUnit\Framework\TestCase;

final class ArchiveInfoTest extends TestCase
{
    /** @var string */
    private $tmp;

    protected function setUp(): void
    {
        $this->tmp = $GLOBALS['MUDRAVA_TEST_TMP'] . '/archive-info-' . uniqid('', true);
        mkdir($this->tmp, 0777, true);
    }

    public function testPlainArchiveReportsSourceUrls(): void
    {
        $path = $this->tmp . '/plain.mudrava';
        $header = new Header(
            Header::CONTAINER_FORMAT,
            0,
            random_bytes(16),
            'test',
            KeyDerivation::KDF_NONE,
            0,
            0,
            str_repeat("\0", 16),
            str_repeat("\0", 8)
        );
        $this->writeArchive($path, $header, null);

        self::assertSame([
            'source_url' => 'https://source.example',
            'home_url' => 'https://source.example/home',
            'encrypted' => false,
            'hint' => '',
            'unreadable' => false,
        ], ArchiveInfo::peek($path));
    }

    public function testOpenSslArchiveUsesHeaderStackOnSodiumCapableHost(): void
    {
        if (!CryptoCapability::supports(CryptoCapability::STACK_OPENSSL)) {
            $this->markTestSkipped('OpenSSL AES-GCM is unavailable');
        }
        $path = $this->tmp . '/openssl.mudrava';
        $salt = random_bytes(16);
        $nonce = random_bytes(8);
        $header = new Header(
            Header::CONTAINER_FORMAT,
            Header::FLAG_ENCRYPTED,
            random_bytes(16),
            'test',
            KeyDerivation::KDF_PBKDF2,
            1000,
            0,
            $salt,
            $nonce,
            'vault hint'
        );
        $key = KeyDerivation::derive('correct horse', KeyDerivation::KDF_PBKDF2, 1000, 0, $salt);
        $this->writeArchive($path, $header, new FrameCipher($key, $nonce, CryptoCapability::STACK_OPENSSL));

        $info = ArchiveInfo::peek($path, 'correct horse');
        self::assertTrue($info['encrypted']);
        self::assertSame('vault hint', $info['hint']);
        self::assertSame('https://source.example', $info['source_url']);
        self::assertSame('https://source.example/home', $info['home_url']);
        self::assertFalse($info['unreadable']);
    }

    public function testEncryptedArchiveWithoutPasswordOnlyExposesPublicHint(): void
    {
        if (!CryptoCapability::supports(CryptoCapability::STACK_OPENSSL)) {
            $this->markTestSkipped('OpenSSL AES-GCM is unavailable');
        }
        $path = $this->tmp . '/sealed.mudrava';
        $salt = random_bytes(16);
        $nonce = random_bytes(8);
        $header = new Header(
            Header::CONTAINER_FORMAT,
            Header::FLAG_ENCRYPTED,
            random_bytes(16),
            'test',
            KeyDerivation::KDF_PBKDF2,
            1000,
            0,
            $salt,
            $nonce,
            'public hint'
        );
        $key = KeyDerivation::derive('secret', KeyDerivation::KDF_PBKDF2, 1000, 0, $salt);
        $this->writeArchive($path, $header, new FrameCipher($key, $nonce, CryptoCapability::STACK_OPENSSL));

        $info = ArchiveInfo::peek($path);
        self::assertTrue($info['encrypted']);
        self::assertSame('public hint', $info['hint']);
        self::assertSame('', $info['source_url']);
        self::assertFalse($info['unreadable']);
        self::assertTrue(ArchiveInfo::peek($path, 'wrong')['unreadable']);
    }

    public function testMissingOrCorruptArchiveFailsClosed(): void
    {
        self::assertTrue(ArchiveInfo::peek($this->tmp . '/missing.mudrava')['unreadable']);
        $path = $this->tmp . '/corrupt.mudrava';
        file_put_contents($path, 'not an archive');
        self::assertTrue(ArchiveInfo::peek($path)['unreadable']);
    }

    private function writeArchive(string $path, Header $header, ?FrameCipher $cipher): void
    {
        $stream = fopen($path, 'wb');
        self::assertIsResource($stream);
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), $cipher);
        $writer->writeJson(FrameType::SITE_METADATA, [
            'site_url' => 'https://source.example',
            'home_url' => 'https://source.example/home',
        ]);
        $writer->finalize();
        fclose($stream);
    }
}
