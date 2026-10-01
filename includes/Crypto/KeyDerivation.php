<?php

/**
 * Password-based key derivation. The password itself is never stored -
 * only the salt and KDF parameters live in the archive header.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Crypto;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are reduced to safe codes at the REST boundary.

final class KeyDerivation
{
    public const KDF_NONE    = 0;
    public const KDF_ARGON2ID = 1;
    public const KDF_PBKDF2  = 2;

    // Interactive limits; importers can raise via header values.
    public const DEFAULT_OPSLIMIT = 3;       // sodium_interactive
    public const DEFAULT_MEMLIMIT = 67108864; // 64 MiB
    public const PBKDF2_ITERATIONS = 600000;
    public const SALT_BYTES = 16;
    public const KEY_BYTES = 32;
    public const MAX_ARGON_OPSLIMIT = 10;
    public const MAX_ARGON_MEMLIMIT = 268435456; // 256 MiB
    public const MAX_PBKDF2_ITERATIONS = 5000000;

    /**
     * @return array{kdf:int,opslimit:int,memlimit:int,salt:string}
     */
    public static function parameters(CryptoCapability $cap): array
    {
        if ($cap->stack === CryptoCapability::STACK_SODIUM) {
            return [
                'kdf'      => self::KDF_ARGON2ID,
                'opslimit' => self::DEFAULT_OPSLIMIT,
                'memlimit' => self::DEFAULT_MEMLIMIT,
                'salt'     => random_bytes(self::SALT_BYTES),
            ];
        }
        if ($cap->stack === CryptoCapability::STACK_OPENSSL) {
            return [
                'kdf'      => self::KDF_PBKDF2,
                'opslimit' => self::PBKDF2_ITERATIONS,
                'memlimit' => 0,
                'salt'     => random_bytes(self::SALT_BYTES),
            ];
        }
        throw new \RuntimeException('MUDRAVA_CRYPTO_UNAVAILABLE: no crypto stack');
    }

    public static function derive(string $password, int $kdf, int $opslimit, int $memlimit, string $salt): string
    {
        if ($password === '') {
            throw new \InvalidArgumentException('Empty password.');
        }
        if (strlen($salt) !== self::SALT_BYTES) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: invalid KDF salt');
        }
        if ($kdf === self::KDF_ARGON2ID) {
            $ops = $opslimit > 0 ? $opslimit : self::DEFAULT_OPSLIMIT;
            $mem = $memlimit > 0 ? $memlimit : self::DEFAULT_MEMLIMIT;
            if ($ops > self::MAX_ARGON_OPSLIMIT || $mem > self::MAX_ARGON_MEMLIMIT) {
                throw new \RuntimeException('MUDRAVA_FORMAT_UNSUPPORTED: excessive KDF cost');
            }
            if (!function_exists('sodium_crypto_pwhash')) {
                throw new \RuntimeException('MUDRAVA_CRYPTO_UNAVAILABLE: libsodium required for Argon2id');
            }
            return \sodium_crypto_pwhash(
                self::KEY_BYTES,
                $password,
                $salt,
                $ops,
                $mem,
                \SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
            );
        }
        if ($kdf === self::KDF_PBKDF2) {
            $iterations = $opslimit > 0 ? $opslimit : self::PBKDF2_ITERATIONS;
            if ($iterations > self::MAX_PBKDF2_ITERATIONS) {
                throw new \RuntimeException('MUDRAVA_FORMAT_UNSUPPORTED: excessive KDF cost');
            }
            $raw = \hash_pbkdf2('sha256', $password, $salt, $iterations, self::KEY_BYTES * 2, true);
            return substr($raw, 0, self::KEY_BYTES);
        }
        throw new \RuntimeException('MUDRAVA_FORMAT_UNSUPPORTED: unknown KDF id ' . $kdf);
    }
}
