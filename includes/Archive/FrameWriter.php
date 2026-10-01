<?php

/**
 * Streaming archive writer. Append-only; never seeks backwards.
 *
 * Pipeline per frame: logical → compress → encrypt → store.
 * Emits header, frames with monotonic sequence, checkpoints, manifest,
 * footer and tail magic. Optionally splits at frame boundaries.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Archive;

use Mudrava\Migration\Compression\DeflateCodec;
use Mudrava\Migration\Crypto\FrameCipher;
use Mudrava\Migration\Crypto\KeyDerivation;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Protected binary archives require seekable native streams and atomic file operations.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are reduced to safe codes at the REST boundary.

final class FrameWriter
{
    public const SYNC = 'MUDF';
    public const MAX_FRAME_BYTES = 268435456; // 256 MiB hard ceiling

    /** @var resource */
    private $stream;

    /** @var DeflateCodec */
    private $codec;

    /** @var FrameCipher|null */
    private $cipher;

    /** @var Header */
    private $header;

    /** @var int */
    private $sequence = 0;

    /** @var RootHash */
    private $rootHash;

    /** @var array<int,int> */
    private $typeCounts = [];

    /** @var int */
    private $logicalBytes = 0;

    /** @var bool */
    private $headerWritten = false;

    /** @var bool */
    private $finalized = false;

    /** @var int current physical part number */
    private $part = 1;

    /** @var int bytes written to current part */
    private $partBytes = 0;

    /** @var int total physical bytes across all parts */
    private $physicalBytes = 0;

    /** @var int|null split threshold, bytes; null = no split */
    private $splitBytes;

    /** @var string|null base path for split parts */
    private $basePath;

    /** @var callable|null fn(int $newPart): void */
    private $onPartRotate;

    /** @var list<resource> streams opened by this writer (parts 2+) */
    private $ownedStreams = [];

    /**
     * @param resource $stream writable stream positioned at 0 of part 1.
     */
    public function __construct(
        $stream,
        Header $header,
        DeflateCodec $codec,
        ?FrameCipher $cipher,
        ?int $splitBytes = null,
        ?string $basePath = null,
        ?callable $onPartRotate = null
    ) {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('Stream resource required.');
        }
        $this->stream = $stream;
        $this->header = $header;
        $this->codec = $codec;
        $this->cipher = $cipher;
        $this->rootHash = new RootHash($header->archiveUuid);
        $this->splitBytes = $splitBytes !== null && $splitBytes > 0 ? $splitBytes : null;
        $this->basePath = $basePath;
        $this->onPartRotate = $onPartRotate;
    }

    public function headerBytes(): string
    {
        return $this->header->encode();
    }

    /**
     * Rebuild a writer from a scanPrefix() result so a crashed export can
     * continue with an unbroken frame sequence and root-hash chain.
     *
     * The caller must have already truncated the physical file to the
     * checkpoint offset and opened the (last) part stream in append mode.
     *
     * @param resource $stream positioned at the truncate offset of the current part
     * @param array{sequence:int,root_hash:string,logical_bytes:int,type_counts:array<int,int>,part:int} $scan
     */
    public static function resumeFrom(
        $stream,
        Header $header,
        DeflateCodec $codec,
        ?FrameCipher $cipher,
        array $scan,
        ?int $splitBytes = null,
        ?string $basePath = null,
        ?callable $onPartRotate = null
    ): self {
        $w = new self($stream, $header, $codec, $cipher, $splitBytes, $basePath, $onPartRotate);
        $w->headerWritten = true;
        $w->sequence = (int) $scan['sequence'];
        $w->logicalBytes = (int) $scan['logical_bytes'];
        $w->typeCounts = $scan['type_counts'];
        $w->part = (int) ($scan['part'] ?? 1);
        // Physical accounting must match the surviving prefix, or the next
        // checkpoint's offset would point into the middle of the file and
        // resume would truncate valid frames (or split parts at wrong sizes).
        $w->partBytes = (int) ($scan['part_offset'] ?? 0);
        $w->physicalBytes = (int) ($scan['physical_bytes'] ?? $scan['part_offset'] ?? 0);
        $raw = hex2bin((string) $scan['root_hash']);
        if ($raw === false || strlen($raw) !== 32) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: bad root_hash hex in resume scan');
        }
        $w->rootHash = RootHash::fromState($raw);
        return $w;
    }

    public function writeHeader(): void
    {
        if ($this->headerWritten) {
            throw new \LogicException('Header already written.');
        }
        $bytes = $this->header->encode();
        $this->rawWrite($bytes);
        $this->headerWritten = true;
    }

    /**
     * Write one frame. $payload is logical bytes (JSON for JSON types).
     */
    public function writeFrame(int $type, string $payload): Frame
    {
        if (!$this->headerWritten) {
            $this->writeHeader();
        }
        if ($this->finalized) {
            throw new \LogicException('Writer already finalized.');
        }
        if (strlen($payload) > self::MAX_FRAME_BYTES) {
            throw new \RuntimeException('Frame payload exceeds MAX_FRAME_BYTES; split it upstream.');
        }
        $this->sequence++;

        $stored = $payload;
        $compressed = false;
        $c = $this->codec->compress($payload);
        if (strlen($c) < strlen($payload)) {
            $stored = $c;
            $compressed = true;
        }

        $encrypted = false;
        if ($this->cipher !== null) {
            $stored = $this->cipher->encrypt($stored, $type, $this->sequence);
            $encrypted = true;
        }

        $crc = crc32($stored);
        $flags = ($compressed ? 1 : 0) | ($encrypted ? 2 : 0);

        $binary = self::SYNC
            . chr($type)
            . chr($flags)
            . pack('P', $this->sequence)
            . pack('N', strlen($stored))
            . pack('N', strlen($payload))
            . pack('N', $crc & 0xFFFFFFFF)
            . $stored;

        $this->rawWrite($binary);

        $this->rootHash->update($type, $this->sequence, $crc & 0xFFFFFFFF);
        $this->typeCounts[$type] = ($this->typeCounts[$type] ?? 0) + 1;
        $this->logicalBytes += strlen($payload);

        return new Frame($type, $this->sequence, $payload, $compressed, $encrypted);
    }

    /**
     * @param array<string,mixed> $data frame payload as an array (JSON-encoded)
     */
    public function writeJson(int $type, array $data): Frame
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('JSON encode failed: ' . json_last_error_msg());
        }
        return $this->writeFrame($type, $json);
    }

    /**
     * Emit manifest + footer + tail magic. Idempotent-safe: second call throws.
     *
     * @param array<string,mixed> $extra additional manifest fields (file_count, row_count, …)
     */
    public function finalize(array $extra = []): void
    {
        if ($this->finalized) {
            throw new \LogicException('Already finalized.');
        }
        $manifest = array_merge([
            // frame_count = the manifest's own sequence (all frames up to and
            // including the manifest itself; the footer follows).
            'frame_count'  => $this->sequence + 1,
            'type_counts'  => (object) $this->typeCounts,
            'logical_bytes' => (string) $this->logicalBytes,
            'root_hash'    => $this->rootHash->hex(),
            'created_at'   => time(),
        ], $extra);
        $this->writeJson(FrameType::MANIFEST, $manifest);
        $this->writeJson(FrameType::FOOTER, [
            'manifest_seq'   => $this->sequence,
            'total_frames'   => $this->sequence + 1,
            'logical_bytes'  => (string) $this->logicalBytes,
            'parts_expected' => $this->part,
        ]);
        $this->rawWrite(Header::MAGIC_TAIL);
        if (is_resource($this->stream)) {
            fflush($this->stream);
        }
        // Do NOT close streams here: the current part may be one this writer
        // owns (split case), and the caller still flushes after finalize.
        // closeOwnedStreams() is the single place owned streams are closed.
        $this->finalized = true;
    }

    /** Flush (and close streams this writer owns). Caller closes part-1 stream. */
    public function flush(): void
    {
        if (is_resource($this->stream)) {
            fflush($this->stream);
        }
        foreach ($this->ownedStreams as $owned) {
            if (is_resource($owned)) {
                fflush($owned);
            }
        }
    }

    /** Close streams this writer opened (parts 2+); caller owns the part-1 stream. */
    public function closeOwnedStreams(): void
    {
        foreach ($this->ownedStreams as $owned) {
            if (is_resource($owned)) {
                fclose($owned);
            }
        }
        $this->ownedStreams = [];
    }

    public function sequence(): int
    {
        return $this->sequence;
    }

    /** Total logical bytes written so far (for progress/checkpoints). */
    public function logicalBytesEstimate(): int
    {
        return $this->logicalBytes;
    }

    public function rootHashHex(): string
    {
        return $this->rootHash->hex();
    }

    /**
     * State at a durable boundary, before its checkpoint frame is written.
     * @return array{header_sha256:string,root_hash:string,type_counts:array<int,int>,physical_bytes:int}
     */
    public function resumeState(): array
    {
        return [
            'header_sha256' => hash('sha256', $this->header->encode()),
            'root_hash' => $this->rootHash->hex(),
            'type_counts' => $this->typeCounts,
            'physical_bytes' => $this->physicalBytes,
        ];
    }

    public function isFinalized(): bool
    {
        return $this->finalized;
    }

    private function rawWrite(string $bytes): void
    {
        if (strlen($bytes) === 0) {
            return;
        }
        $total = strlen($bytes);
        $written = 0;
        while ($written < $total) {
            $n = fwrite($this->stream, substr($bytes, $written));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('MUDRAVA_DISK_FULL: write failed at offset ' . $written);
            }
            $written += $n;
        }
        $this->partBytes += $total;
        $this->physicalBytes += $total;
        $this->maybeRotate();
    }

    /**
     * Splitting happens only at frame boundaries; rawWrite is called once per
     * frame/header/footer unit, so rotation here is boundary-safe.
     */
    private function maybeRotate(): void
    {
        if ($this->splitBytes === null || $this->basePath === null) {
            return;
        }
        if ($this->partBytes < $this->splitBytes) {
            return;
        }
        fflush($this->stream);
        // Close the previous part if this writer opened it (parts 2+).
        $key = array_search($this->stream, $this->ownedStreams, true);
        if ($key !== false) {
            fclose($this->stream);
            unset($this->ownedStreams[$key]);
        }
        $this->part++;
        $this->partBytes = 0;
        $path = sprintf('%s.part%04d', $this->basePath, $this->part);
        $next = @fopen($path, 'wb');
        if ($next === false) {
            throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: cannot open ' . $path);
        }
        $this->ownedStreams[] = $next;
        $this->stream = $next;
        $this->rawWriteRaw(Header::encodeContinuation($this->header->archiveUuid, $this->part));
        if ($this->onPartRotate !== null) {
            ($this->onPartRotate)($this->part);
        }
    }

    /** Write without triggering rotation (used for continuation header). */
    private function rawWriteRaw(string $bytes): void
    {
        $total = strlen($bytes);
        $written = 0;
        while ($written < $total) {
            $n = fwrite($this->stream, substr($bytes, $written));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('MUDRAVA_DISK_FULL: write failed');
            }
            $written += $n;
        }
        $this->partBytes += $total;
        $this->physicalBytes += $total;
    }

    /** Total physical bytes written across all parts. */
    public function physicalBytes(): int
    {
        return $this->physicalBytes;
    }

    /** Physical bytes written to the CURRENT part (truncate target). */
    public function currentPartBytes(): int
    {
        return $this->partBytes;
    }

    /** Current physical part number. */
    public function currentPart(): int
    {
        return $this->part;
    }
}
