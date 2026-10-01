<?php

/**
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */


declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Crypto\CryptoCapability;
use Mudrava\Migration\Crypto\KeyDerivation;
use Mudrava\Migration\Migration\JobRunner;
use PHPUnit\Framework\TestCase;

final class JobRunnerCryptoSelectionTest extends TestCase
{
    public function testOpenSslArchiveUsesItsHeaderKdfEvenOnSodiumHost(): void
    {
        if (!CryptoCapability::supports(CryptoCapability::STACK_OPENSSL)) {
            $this->markTestSkipped('OpenSSL AES-GCM is unavailable');
        }
        $header = new Header(
            Header::CONTAINER_FORMAT,
            Header::FLAG_ENCRYPTED,
            random_bytes(16),
            'test',
            KeyDerivation::KDF_PBKDF2,
            1000,
            0,
            random_bytes(16),
            random_bytes(8)
        );
        $method = new \ReflectionMethod(JobRunner::class, 'cipherFromHeader');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $cipher = $method->invoke(null, $header, 'secret');
        $sealed = $cipher->encrypt('payload', 1, 1);
        self::assertSame('payload', $cipher->decrypt($sealed, 1, 1));
    }
}
