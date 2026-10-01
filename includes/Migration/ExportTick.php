<?php

/**
 * One durable export tick: the full file lifecycle for a single bounded
 * step of an export job.
 *
 * This is the resumability contract, isolated from WordPress so it can be
 * tested across simulated process restarts:
 *
 *  - fresh tick (no checkpoint): create the part-1 stream ('wb'), write
 *    header + SITE_METADATA, step, and ALWAYS leave a durable checkpoint.
 *    Without the guaranteed checkpoint, a crash before the first periodic
 *    checkpoint would make the next tick re-truncate the file and restart
 *    the export forever.
 *  - resume tick: truncate the current part to the checkpoint offset,
 *    delete orphan later parts, restore the saved writer state and continue.
 *    Older checkpoints fall back to a prefix scan. A completed export is
 *    verified in full by JobRunner before it is marked done.
 *
 * The process may die at any point between ticks; the next tick always
 * converges to a valid archive.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Migration;

use Mudrava\Migration\Archive\Checkpoint;
use Mudrava\Migration\Archive\FrameReader;
use Mudrava\Migration\Archive\FrameWriter;
use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Compression\DeflateCodec;
use Mudrava\Migration\Crypto\FrameCipher;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Protected binary archives require seekable native streams and atomic file operations.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are reduced to safe codes at the REST boundary.

final class ExportTick
{
    /**
     * @param callable(list<string>=):DatabaseSource $dbFactory takes optional excluded table prefixes
     * @param callable(list<string>=):FileInventory $filesFactory takes optional excluded relative dirs
     * @param callable(Header):?FrameCipher $cipherFactory builds the cipher
     *        for a given header (fresh or scanned); null for plaintext.
     * @param array<string,mixed>|null $checkpoint persisted checkpoint, or null on first tick
     * @param array<string,mixed> $siteMeta
     * @param array{tables?:list<string>,dirs?:list<string>} $excludes user exclusions
     * @return array{checkpoint:array<string,mixed>|null,state:string,percent:int,current_part:int,logical_bytes:int}
     */
    public static function run(
        string $base,
        ?array $checkpoint,
        callable $dbFactory,
        callable $filesFactory,
        array $siteMeta,
        ?Header $freshHeader,
        callable $cipherFactory,
        ?int $splitBytes,
        float $deadline,
        array $excludes = []
    ): array {
        $codec = new DeflateCodec();
        $tables = array_values(array_map('strval', (array) ($excludes['tables'] ?? [])));
        $dirs = array_values(array_map('strval', (array) ($excludes['dirs'] ?? [])));

        if ($checkpoint === null) {
            if ($freshHeader === null) {
                throw new \LogicException('Fresh export tick requires a header.');
            }
            $stream = @fopen($base, 'wb');
            if ($stream === false) {
                throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: cannot create archive file');
            }
            $writer = new FrameWriter($stream, $freshHeader, $codec, $cipherFactory($freshHeader), $splitBytes, $base);
            // Factories receive the exclusion lists; a factory that ignores
            // them (tests, defaults) is still valid PHP.
            $exporter = new Exporter($writer, $dbFactory($tables), $filesFactory($dirs), $siteMeta);
            $exporter->writeSiteMetadata();
        } else {
            $cp = Checkpoint::fromArray($checkpoint);
            self::truncateToCheckpoint($base, $cp);
            $scan = self::resumeState($base, $cp);
            if (
                (int) $scan['part'] !== $cp->part
                || (int) $scan['part_offset'] !== (int) $cp->physicalOffset
                || (int) $scan['sequence'] !== $cp->sequence
                || (string) $scan['logical_bytes'] !== $cp->logicalBytes
                || !hash_equals($cp->rootHash, (string) $scan['root_hash'])
            ) {
                throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED: export prefix differs from checkpoint');
            }
            $partPath = self::partPath($base, $cp->part);
            $stream = @fopen($partPath, 'ab');
            if ($stream === false) {
                throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: cannot reopen part ' . $cp->part);
            }
            $writer = FrameWriter::resumeFrom(
                $stream,
                $scan['header'],
                $codec,
                $cipherFactory($scan['header']),
                $scan,
                $splitBytes,
                $base
            );
            $exporter = new Exporter($writer, $dbFactory($tables), $filesFactory($dirs), $siteMeta, $cp);
        }

        $state = $exporter->stepBounded($deadline);
        if ($state === Exporter::STATE_DONE) {
            $exporter->finalize();
        } else {
            // Durable boundary every tick - the invariant that makes
            // cross-process resume safe.
            $exporter->checkpointNow();
        }
        $writer->flush();
        $writer->closeOwnedStreams();
        // When the current part is one the writer owns (split case),
        // closeOwnedStreams() already closed it; fclose() would warn.
        if (is_resource($stream)) {
            fclose($stream);
        }

        $cpNew = $exporter->lastCheckpoint();
        return [
            'checkpoint'    => $cpNew !== null ? $cpNew->toArray() : null,
            'state'         => $state,
            'percent'       => $state === Exporter::STATE_DONE ? 100 : ($state === Exporter::STATE_FILES ? 60 : 5),
            'current_part'  => $writer->currentPart(),
            'logical_bytes' => $writer->logicalBytesEstimate(),
        ];
    }

    public static function partPath(string $base, int $part): string
    {
        return $part <= 1 ? $base : sprintf('%s.part%04d', $base, $part);
    }

    /**
     * Truncate the current part to the checkpoint offset and delete later
     * parts so the resumed stream has no duplicate or orphan frames.
     */
    public static function truncateToCheckpoint(string $base, Checkpoint $cp): void
    {
        $partPath = self::partPath($base, $cp->part);
        $target = (int) $cp->physicalOffset;
        $normalizedOffset = ltrim($cp->physicalOffset, '0');
        if (
            !ctype_digit($cp->physicalOffset)
            || (string) $target !== ($normalizedOffset === '' ? '0' : $normalizedOffset)
        ) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED: invalid export checkpoint offset');
        }
        if (is_link($partPath) || !is_file($partPath)) {
            throw new \RuntimeException('MUDRAVA_PART_MISSING: export checkpoint part is absent or unsafe');
        }
        clearstatcache(true, $partPath);
        $current = (int) filesize($partPath);
        if ($current < $target) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED: export part is shorter than checkpoint');
        }
        if ($current > $target) {
            $h = @fopen($partPath, 'r+b');
            if ($h === false || !@ftruncate($h, max(0, $target))) {
                if ($h !== false) {
                    fclose($h);
                }
                throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: truncate failed');
            }
            fclose($h);
        }
        for ($i = $cp->part + 1; $i <= $cp->part + 4096; $i++) {
            $p = sprintf('%s.part%04d', $base, $i);
            if (is_file($p)) {
                @unlink($p);
            } else {
                break;
            }
        }
    }

    /**
     * Re-derive writer state (sequence, root hash, type counts) by scanning
     * the surviving prefix. No decryption: the scan reads frame headers only.
     *
     * @return array{sequence:int,root_hash:string,logical_bytes:int,type_counts:array<int,int>,part:int,part_offset:int,physical_bytes:int,header:Header}
     */
    public static function scanSet(string $base, int $currentPart): array
    {
        $stream = @fopen($base, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('MUDRAVA_PART_MISSING: ' . basename($base));
        }
        $partNum = 1;
        $provider = static function () use (&$partNum, $base) {
            $partNum++;
            $p = sprintf('%s.part%04d', $base, $partNum);
            if (!is_file($p)) {
                return null;
            }
            return @fopen($p, 'rb') ?: null;
        };
        $scan = FrameReader::scanPrefix($stream, $provider);
        fclose($stream);
        return $scan;
    }

    /**
     * Resume from a trusted WordPress job checkpoint without rereading all
     * previous payloads. The final verification still reads every byte.
     *
     * @return array{sequence:int,root_hash:string,logical_bytes:int,type_counts:array<int,int>,part:int,part_offset:int,physical_bytes:int,header:Header}
     */
    private static function resumeState(string $base, Checkpoint $cp): array
    {
        $saved = $cp->cursor['writer_state'] ?? null;
        if (!is_array($saved) || !isset($saved['header_sha256'], $saved['type_counts'], $saved['physical_bytes'])) {
            $scan = self::scanSet($base, $cp->part);
            // scanPrefix's physical_bytes counts frame bodies only. A fresh
            // checkpoint must count the header and continuation headers too,
            // or its next fast resume would reject a valid archive.
            $physical = 0;
            for ($part = 1; $part <= $cp->part; $part++) {
                $path = self::partPath($base, $part);
                clearstatcache(true, $path);
                $size = @filesize($path);
                if ($size === false) {
                    throw new \RuntimeException('MUDRAVA_PART_MISSING: export part is absent');
                }
                $physical += (int) $size;
            }
            $scan['physical_bytes'] = $physical;
            return $scan;
        }
        if (
            !is_string($saved['header_sha256']) || !is_array($saved['type_counts'])
            || !isset($saved['root_hash']) || !is_string($saved['root_hash'])
            || !hash_equals($saved['root_hash'], $cp->rootHash)
        ) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED: invalid writer checkpoint');
        }
        $stream = @fopen($base, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('MUDRAVA_PART_MISSING: ' . basename($base));
        }
        try {
            $header = (new FrameReader($stream, new DeflateCodec()))->readHeader();
        } finally {
            fclose($stream);
        }
        if (!hash_equals($saved['header_sha256'], hash('sha256', $header->encode()))) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED: export header differs from checkpoint');
        }
        $counts = [];
        $sum = 0;
        foreach ($saved['type_counts'] as $type => $count) {
            if (!ctype_digit((string) $type) || !is_int($count) || $count < 0) {
                throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED: invalid frame counts');
            }
            $counts[(int) $type] = $count;
            $sum += $count;
        }
        if (
            $sum !== $cp->sequence || !ctype_digit($cp->logicalBytes)
            || preg_match('/^[a-f0-9]{64}$/D', $cp->rootHash) !== 1
            || !is_int($saved['physical_bytes']) || $saved['physical_bytes'] < (int) $cp->physicalOffset
        ) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED: invalid writer checkpoint');
        }
        if ($cp->part > 1) {
            $partStream = @fopen(self::partPath($base, $cp->part), 'rb');
            if ($partStream === false) {
                throw new \RuntimeException('MUDRAVA_PART_MISSING: export checkpoint part is absent');
            }
            try {
                $continuation = Header::decodeContinuation((string) fread($partStream, 28));
            } finally {
                fclose($partStream);
            }
            if (
                $continuation['part_number'] !== $cp->part
                || !hash_equals($header->archiveUuid, $continuation['archive_uuid'])
            ) {
                throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED: wrong continuation header');
            }
        }
        return [
            'sequence' => $cp->sequence,
            'root_hash' => $cp->rootHash,
            'logical_bytes' => (int) $cp->logicalBytes,
            'type_counts' => $counts,
            'part' => $cp->part,
            'part_offset' => (int) $cp->physicalOffset,
            'physical_bytes' => $saved['physical_bytes'],
            'header' => $header,
        ];
    }
}
