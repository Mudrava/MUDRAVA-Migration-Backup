<?php

/**
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */


declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Crypto\CryptoCapability;
use Mudrava\Migration\Crypto\FrameCipher;
use Mudrava\Migration\Crypto\KeyDerivation;
use PHPUnit\Framework\TestCase;

final class CryptoBoundsTest extends TestCase
{
    public function testCapabilityDescriptionsAndKdfMappingAreStable(): void
    {
        $none = CryptoCapability::none();
        $this->assertFalse($none->available);
        $this->assertSame(CryptoCapability::STACK_NONE, $none->stack);
        $this->assertSame('no supported crypto extension', $none->describe());
        $this->assertFalse(CryptoCapability::supports('untrusted-stack'));
        $this->assertSame(CryptoCapability::STACK_SODIUM, CryptoCapability::stackForKdf(KeyDerivation::KDF_ARGON2ID));
        $this->assertSame(CryptoCapability::STACK_OPENSSL, CryptoCapability::stackForKdf(KeyDerivation::KDF_PBKDF2));

        $openssl = $this->capability(CryptoCapability::STACK_OPENSSL, true);
        $this->assertStringContainsString('OpenSSL', $openssl->describe());
        $parameters = KeyDerivation::parameters($openssl);
        $this->assertSame(KeyDerivation::KDF_PBKDF2, $parameters['kdf']);
        $this->assertSame(KeyDerivation::SALT_BYTES, strlen($parameters['salt']));

        $sodium = $this->capability(CryptoCapability::STACK_SODIUM, true);
        $this->assertStringContainsString('libsodium', $sodium->describe());
    }

    public function testUnknownKdfAndUnavailableParametersAreRejected(): void
    {
        try {
            CryptoCapability::stackForKdf(255);
            $this->fail('unknown encrypted KDF accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unknown encrypted KDF', $e->getMessage());
        }

        $this->expectExceptionMessage('MUDRAVA_CRYPTO_UNAVAILABLE');
        KeyDerivation::parameters(CryptoCapability::none());
    }

    /**
     * @dataProvider invalidDerivationProvider
     */
    public function testInvalidDerivationInputsAreRejected(string $password, int $kdf, string $salt, string $message): void
    {
        $this->expectExceptionMessage($message);
        KeyDerivation::derive($password, $kdf, 1, 1, $salt);
    }

    /** @return array<string,array{string,int,string,string}> */
    public function invalidDerivationProvider(): array
    {
        return [
            'empty password' => ['', KeyDerivation::KDF_PBKDF2, random_bytes(16), 'Empty password'],
            'short salt' => ['password', KeyDerivation::KDF_PBKDF2, 'short', 'invalid KDF salt'],
            'unknown id' => ['password', 255, random_bytes(16), 'unknown KDF id'],
        ];
    }

    public function testPbkdf2DefaultsAndReturnsFixedLengthKey(): void
    {
        $key = KeyDerivation::derive('password', KeyDerivation::KDF_PBKDF2, 0, 0, str_repeat('s', 16));
        $this->assertSame(KeyDerivation::KEY_BYTES, strlen($key));
    }

    public function testUntrustedKdfCostIsRejectedBeforeWork(): void
    {
        $this->expectExceptionMessage('excessive KDF cost');
        KeyDerivation::derive('password', KeyDerivation::KDF_PBKDF2, 5000001, 0, random_bytes(16));
    }

    public function testUntrustedArgonMemoryIsRejectedBeforeAllocation(): void
    {
        $this->expectExceptionMessage('excessive KDF cost');
        KeyDerivation::derive('password', KeyDerivation::KDF_ARGON2ID, 3, 268435457, random_bytes(16));
    }

    public function testGcmSequenceCannotReuseAnEarlierNonce(): void
    {
        $cipher = new FrameCipher(random_bytes(32), random_bytes(8), CryptoCapability::STACK_OPENSSL);
        $this->expectExceptionMessage('nonce sequence exhausted');
        $cipher->encrypt('payload', 1, 0x100000001);
    }

    private function capability(string $stack, bool $available): CryptoCapability
    {
        $reflection = new \ReflectionClass(CryptoCapability::class);
        /** @var CryptoCapability $capability */
        $capability = $reflection->newInstanceWithoutConstructor();
        $capability->stack = $stack;
        $capability->available = $available;
        return $capability;
    }
}
