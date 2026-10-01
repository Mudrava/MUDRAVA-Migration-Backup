<?php

/**
 * Forward-only streaming reader. One parser for local files, split sets,
 * HTTP bodies and browser uploads (spec §"reader must be able to go only
 * forward").
 *
 * Verifies sync, sequence monotonicity, CRC32 and AEAD tags. Recomputes the
 * root-hash chain so callers can compare against the manifest.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Archive;

use Mudrava\Migration\Compression\DeflateCodec;
use Mudrava\Migration\Crypto\FrameCipher;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped,WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Internal parser exceptions never reach HTML, and binary frames require seekable streams.

final class FrameReader
{
    public const SYNC = 'MUDF';
    public const FRAME_HEADER_BYTES = 26; // sync4+type1+flags1+seq8+stored4+logical4+crc4
    public const MAX_STORED_BYTES = FrameWriter::MAX_FRAME_BYTES + 1024;

    /** @var resource */
    private $stream;

    /** @var DeflateCodec */
    private $codec;

    /** @var FrameCipher|null */
    private $cipher;

    /** @var RootHash */
    private $rootHash;

    /** @var int */
    private $expectedSequence = 1;

    /** @var bool */
    private $headerRead = false;

    /** @var Header|null */
    private $header;

    /** @var bool */
    private $eof = false;

    /** @var int logical bytes consumed, including optional unknown frames */
    private $logicalBytes = 0;

    /** @var string|null root-hash state captured before the MANIFEST frame */
    private $hashBeforeManifest = null;

    /** @var int|null sequence of the MANIFEST frame */
    private $manifestSequence = null;

    /** @var array<int,int> */
    private $typeCounts = [];

    /** @var callable|null fn(): resource|null - called to fetch the next part stream */
    private $nextPartProvider;

    /** @var int current physical part number (1-based); advanced on rotation */
    private $part = 1;

    /**
     * @param resource $stream positioned at the start of part 1.
     */
    public function __construct(
        $stream,
        DeflateCodec $codec,
        ?FrameCipher $cipher = null,
        ?callable $nextPartProvider = null
    ) {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('Stream resource required.');
        }
        $this->stream = $stream;
        $this->codec = $codec;
        $this->cipher = $cipher;
        $this->nextPartProvider = $nextPartProvider;
        $this->rootHash = new RootHash(str_repeat("\0", 16)); // replaced after header
    }

    public function readHeader(): Header
    {
        if ($this->headerRead) {
            /** @var Header */
            $h = $this->header;
            return $h;
        }
        $prefix = $this->readBytesOrFail(12, 'header prefix');
        if (substr($prefix, 0, 8) !== Header::MAGIC_PART1) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: bad magic');
        }
        $un = self::unpackOrFail('vformat/vheaderSize', substr($prefix, 8, 4));
        $rest = $this->readBytesOrFail((int) $un['headerSize'] - 12, 'header body');
        $this->header = Header::decode($prefix . $rest);
        $this->rootHash = new RootHash($this->header->archiveUuid);
        $this->headerRead = true;
        return $this->header;
    }

    /**
     * Read the next frame, or null at clean EOF (after tail magic).
     */
    public function next(): ?Frame
    {
        if ($this->eof) {
            return null;
        }
        if (!$this->headerRead) {
            $this->readHeader();
        }

        $head = $this->readMaybe(8);
        if ($head === null) {
            // Abrupt end without tail magic.
            throw new \RuntimeException('MUDRAVA_ARCHIVE_TRUNCATED: unexpected EOF (last sequence ' . ($this->expectedSequence - 1) . ')');
        }
        if ($head === Header::MAGIC_TAIL) {
            // Tail terminates this physical stream. A split provider may
            // report a missing next part as an error, so do not rotate here.
            $trailing = fread($this->stream, 1);
            if ($trailing === false || $trailing !== '') {
                throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: data after tail');
            }
            $this->eof = true;
            return null;
        }
        if ($head === Header::MAGIC_CONT) {
            // Part boundary: consume continuation header and continue.
            $cont = $this->readBytesOrFail(20, 'continuation header');
            $decoded = Header::decodeContinuation($head . $cont);
            /** @var Header $header */
            $header = $this->header;
            if ($decoded['archive_uuid'] !== $header->archiveUuid) {
                throw new \RuntimeException('MUDRAVA_PART_MISMATCH: wrong archive UUID in part ' . $decoded['part_number']);
            }
            $this->assertPartContinuity((int) $decoded['part_number']);
            $this->part = (int) $decoded['part_number'];
            return $this->next();
        }
        if (substr($head, 0, 4) !== self::SYNC) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: bad frame sync at sequence ' . $this->expectedSequence);
        }

        $fixed = substr($head, 4) . $this->readBytesOrFail(18, 'frame header');
        $type = ord($fixed[0]);
        $flags = ord($fixed[1]);
        $seq = self::unpackOrFail('P', substr($fixed, 2, 8))[1];
        $storedLen = self::unpackOrFail('N', substr($fixed, 10, 4))[1];
        $logicalLen = self::unpackOrFail('N', substr($fixed, 14, 4))[1];
        $crc = self::unpackOrFail('N', substr($fixed, 18, 4))[1];

        if ($seq !== $this->expectedSequence) {
            throw new \RuntimeException("MUDRAVA_ARCHIVE_CORRUPT: sequence gap (expected {$this->expectedSequence}, got {$seq})");
        }
        if ($storedLen > self::MAX_STORED_BYTES) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: implausible stored_len ' . $storedLen);
        }
        if ($logicalLen > FrameWriter::MAX_FRAME_BYTES) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: implausible logical_len ' . $logicalLen);
        }
        $this->assertFrameFitsMemory((int) $storedLen, (int) $logicalLen);

        $stored = $this->readBytesOrFail((int) $storedLen, 'frame payload');
        if (crc32($stored) !== $crc) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: crc mismatch at sequence ' . $seq);
        }

        // The manifest embeds the chain state BEFORE itself; capture it here.
        if ($type === FrameType::MANIFEST) {
            $this->hashBeforeManifest = $this->rootHash->hex();
            $this->manifestSequence = (int) $seq;
        }

        $this->rootHash->update($type, (int) $seq, (int) $crc);
        $this->typeCounts[$type] = ($this->typeCounts[$type] ?? 0) + 1;
        $this->expectedSequence++;

        if (!FrameType::isKnown($type)) {
            if (FrameType::isOptional($type)) {
                $this->logicalBytes += (int) $logicalLen;
                return new Frame($type, (int) $seq, '', false, false); // skippable
            }
            throw new \RuntimeException('MUDRAVA_FORMAT_UNSUPPORTED: frame type 0x' . dechex($type));
        }

        $compressed = ($flags & 1) !== 0;
        $encrypted = ($flags & 2) !== 0;

        $payload = $stored;
        if ($encrypted) {
            if ($this->cipher === null) {
                throw new \RuntimeException('MUDRAVA_WRONG_PASSWORD: encrypted frame but no key provided');
            }
            $payload = $this->cipher->decrypt($payload, $type, (int) $seq);
        }
        $payload = $this->codec->decompress($payload, $compressed, (int) $logicalLen);

        if (strlen($payload) !== (int) $logicalLen) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: logical length mismatch at sequence ' . $seq);
        }
        $this->logicalBytes += strlen($payload);

        return new Frame($type, (int) $seq, $payload, $compressed, $encrypted);
    }

    /**
     * Verify manifest fields against the recomputed chain.
     *
     * @param array<mixed> $manifest
     */
    public function verifyAgainstManifest(array $manifest): void
    {
        $expected = (string) ($manifest['root_hash'] ?? '');
        $actual = $this->hashBeforeManifest ?? '';
        if ($actual === '' || !hash_equals($expected, $actual)) {
            throw new \RuntimeException('MUDRAVA_MANIFEST_MISMATCH: root_hash');
        }
        $frameCount = (int) ($manifest['frame_count'] ?? -1);
        if ($frameCount !== $this->manifestSequence) {
            throw new \RuntimeException('MUDRAVA_MANIFEST_MISMATCH: frame_count');
        }
    }

    public function rootHashHex(): string
    {
        return $this->rootHash->hex();
    }

    /** Sequence of the last frame consumed (0 before any frame). */
    public function lastSequence(): int
    {
        return $this->expectedSequence - 1;
    }

    /**
     * Current physical position: the active part number and the byte offset
     * within it (a frame boundary when called between next() calls). Used to
     * persist a seek-based resume point so a resumed tick does not re-read
     * the whole archive.
     *
     * @return array{part:int,offset:int}
     */
    public function position(): array
    {
        $offset = $this->stream === null ? 0 : (int) ftell($this->stream);
        return ['part' => $this->part, 'offset' => $offset];
    }

    /**
     * Seek-based resume: position the reader at a durable frame boundary
     * (part + offset) with the root-hash chain and sequence restored, so
     * reading continues exactly where a previous process stopped - without
     * re-reading (or re-decrypting) the completed prefix. The unit that
     * begins at this boundary is reprocessed from scratch by the caller, so
     * it must be idempotent (DROP+CREATE table, or beginFile 'wb').
     */
    public function resumeTo(int $part, int $offset, int $sequence, string $rootHashHex, int $logicalBytes = 0): void
    {
        $this->readHeader();
        while ($this->part < $part) {
            if (!$this->rotatePart()) {
                throw new \RuntimeException('MUDRAVA_PART_MISSING: cannot reach part ' . $part);
            }
        }
        if (@fseek($this->stream, $offset, SEEK_SET) !== 0) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: seek failed at resume');
        }
        $raw = hex2bin($rootHashHex);
        if ($raw === false || strlen($raw) !== 32) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: bad resume root_hash');
        }
        $this->rootHash = RootHash::fromState($raw);
        $this->expectedSequence = $sequence + 1;
        $this->logicalBytes = $logicalBytes;
        $this->eof = false;
    }

    public function logicalBytes(): int
    {
        return $this->logicalBytes;
    }

    /** Reject a frame before PHP fatally exhausts a finite memory limit. */
    private function assertFrameFitsMemory(int $storedLen, int $logicalLen): void
    {
        $setting = strtoupper(trim((string) ini_get('memory_limit')));
        if ($setting === '-1' || preg_match('/^([0-9]+)([KMG]?)$/D', $setting, $matches) !== 1) {
            return;
        }
        $factor = ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824][$matches[2]];
        $number = (int) $matches[1];
        if ($number <= 0 || $number > intdiv(PHP_INT_MAX, $factor)) {
            return;
        }
        $limit = $number * $factor;
        // Reading, decrypting and inflating can briefly retain both input
        // and output buffers. Leave room for WordPress and zlib allocations.
        $needed = memory_get_usage(true) + 2 * $storedLen + 2 * $logicalLen + 8 * 1048576;
        if ($needed > $limit) {
            throw new \RuntimeException('MUDRAVA_MEMORY_LIMIT: frame exceeds PHP memory budget');
        }
    }

    /** @return array<int,int> */
    public function typeCounts(): array
    {
        return $this->typeCounts;
    }

    public function isEof(): bool
    {
        return $this->eof;
    }

    /**
     * @return string|null null on clean EOF before any byte
     */
    private function readMaybe(int $n): ?string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->stream, max(1, $n - strlen($buf)));
            if ($chunk === false || $chunk === '') {
                if (feof($this->stream)) {
                    if ($this->rotatePart()) {
                        continue; // continue reading from next part
                    }
                    return $buf === '' ? null : $this->truncated($buf, $n);
                }
                throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: read error');
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private function truncated(string $buf, int $n): string
    {
        // Partial magic at EOF: treat as truncation.
            throw new \RuntimeException(
                'MUDRAVA_ARCHIVE_TRUNCATED: partial data at EOF (got ' . strlen($buf)
                . '/' . $n . ' bytes, last sequence ' . ($this->expectedSequence - 1) . ')'
            );
    }

    private function readBytesOrFail(int $n, string $what): string
    {
        $buf = $this->readMaybe($n);
        if ($buf === null || strlen($buf) < $n) {
            throw new \RuntimeException("MUDRAVA_ARCHIVE_TRUNCATED: incomplete {$what}");
        }
        return $buf;
    }

    /**
     * Attempt to switch to the next physical part. Returns true on success.
     */
    private function rotatePart(): bool
    {
        if ($this->nextPartProvider === null) {
            return false;
        }
        $next = ($this->nextPartProvider)();
        if (!is_resource($next)) {
            return false;
        }
        $this->stream = $next;
        // Validate continuation header.
        $cont = $this->readBytesOrFail(28, 'continuation header');
        $decoded = Header::decodeContinuation($cont);
        /** @var Header $header */
        $header = $this->header;
        if ($decoded['archive_uuid'] !== $header->archiveUuid) {
            throw new \RuntimeException('MUDRAVA_PART_MISMATCH: wrong archive UUID in part ' . $decoded['part_number']);
        }
        $this->assertPartContinuity((int) $decoded['part_number']);
        $this->part = (int) $decoded['part_number'];
        return true;
    }

    /**
     * Split-set parts must arrive as an unbroken 1,2,3,... chain. A wrong
     * slot (mis-renamed file, spliced set) must fail at the boundary with
     * the part numbers named, never skip silently mid-stream.
     */
    private function assertPartContinuity(int $partNumber): void
    {
        if ($partNumber !== $this->part + 1) {
            throw new \RuntimeException(
                'MUDRAVA_PART_MISMATCH: expected part ' . sprintf('%04d', $this->part + 1)
                . ', got ' . sprintf('%04d', $partNumber)
            );
        }
    }

    /**
     * Scan a retained archive prefix WITHOUT decrypting payloads.
     *
     * The root-hash chain is computed from stored-payload CRCs, so a crashed
     * export can be resumed exactly: truncate to the checkpoint offset, then
     * re-derive sequence / root hash / counts from the surviving bytes.
     *
     * Stops cleanly at the last intact frame; trailing garbage is ignored
     * (it will be truncated away by the caller). Supports split sets via
     * $nextPartProvider (same contract as the constructor).
     *
     * @param resource $stream positioned at the start of part 1
     * @param callable|null $nextPartProvider fn(): resource|null
     * @return array{sequence:int,root_hash:string,logical_bytes:int,type_counts:array<int,int>,physical_bytes:int,part_offset:int,part:int,header:Header}
     */
    public static function scanPrefix($stream, ?callable $nextPartProvider = null): array
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('Stream resource required.');
        }
        $headerBytes = self::readExactly($stream, 12);
        if ($headerBytes === null || substr($headerBytes, 0, 8) !== Header::MAGIC_PART1) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: bad magic in scan');
        }
        $un = self::unpackOrFail('vformat/vheaderSize', substr($headerBytes, 8, 4));
        $rest = self::readExactly($stream, (int) $un['headerSize'] - 12);
        if ($rest === null || strlen($rest) !== (int) $un['headerSize'] - 12) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_TRUNCATED: header in scan');
        }
        $header = Header::decode($headerBytes . $rest);
        $root = new RootHash($header->archiveUuid);

        $sequence = 0;
        $logical = 0;
        $physical = 0;
        $typeCounts = [];
        $part = 1;
        // Physical bytes consumed inside the CURRENT part (truncate target).
        $partOffset = (int) $un['headerSize'];

        while (true) {
            $sync = self::readExactly($stream, 4);
            if ($sync === null) {
                if ($nextPartProvider === null) {
                    break;
                }
                $next = $nextPartProvider();
                if (!is_resource($next)) {
                    break;
                }
                $stream = $next;
                $continuation = self::readExactly($stream, 28);
                if (
                    $continuation === null
                    || strlen($continuation) !== 28
                    || substr($continuation, 0, 8) !== Header::MAGIC_CONT
                ) {
                    break;
                }
                $decoded = Header::decodeContinuation($continuation);
                if (
                    $decoded['archive_uuid'] !== $header->archiveUuid
                    || (int) $decoded['part_number'] !== $part + 1
                ) {
                    break;
                }
                $part = (int) $decoded['part_number'];
                $partOffset = 28; // continuation header consumed in new part
                continue;
            }
            // Tail and continuation magics share the MUDR prefix. Frames use
            // MUDF, so read the remaining four magic bytes only for MUDR.
            if ($sync === substr(Header::MAGIC_TAIL, 0, 4)) {
                $magicRest = self::readExactly($stream, 4);
                if ($magicRest === null || strlen($magicRest) !== 4) {
                    break;
                }
                $magic = $sync . $magicRest;
                if ($magic === Header::MAGIC_TAIL) {
                    break; // complete archive
                }
                if ($magic === Header::MAGIC_CONT) {
                    $cont = self::readExactly($stream, 20);
                    if ($cont === null || strlen($cont) !== 20) {
                        break;
                    }
                    $decoded = Header::decodeContinuation($magic . $cont);
                    if (
                        $decoded['archive_uuid'] !== $header->archiveUuid
                        || (int) $decoded['part_number'] !== $part + 1
                    ) {
                        break;
                    }
                    $part = (int) $decoded['part_number'];
                    $partOffset = 28;
                    continue;
                }
                break;
            }
            if ($sync !== self::SYNC) {
                break; // trailing garbage: stop at last intact frame
            }
            $fixed = self::readExactly($stream, 22);
            if ($fixed === null || strlen($fixed) !== 22) {
                break;
            }
            $type = ord($fixed[0]);
            $seq = self::unpackOrFail('P', substr($fixed, 2, 8))[1];
            $storedLen = (int) self::unpackOrFail('N', substr($fixed, 10, 4))[1];
            $logicalLen = (int) self::unpackOrFail('N', substr($fixed, 14, 4))[1];
            $crc = (int) self::unpackOrFail('N', substr($fixed, 18, 4))[1];
            if (
                $seq !== $sequence + 1
                || $storedLen > self::MAX_STORED_BYTES
                || $logicalLen > FrameWriter::MAX_FRAME_BYTES
                || (!FrameType::isKnown($type) && !FrameType::isOptional($type))
            ) {
                break;
            }
            $payloadCrc = self::readPayloadCrc($stream, $storedLen);
            if ($payloadCrc === null || $payloadCrc !== $crc) {
                break; // truncated or corrupt frame body: discard
            }
            $sequence = (int) $seq;
            $logical += $logicalLen;
            $physical += 26 + $storedLen;
            $partOffset += 26 + $storedLen;
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
            $root->update($type, $sequence, $crc);
        }

        return [
            'sequence'       => $sequence,
            'root_hash'      => $root->hex(),
            'logical_bytes'  => $logical,
            'type_counts'    => $typeCounts,
            'physical_bytes' => $physical,
            'part_offset'    => $partOffset,
            'part'           => $part,
            'header'         => $header,
        ];
    }

    /**
     * Verify a stored frame body without holding the entire frame in memory.
     * A valid frame may contain 256 MiB, including on low-memory hosts.
     *
     * @param resource $stream
     */
    private static function readPayloadCrc($stream, int $length): ?int
    {
        $hash = hash_init('crc32b');
        $remaining = $length;
        while ($remaining > 0) {
            $chunk = fread($stream, min(65536, $remaining));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            hash_update($hash, $chunk);
            $remaining -= strlen($chunk);
        }

        return (int) hexdec(hash_final($hash));
    }

    /**
     * unpack() returns array|false; the binary contract guarantees success
     * for fixed-size inputs. Fail loudly instead of letting PHPStan (or a
     * corrupt build) silently pass false around.
     *
     * @return array<int|string,int|string>
     */
    private static function unpackOrFail(string $format, string $data): array
    {
        $un = unpack($format, $data);
        if ($un === false) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: unpack failed for ' . $format);
        }
        return $un;
    }

    /**
     * @param resource $stream
     * @return string|null null on clean EOF
     */
    private static function readExactly($stream, int $n): ?string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($stream, max(1, $n - strlen($buf)));
            if ($chunk === false || $chunk === '') {
                if (feof($stream)) {
                    return $buf === '' ? null : $buf;
                }
                return $buf === '' ? null : $buf;
            }
            $buf .= $chunk;
        }
        return $buf;
    }
}
