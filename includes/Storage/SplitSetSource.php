<?php

/**
 * Reassembles a split set (or single file) into one forward stream.
 * Validates part continuity: UUID match, part numbering, missing parts.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Storage;

use Mudrava\Migration\Archive\Header;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Protected binary archives require seekable native streams and atomic file operations.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are reduced to safe codes at the REST boundary.

final class SplitSetSource implements BackupSource
{
    /** @var string */
    private $basePath;

    /** @var resource|null */
    private $handle;

    /** @var int */
    private $part = 1;

    /** @var bool */
    private $eof = false;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    /**
     * Discover which parts exist on disk.
     *
     * @return array{parts:list<string>,missing:list<int>,found:int}
     */
    public static function inspect(string $basePath): array
    {
        if (!is_file($basePath)) {
            return ['parts' => [], 'missing' => [], 'found' => 0];
        }
        $parts = [$basePath];
        // Scan a bounded window for every existing part. `parts` is the
        // CONTIGUOUS run the reader can actually consume; `missing` reports
        // every hole in (1, maxExisting] so the UI can name lost parts.
        $existing = [];
        $maxSeen = 1;
        $missStreak = 0;
        for ($i = 2; $i <= 4096 && $missStreak < 64; $i++) {
            $p = sprintf('%s.part%04d', $basePath, $i);
            if (is_file($p)) {
                $existing[$i] = $p;
                $maxSeen = $i;
                $missStreak = 0;
            } else {
                $missStreak++;
            }
        }
        // Contiguous run from part 2 upward.
        for ($i = 2; isset($existing[$i]); $i++) {
            $parts[] = $existing[$i];
        }
        $missing = [];
        for ($probe = 2; $probe <= $maxSeen; $probe++) {
            if (!isset($existing[$probe])) {
                $missing[] = $probe;
            }
        }
        return [
            'parts'    => $parts,
            'missing'  => $missing,
            'found'    => count($parts),
        ];
    }

    /**
     * Pin the on-disk identity of every archive part across verification and
     * restore requests. Metadata catches replacement and size changes;
     * head/tail samples catch common in-place edits within one timestamp
     * resolution. Frame CRC/AEAD and the manifest still verify the content.
     */
    public static function identity(string $basePath): string
    {
        $set = self::inspect($basePath);
        if ($set['found'] === 0 || $set['missing'] !== []) {
            throw new \RuntimeException('MUDRAVA_PART_MISSING');
        }
        $hash = hash_init('sha256');
        foreach ($set['parts'] as $path) {
            clearstatcache(true, $path);
            if (is_link($path)) {
                throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED');
            }
            $stat = @stat($path);
            if ($stat === false || !is_file($path)) {
                throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED');
            }
            foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $field) {
                hash_update($hash, $field . '=' . (string) $stat[$field] . "\n");
            }
            $stream = @fopen($path, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED');
            }
            try {
                $head = fread($stream, 4096);
                if ($head === false) {
                    throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED');
                }
                hash_update($hash, $head);
                if ($stat['size'] > 4096) {
                    if (fseek($stream, -min(4096, (int) $stat['size']), SEEK_END) !== 0) {
                        throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED');
                    }
                    $tail = fread($stream, 4096);
                    if ($tail === false) {
                        throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED');
                    }
                    hash_update($hash, $tail);
                }
            } finally {
                fclose($stream);
            }
        }
        return hash_final($hash);
    }

    /**
     * The server-side gate before ANY destructive tick: prove the set on
     * disk is complete and internally consistent, so a missing or
     * mis-slotted part is never discovered mid-restore with the target
     * half-dropped. Checks: part 1 exists; no holes below the highest
     * part; the last stored part ends with the real end-of-archive
     * marker; every part 2+ carries a continuation header whose UUID and
     * part number match the slot the file physically sits in; and when
     * the operator states the part count the set should have, nothing
     * below it may be missing.
     *
     * @return array{parts:list<string>,missing:list<int>,found:int}
     * @throws \RuntimeException MUDRAVA_* code with a path-free part detail
     */
    public static function assertSetReadable(string $basePath, int $expectedParts = 0): array
    {
        $inspect = self::inspect($basePath);
        $base = basename($basePath);
        if ($inspect['found'] === 0) {
            throw new \RuntimeException('MUDRAVA_PART_MISSING: part 0001 of ' . $base . ' (nothing uploaded)');
        }
        if ($inspect['missing'] !== []) {
            $holes = [];
            foreach ($inspect['missing'] as $n) {
                $holes[] = sprintf('%04d', (int) $n);
            }
            throw new \RuntimeException(
                'MUDRAVA_PART_MISSING: part ' . $holes[0] . ' of ' . $base
                . ' (holes in the uploaded set: ' . implode(', ', $holes) . ')'
            );
        }
        if ($expectedParts > $inspect['found']) {
            throw new \RuntimeException(
                'MUDRAVA_PART_MISSING: part ' . sprintf('%04d', $inspect['found'] + 1) . ' of ' . $base
                . ' (archive expects ' . $expectedParts . ' parts, ' . $inspect['found'] . ' received)'
            );
        }
        $last = (string) $inspect['parts'][count($inspect['parts']) - 1];
        if (!self::tailHasFooter($last)) {
            throw new \RuntimeException(
                'MUDRAVA_PART_MISSING: part ' . sprintf('%04d', $inspect['found'] + 1) . ' of ' . $base
                . ' (' . basename($last) . ' has no end-of-archive marker: the last part of the set is missing or truncated)'
            );
        }
        $header = Header::decode(self::readHead($basePath, 4096));
        foreach ($inspect['parts'] as $i => $path) {
            $slot = (int) $i + 1;
            if ($slot === 1) {
                continue;
            }
            $decoded = Header::decodeContinuation(self::readHead((string) $path, 28));
            if ($decoded['archive_uuid'] !== $header->archiveUuid) {
                throw new \RuntimeException('MUDRAVA_PART_MISMATCH: wrong archive UUID in part ' . sprintf('%04d', $slot));
            }
            if ($decoded['part_number'] !== $slot) {
                throw new \RuntimeException(
                    'MUDRAVA_PART_MISMATCH: expected part ' . sprintf('%04d', $slot)
                    . ', got ' . sprintf('%04d', $decoded['part_number'])
                );
            }
        }
        return $inspect;
    }

    /** True when the file ends exactly with the tail magic: a stream end. */
    public static function tailHasFooter(string $path): bool
    {
        $size = @filesize($path);
        if ($size === false || $size < 8) {
            return false;
        }
        $h = @fopen($path, 'rb');
        if ($h === false) {
            return false;
        }
        $ok = false;
        if (fseek($h, -8, SEEK_END) === 0) {
            $ok = fread($h, 8) === Header::MAGIC_TAIL;
        }
        fclose($h);
        return $ok;
    }

    /**
     * @throws \RuntimeException
     */
    private static function readHead(string $path, int $n): string
    {
        $h = @fopen($path, 'rb');
        if ($h === false) {
            throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: ' . basename($path));
        }
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($h, max(1, $n - strlen($buf)));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buf .= $chunk;
        }
        fclose($h);
        return $buf;
    }

    public function stream()
    {
        if ($this->handle === null) {
            $handle = @fopen($this->basePath, 'rb');
            if ($handle === false) {
                throw new \RuntimeException('MUDRAVA_PART_MISSING: ' . $this->basePath);
            }
            $this->handle = $handle;
        }
        return $this->handle;
    }

    /**
     * Provider callback for FrameReader: returns the next part stream or
     * throws MUDRAVA_PART_MISSING when the set is exhausted.
     *
     * @return callable():resource
     */
    public function nextPartProvider(): callable
    {
        return function () {
            $this->part++;
            $path = sprintf('%s.part%04d', $this->basePath, $this->part);
            if (!is_file($path)) {
                $this->eof = true;
                throw new \RuntimeException(
                    'MUDRAVA_PART_MISSING: part ' . sprintf('%04d', $this->part) . ' of ' . basename($this->basePath)
                );
            }
            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: ' . $path);
            }
            $this->handle = $handle;
            return $handle;
        };
    }

    public function eof(): bool
    {
        return $this->eof;
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
