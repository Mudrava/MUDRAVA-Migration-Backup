<?php

/**
 * Archive header (part 1) and continuation header (parts 2+).
 * Fixed-size core fields, little-endian, forward-skippable via header_size.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Archive;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are reduced to safe codes at the REST boundary.

final class Header
{
    public const MAGIC_PART1   = "MUDRAVA\0";
    public const MAGIC_CONT    = "MUDRAVAP";
    public const MAGIC_TAIL    = "MUDRAVAE";
    public const CONTAINER_FORMAT = 1;

    // public_flags bits
    public const FLAG_ENCRYPTED   = 1;
    public const FLAG_SPLIT_SET   = 2;

    /** @var int */
    public $containerFormat;
    /** @var int */
    public $flags;
    /** @var string 16 raw bytes */
    public $archiveUuid;
    /** @var string */
    public $producerVersion;
    /** @var int */
    public $kdf;
    /** @var int */
    public $kdfOpslimit;
    /** @var int */
    public $kdfMemlimit;
    /** @var string 16 raw bytes */
    public $kdfSalt;
    /** @var string 8 raw bytes */
    public $noncePrefix;
    /** @var string optional plaintext hint; intentionally NOT secret (spec §9) */
    public $passwordHint;

    public function __construct(
        int $containerFormat,
        int $flags,
        string $archiveUuid,
        string $producerVersion,
        int $kdf,
        int $kdfOpslimit,
        int $kdfMemlimit,
        string $kdfSalt,
        string $noncePrefix,
        string $passwordHint = ''
    ) {
        $this->containerFormat = $containerFormat;
        $this->flags = $flags;
        $this->archiveUuid = $archiveUuid;
        $this->producerVersion = $producerVersion;
        $this->kdf = $kdf;
        $this->kdfOpslimit = $kdfOpslimit;
        $this->kdfMemlimit = $kdfMemlimit;
        $this->kdfSalt = $kdfSalt;
        $this->noncePrefix = $noncePrefix;
        $this->passwordHint = $passwordHint;
    }

    public function isEncrypted(): bool
    {
        return ($this->flags & self::FLAG_ENCRYPTED) !== 0;
    }

    public function isSplitSet(): bool
    {
        return ($this->flags & self::FLAG_SPLIT_SET) !== 0;
    }

    public function encode(): string
    {
        $pv = $this->producerVersion;
        if (strlen($pv) > 255) {
            throw new \InvalidArgumentException('producer_version too long.');
        }
        // Body after magic+format+header_size: flags(u32) uuid(16) pvlen(u8) pv
        // kdf(u8) opslimit(u32) memlimit(u32) salt(16) nonce(8)
        // Optional trailing (skippable via header_size): hint_len(u16) hint.
        // Empty hint writes nothing, so pre-hint archives stay byte-identical.
        $body = pack('V', $this->flags)
            . $this->archiveUuid
            . chr(strlen($pv)) . $pv
            . chr($this->kdf)
            . pack('V', $this->kdfOpslimit)
            . pack('V', $this->kdfMemlimit)
            . str_pad($this->kdfSalt, 16, "\0")
            . str_pad($this->noncePrefix, 8, "\0");
        $headerSize = 12 + strlen($body);
        if ($this->passwordHint !== '') {
            $hintLength = strlen($this->passwordHint);
            if ($hintLength > 65535 - $headerSize - 2) {
                throw new \InvalidArgumentException('password_hint too long for header.');
            }
            $body .= pack('v', $hintLength) . $this->passwordHint;
            $headerSize += 2 + $hintLength;
        }
        // header_size counts: magic(8) + format(u16) + header_size(u16) + body
        return self::MAGIC_PART1 . pack('v', $this->containerFormat) . pack('v', $headerSize) . $body;
    }

    /**
     * Parse a part-1 header from the start of a stream.
     *
     * @param string $bytes at least the fixed prefix; may be longer.
     */
    public static function decode(string $bytes): self
    {
        if (strlen($bytes) < 12 || substr($bytes, 0, 8) !== self::MAGIC_PART1) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: bad magic');
        }
        // Layout: magic(8) format(u16) header_size(u16) flags(u32) uuid(16)
        // pvlen(u8) pv(k) kdf(u8) opslimit(u32) memlimit(u32) salt(16) nonce(8)
        $un = \Mudrava\Migration\Support\Binary::unpack('vformat/vheaderSize', substr($bytes, 8, 4));
        $format = (int) $un['format'];
        $headerSize = (int) $un['headerSize'];
        if ($format !== self::CONTAINER_FORMAT) {
            throw new \RuntimeException('MUDRAVA_FORMAT_UNSUPPORTED: container_format ' . $format);
        }
        if ($headerSize < 66 || strlen($bytes) < $headerSize) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: bad header_size');
        }
        $flags = \Mudrava\Migration\Support\Binary::scalar('V', substr($bytes, 12, 4));
        $uuid = substr($bytes, 16, 16);
        $pvLen = ord($bytes[32]);
        $pv = substr($bytes, 33, $pvLen);
        $off = 33 + $pvLen;
        // Fixed tail after producer version: kdf(1)+ops(4)+mem(4)+salt(16)+nonce(8) = 33
        if ($headerSize < $off + 33 || strlen($bytes) < $headerSize) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: bad header_size');
        }
        $kdf = ord($bytes[$off]);
        $ops = \Mudrava\Migration\Support\Binary::scalar('V', substr($bytes, $off + 1, 4));
        $mem = \Mudrava\Migration\Support\Binary::scalar('V', substr($bytes, $off + 5, 4));
        $salt = substr($bytes, $off + 9, 16);
        $nonce = substr($bytes, $off + 25, 8);
        // Optional hint tail: hint_len(u16) + hint, bounded by header_size.
        $hint = '';
        $tail = $off + 33;
        if ($headerSize > $tail + 2 && strlen($bytes) >= $tail + 2) {
            $hl = (int) \Mudrava\Migration\Support\Binary::scalar('v', substr($bytes, $tail, 2));
            if ($hl > 0 && strlen($bytes) >= $tail + 2 + $hl) {
                $hint = substr($bytes, $tail + 2, $hl);
            }
        }
        return new self((int) $format, (int) $flags, $uuid, $pv, $kdf, (int) $ops, (int) $mem, $salt, $nonce, $hint);
    }

    /**
     * Encode a continuation header for parts 2+.
     */
    public static function encodeContinuation(string $archiveUuid, int $partNumber): string
    {
        return self::MAGIC_CONT . $archiveUuid . pack('V', $partNumber);
    }

    /**
     * @return array{archive_uuid:string,part_number:int}
     */
    public static function decodeContinuation(string $bytes): array
    {
        if (strlen($bytes) < 28 || substr($bytes, 0, 8) !== self::MAGIC_CONT) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: bad continuation magic');
        }
        $part = \Mudrava\Migration\Support\Binary::scalar('V', substr($bytes, 24, 4));
        return ['archive_uuid' => substr($bytes, 8, 16), 'part_number' => (int) $part];
    }

    public static function uuidHex(string $uuid): string
    {
        return bin2hex($uuid);
    }
}
