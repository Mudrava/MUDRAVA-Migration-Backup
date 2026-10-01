<?php

/**
 * Detects which vetted crypto stack is available. Never invents crypto.
 *
 * Primary:  libsodium - Argon2id KDF + XChaCha20-Poly1305 AEAD.
 * Fallback: OpenSSL  - PBKDF2-HMAC-SHA256 KDF + AES-256-GCM AEAD.
 * None:     encryption unavailable; the UI must say so explicitly and the
 *           user may opt into an unencrypted migration. Silent downgrade
 *           is forbidden.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Crypto;

final class CryptoCapability
{
    public const STACK_NONE    = 'none';
    public const STACK_SODIUM  = 'sodium';
    public const STACK_OPENSSL = 'openssl';

    /** @var string */
    public $stack;

    /** @var bool */
    public $available;

    private function __construct(string $stack, bool $available)
    {
        $this->stack = $stack;
        $this->available = $available;
    }

    public static function detect(): self
    {
        if (self::supports(self::STACK_SODIUM)) {
            return new self(self::STACK_SODIUM, true);
        }
        if (self::supports(self::STACK_OPENSSL)) {
            return new self(self::STACK_OPENSSL, true);
        }
        return new self(self::STACK_NONE, false);
    }

    public static function supports(string $stack): bool
    {
        if ($stack === self::STACK_SODIUM) {
            return function_exists('sodium_crypto_pwhash')
                && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
                && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')
                && defined('SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13');
        }
        if ($stack === self::STACK_OPENSSL) {
            return function_exists('openssl_encrypt')
                && function_exists('openssl_decrypt')
                && in_array('aes-256-gcm', \openssl_get_cipher_methods(true), true)
                && function_exists('hash_pbkdf2');
        }
        return false;
    }

    public static function none(): self
    {
        return new self(self::STACK_NONE, false);
    }

    /**
     * The archive header records the KDF, which also identifies the AEAD
     * stack used to seal its frames. Restore code must follow that header
     * instead of preferring whichever extension exists on the destination.
     */
    public static function stackForKdf(int $kdf): string
    {
        if ($kdf === KeyDerivation::KDF_ARGON2ID) {
            return self::STACK_SODIUM;
        }
        if ($kdf === KeyDerivation::KDF_PBKDF2) {
            return self::STACK_OPENSSL;
        }
        throw new \RuntimeException('MUDRAVA_FORMAT_UNSUPPORTED: unknown encrypted KDF');
    }

    public function describe(): string
    {
        if ($this->stack === self::STACK_SODIUM) {
            return 'libsodium (Argon2id + XChaCha20-Poly1305)';
        }
        if ($this->stack === self::STACK_OPENSSL) {
            return 'OpenSSL (PBKDF2-SHA256 + AES-256-GCM)';
        }
        return 'no supported crypto extension';
    }
}
