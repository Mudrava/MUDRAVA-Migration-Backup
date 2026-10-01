<?php

/**
 * Per-frame authenticated encryption.
 *
 * Nonce is derived (never stored): prefix || sequence. This guarantees
 * uniqueness because frame sequences are strictly monotonic per archive.
 *
 * AAD binds frame_type + sequence so a frame cannot be replayed into a
 * different position or role.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Crypto;

final class FrameCipher
{
    public const TAG_BYTES = 16;
    public const SODIUM_NONCE_BYTES = 24;
    public const GCM_NONCE_BYTES = 12;

    /** @var string */
    private $key;

    /** @var string */
    private $noncePrefix;

    /** @var string */
    private $stack;

    public function __construct(string $key, string $noncePrefix, string $stack)
    {
        if (strlen($key) !== 32) {
            throw new \InvalidArgumentException('Key must be 32 bytes.');
        }
        $this->key = $key;
        $this->noncePrefix = $noncePrefix;
        $this->stack = $stack;
    }

    private function nonce(int $sequence, int $length): string
    {
        if ($sequence < 1 || ($length === self::GCM_NONCE_BYTES && $sequence > 0xffffffff)) {
            throw new \RuntimeException('MUDRAVA_FORMAT_UNSUPPORTED: nonce sequence exhausted');
        }
        $seq = pack('P', $sequence); // 8 bytes LE
        $base = $this->noncePrefix . $seq;
        if (strlen($base) >= $length) {
            return substr($base, 0, $length);
        }
        return $base . str_repeat("\0", $length - strlen($base));
    }

    private function aad(int $frameType, int $sequence): string
    {
        return chr($frameType) . pack('P', $sequence);
    }

    /**
     * @return string ciphertext || tag(16)
     */
    public function encrypt(string $plaintext, int $frameType, int $sequence): string
    {
        $aad = $this->aad($frameType, $sequence);
        if ($this->stack === CryptoCapability::STACK_SODIUM) {
            $ct = \sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintext,
                $aad,
                $this->nonce($sequence, self::SODIUM_NONCE_BYTES),
                $this->key
            );
            return $ct; // sodium appends the 16-byte tag
        }
        if ($this->stack === CryptoCapability::STACK_OPENSSL) {
            $tag = '';
            $ct = \openssl_encrypt(
                $plaintext,
                'aes-256-gcm',
                $this->key,
                OPENSSL_RAW_DATA,
                $this->nonce($sequence, self::GCM_NONCE_BYTES),
                $tag,
                $aad,
                self::TAG_BYTES
            );
            if ($ct === false || strlen($tag) !== self::TAG_BYTES) {
                throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: openssl encrypt failed');
            }
            return $ct . $tag;
        }
        throw new \RuntimeException('MUDRAVA_CRYPTO_UNAVAILABLE');
    }

    /**
     * @throws \RuntimeException MUDRAVA_WRONG_PASSWORD on auth failure
     */
    public function decrypt(string $payload, int $frameType, int $sequence): string
    {
        if (strlen($payload) < self::TAG_BYTES) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: payload shorter than tag');
        }
        $aad = $this->aad($frameType, $sequence);
        if ($this->stack === CryptoCapability::STACK_SODIUM) {
            $pt = @\sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $payload,
                $aad,
                $this->nonce($sequence, self::SODIUM_NONCE_BYTES),
                $this->key
            );
            if ($pt === false) {
                throw new \RuntimeException('MUDRAVA_WRONG_PASSWORD');
            }
            return $pt;
        }
        if ($this->stack === CryptoCapability::STACK_OPENSSL) {
            $ct = substr($payload, 0, strlen($payload) - self::TAG_BYTES);
            $tag = substr($payload, -self::TAG_BYTES);
            $pt = @\openssl_decrypt(
                $ct,
                'aes-256-gcm',
                $this->key,
                OPENSSL_RAW_DATA,
                $this->nonce($sequence, self::GCM_NONCE_BYTES),
                $tag,
                $aad
            );
            if ($pt === false) {
                throw new \RuntimeException('MUDRAVA_WRONG_PASSWORD');
            }
            return $pt;
        }
        throw new \RuntimeException('MUDRAVA_CRYPTO_UNAVAILABLE');
    }
}
