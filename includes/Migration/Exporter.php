<?php

/**
 * Export engine: walks the state machine Preflight → Inventory → Database →
 * Files → Manifest and writes one logical .mudrava archive.
 *
 * Every public method performs ONE bounded batch, so the job runner can call
 * it repeatedly from short HTTP requests. All state is durable (checkpoint
 * frames + job options), so the process may die between calls.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Migration;

use Mudrava\Migration\Archive\Checkpoint;
use Mudrava\Migration\Archive\FrameType;
use Mudrava\Migration\Archive\FrameWriter;
use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Database\RowCodec;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Protected binary archives require seekable native streams and atomic file operations.

final class Exporter
{
    public const STATE_DB     = 'database';
    public const STATE_FILES  = 'files';
    public const STATE_DONE   = 'done';

    public const DB_BATCH_ROWS = 500;
    public const DB_FRAME_TARGET_BYTES = 4194304; // keep row batches small on shared hosts
    public const FILE_CHUNK_BYTES = 8388608; // 8 MiB
    public const CHECKPOINT_EVERY_BATCHES = 20;

    /** @var FrameWriter */
    private $writer;

    /** @var DatabaseSource */
    private $db;

    /** @var FileInventory */
    private $files;

    /** @var array<string,mixed> */
    private $siteMeta;

    /** @var string */
    private $state = self::STATE_DB;

    /** @var int */
    private $tableIndex = 0;

    /** @var list<string> */
    private $tables;

    /** @var ?string */
    private $currentTable = null;

    /** @var ?string */
    private $currentPk = null;

    /** @var ?string primary-key bytes, null before first row, or decimal offset */
    private $pkCursor = null;

    /** @var ?string SHA-256 fingerprint of the last archived row of the
     *      current PK-less table. Offset paging has no key to resume from,
     *      so this anchor is re-read from the database before every batch:
     *      deletes or inserts before the window slide LIMIT/OFFSET silently
     *      and would otherwise skip rows inside a "verified" archive. */
    private $lastRowHash = null;

    /** @var int */
    private $batchesSinceCheckpoint = 0;

    /** @var int */
    private $rowCount = 0;

    /** @var int */
    private $fileCount = 0;

    /** @var int */
    private $fileBytes = 0;

    /** @var string|null current file being streamed */
    private $currentFile = null;

    /** @var int current file offset */
    private $currentFileOffset = 0;

    /** @var ?int size written in FILE_METADATA for the current file */
    private $currentFileSize = null;

    /** @var ?array<string,int> identity of an opened regular file */
    private $currentFileStat = null;

    /** @var int size of the most recently archived chunk */
    private $lastFileChunkSize = 0;

    /** @var ?string SHA-256 of the most recently archived chunk */
    private $lastFileChunkHash = null;

    /** @var int inventory entries to skip on resume (completed files) */
    private $skipFiles = 0;

    /** @var ?string relative path of the most recently emitted file entry.
     *      The inventory re-walks the tree on every resume tick and skips
     *      completed entries by COUNT, so a file added before the resume
     *      position shifts the count and lands the cursor on an already
     *      emitted path - a duplicate inside a "verified" archive. One
     *      remembered path (never a set of all paths) makes that collision
     *      detectable from the checkpoint alone. */
    private $lastFilePath = null;

    /** @var \Generator|null */
    private $fileIterator = null;

    /** @var Checkpoint|null last emitted checkpoint (for the job runner) */
    private $lastCheckpoint = null;

    /**
     * @param array<string,mixed> $siteMeta data for SITE_METADATA frame
     */
    public function __construct(
        FrameWriter $writer,
        DatabaseSource $db,
        FileInventory $files,
        array $siteMeta,
        ?Checkpoint $resume = null
    ) {
        $this->writer = $writer;
        $this->db = $db;
        $this->files = $files;
        $this->siteMeta = $siteMeta;
        $this->tables = $db->tables();

        if ($resume !== null) {
            $this->state = $resume->state;
            $this->tableIndex = (int) ($resume->cursor['table_index'] ?? 0);
            $this->currentTable = isset($resume->cursor['table']) ? (string) $resume->cursor['table'] : null;
            // A resumed table must keep using its primary-key cursor. Without
            // this, rows() interprets the saved PK value as an OFFSET and can
            // silently omit the rest of a table after the first batch.
            $this->currentPk = $this->currentTable !== null
                ? $this->db->primaryKey($this->currentTable)
                : null;
            $savedCursor = $resume->cursor['pk'] ?? null;
            $this->pkCursor = $savedCursor === null
                ? ($this->currentPk !== null ? null : '0')
                : (string) $savedCursor;
            $savedAnchor = $resume->cursor['last_row_hash'] ?? null;
            $this->lastRowHash = is_string($savedAnchor) ? $savedAnchor : null;
            if (
                $this->currentPk === null
                && (int) $this->pkCursor > 0
                && $this->lastRowHash === null
            ) {
                // An offset cursor without its anchor cannot prove the
                // window still points at the same rows. Fail loudly and
                // restart rather than resume into a silent gap.
                throw new \RuntimeException(
                    'MUDRAVA_ARCHIVE_CHANGED: offset anchor missing from checkpoint'
                );
            }
            $this->rowCount = (int) ($resume->cursor['row_count'] ?? 0);
            $this->fileCount = (int) ($resume->cursor['file_count'] ?? 0);
            $this->fileBytes = (int) ($resume->cursor['file_bytes'] ?? 0);
            $this->currentFile = isset($resume->cursor['path']) ? (string) $resume->cursor['path'] : null;
            $this->currentFileOffset = (int) ($resume->cursor['file_offset'] ?? 0);
            $this->currentFileSize = isset($resume->cursor['file_size'])
                ? (int) $resume->cursor['file_size']
                : null;
            if ($this->currentFile !== null && ($this->currentFileSize === null || $this->currentFileSize < 0)) {
                throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED: current file size missing from checkpoint');
            }
            $savedFileStat = $resume->cursor['file_stat'] ?? null;
            $this->currentFileStat = is_array($savedFileStat)
                ? $savedFileStat
                : ($savedFileStat instanceof \stdClass ? (array) $savedFileStat : null);
            $this->lastFileChunkSize = (int) ($resume->cursor['last_chunk_size'] ?? 0);
            $this->lastFileChunkHash = isset($resume->cursor['last_chunk_hash'])
                ? (string) $resume->cursor['last_chunk_hash']
                : null;
            if (
                $this->currentFile !== null
                && $this->currentFileOffset > 0
                && (
                    $this->lastFileChunkSize < 1
                    || $this->lastFileChunkSize > self::FILE_CHUNK_BYTES
                    || $this->lastFileChunkSize > $this->currentFileOffset
                    || !is_string($this->lastFileChunkHash)
                    || preg_match('/^[a-f0-9]{64}$/', $this->lastFileChunkHash) !== 1
                )
            ) {
                throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED: file chunk identity missing from checkpoint');
            }
            // Mid-file resume: the interrupted file is already positioned via
            // currentFile, so the inventory must skip completed files + it.
            $this->skipFiles = $this->currentFile !== null
                ? $this->fileCount + 1
                : $this->fileCount;
            // Checkpoints written before this guard have no last path; the
            // duplicate check simply stays off for them.
            $savedLastFile = $resume->cursor['last_file_path'] ?? null;
            $this->lastFilePath = is_string($savedLastFile) && $savedLastFile !== ''
                ? $savedLastFile
                : null;
        }
    }

    /**
     * Write the SITE_METADATA frame (must be the first frame of a fresh export).
     */
    public function writeSiteMetadata(): void
    {
        $this->writer->writeJson(FrameType::SITE_METADATA, $this->siteMeta);
    }

    /**
     * Run one bounded batch. Returns the state after the batch.
     */
    public function step(): string
    {
        if ($this->state === self::STATE_DB) {
            return $this->stepDatabase();
        }
        if ($this->state === self::STATE_FILES) {
            return $this->stepFiles();
        }
        return $this->state;
    }

    /**
     * Run batches until the wall-clock deadline or completion. This is the
     * tick entry point in production: one HTTP request does as much work as
     * fits in its time budget, then stops at a frame boundary. The process
     * may die at any moment; the next tick resumes from the last durable
     * checkpoint.
     */
    public function stepBounded(float $deadline): string
    {
        do {
            $state = $this->step();
            if ($state === self::STATE_DONE) {
                return $state;
            }
        } while (microtime(true) < $deadline);
        return $state;
    }

    /**
     * Force a durable checkpoint at the current position. Called by the
     * tick runner after every bounded step so the on-disk archive always
     * has a resume boundary newer than or equal to the last completed
     * frame - even if the process dies before the next periodic checkpoint.
     */
    public function checkpointNow(): void
    {
        $this->emitCheckpoint($this->state);
    }

    private function stepDatabase(): string
    {
        // Table not started yet?
        if ($this->currentTable === null) {
            if ($this->tableIndex >= count($this->tables)) {
                $this->emitCheckpoint(self::STATE_FILES);
                $this->state = self::STATE_FILES;
                return $this->state;
            }
            $table = $this->tables[$this->tableIndex];
            $this->currentTable = $table;
            $this->currentPk = $this->db->primaryKey($table);
            $this->pkCursor = $this->currentPk !== null ? null : '0';
            $this->lastRowHash = null;
            $columns = $this->db->columns($table);
            $meta = $this->db->tableMeta($table);
            $this->writer->writeJson(FrameType::DB_TABLE_BEGIN, [
                'table'   => $table,
                'columns' => $columns,
                'pk'      => $this->currentPk,
                'engine'  => $meta['engine'] ?? '',
                'charset' => $meta['charset'] ?? '',
            ]);
            $this->writer->writeFrame(FrameType::DB_SCHEMA, $this->db->schema($table));
            return self::STATE_DB;
        }

        $table = $this->currentTable;
        $columns = $this->db->columns($table);
        $pkIndex = $this->currentPk !== null ? array_search($this->currentPk, $columns, true) : false;
        if ($this->currentPk !== null && $pkIndex === false) {
            throw new \RuntimeException('MUDRAVA_DB_QUERY_FAILED: primary key absent from columns');
        }
        if ($this->currentPk === null && (int) $this->pkCursor > 0) {
            $this->assertOffsetAnchor($table);
        }
        $rows = $this->db->rows($table, $this->currentPk, $this->pkCursor, self::DB_BATCH_ROWS);
        $batch = [];
        $batchBytes = 6;
        $count = 0;
        $lastPk = '';
        foreach ($rows as $row) {
            if (count($row) !== count($columns)) {
                throw new \RuntimeException('MUDRAVA_DB_QUERY_FAILED: row width mismatch');
            }
            $rowBytes = 0;
            foreach ($row as $cell) {
                if ($cell === null) {
                    $rowBytes++;
                } elseif (is_string($cell)) {
                    $rowBytes += 5 + strlen($cell);
                } else {
                    throw new \InvalidArgumentException('Cell must be string or null.');
                }
            }
            if ($rowBytes > FrameWriter::MAX_FRAME_BYTES - 6) {
                throw new \RuntimeException('MUDRAVA_DB_ROW_TOO_LARGE: single row exceeds frame limit');
            }
            if ($batch !== [] && $batchBytes + $rowBytes > self::DB_FRAME_TARGET_BYTES) {
                $this->writer->writeFrame(FrameType::DB_ROWS, RowCodec::encode($batch));
                $batch = [];
                $batchBytes = 6;
            }
            $batch[] = $row;
            $batchBytes += $rowBytes;
            $count++;
            if ($pkIndex !== false) {
                $lastPk = (string) $row[$pkIndex];
            }
        }
        if ($count === 0) {
            $this->writer->writeJson(FrameType::DB_TABLE_END, [
                'table'     => $table,
                'row_count' => $this->rowCount,
            ]);
            $this->tableIndex++;
            $this->currentTable = null;
            $this->pkCursor = '0';
            $this->lastRowHash = null;
            $this->maybeCheckpoint();
            return self::STATE_DB;
        }

        if ($batch !== []) {
            $this->writer->writeFrame(FrameType::DB_ROWS, RowCodec::encode($batch));
        }
        $this->rowCount += $count;
        if ($this->currentPk !== null) {
            $this->pkCursor = $lastPk;
        } else {
            // No PK: offset-based cursor (LIMIT/OFFSET in the adapter).
            // Remember the exact last row so the next batch can prove the
            // window did not slide underneath us.
            $this->pkCursor = (string) ((int) $this->pkCursor + $count);
            $this->lastRowHash = self::rowFingerprint($rows[$count - 1]);
        }
        $this->maybeCheckpoint();
        return self::STATE_DB;
    }

    /**
     * Re-read the last archived row at its recorded offset. A moved, changed
     * or missing anchor means rows before the window shifted since the last
     * batch, so the next LIMIT/OFFSET would silently skip or repeat rows.
     */
    private function assertOffsetAnchor(string $table): void
    {
        if ($this->lastRowHash === null) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED: offset anchor unavailable');
        }
        $anchor = $this->db->rowAtOffset($table, (int) $this->pkCursor - 1);
        if ($anchor === null || !hash_equals($this->lastRowHash, self::rowFingerprint($anchor))) {
            throw new \RuntimeException('MUDRAVA_DB_TABLE_CHANGED: PK-less table shifted during export');
        }
    }

    /**
     * Length-prefixed unambiguous fingerprint of one positional row.
     *
     * @param list<?string> $row
     */
    private static function rowFingerprint(array $row): string
    {
        $parts = '';
        foreach ($row as $cell) {
            if ($cell === null) {
                $parts .= 'N;';
            } else {
                $cell = (string) $cell;
                $parts .= 's' . strlen($cell) . ':' . $cell . ';';
            }
        }
        return hash('sha256', $parts);
    }

    private function stepFiles(): string
    {
        if ($this->fileIterator === null) {
            $this->fileIterator = $this->files->iterate();
        }

        // No file in progress: advance to the next inventory entry.
        if ($this->currentFile === null) {
            $entry = $this->nextInventoryEntry();
            if ($entry === null) {
                $this->emitCheckpoint(self::STATE_DONE);
                $this->state = self::STATE_DONE;
                return $this->state;
            }
            $this->currentFile = $entry['path'];
            $this->currentFileOffset = 0;
            $this->currentFileSize = (int) $entry['size'];
            $this->currentFileStat = null;
            $this->lastFileChunkSize = 0;
            $this->lastFileChunkHash = null;
            if ($this->currentFileSize < 0) {
                throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED: invalid source file size');
            }
            $this->writer->writeJson(FrameType::FILE_METADATA, [
                'path'  => $entry['path'],
                'size'  => $entry['size'],
                'mtime' => $entry['mtime'],
                'mode'  => $entry['mode'],
                'type'  => $entry['type'],
                'target' => $entry['target'] ?? null,
            ]);
            // The frame is durable now: remember it as the walk high-water
            // mark so a later resume can detect a count-shift collision.
            $this->lastFilePath = (string) $entry['path'];
            if (($entry['type'] ?? 'file') === 'symlink') {
                // Symlink metadata is the payload; no FILE_DATA frames.
                $this->currentFile = null;
                $this->currentFileSize = null;
                $this->currentFileStat = null;
                $this->fileCount++;
                $this->maybeCheckpoint();
                return self::STATE_FILES;
            }
        }

        $handle = $this->files->open((string) $this->currentFile);
        $stat = fstat($handle);
        if (
            !is_array($stat)
            || $this->currentFileSize === null
            || (int) $stat['size'] !== $this->currentFileSize
        ) {
            fclose($handle);
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED: source file size changed');
        }
        $identity = [];
        foreach (['dev', 'ino', 'mtime', 'ctime'] as $key) {
            $identity[$key] = (int) ($stat[$key] ?? 0);
        }
        // Virtual streams used by tests do not have a stable inode. Real
        // files do, so a replacement or changed timestamp stops the export.
        if ($identity['ino'] > 0) {
            if ($this->currentFileStat !== null && $identity !== $this->currentFileStat) {
                fclose($handle);
                throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED: source file identity changed');
            }
            $this->currentFileStat = $identity;
        }
        if ($this->lastFileChunkSize > 0) {
            if (fseek($handle, $this->currentFileOffset - $this->lastFileChunkSize) !== 0) {
                fclose($handle);
                throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED: source file sample seek failed');
            }
            $hash = hash_init('sha256');
            $read = hash_update_stream($hash, $handle, $this->lastFileChunkSize);
            if ($read !== $this->lastFileChunkSize || !hash_equals((string) $this->lastFileChunkHash, hash_final($hash))) {
                fclose($handle);
                throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED: previous source file chunk changed');
            }
        }
        if ($this->currentFileOffset > 0 && fseek($handle, $this->currentFileOffset) !== 0) {
            fclose($handle);
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED: source file seek failed');
        }
        $chunk = fread($handle, self::FILE_CHUNK_BYTES);
        if ($chunk === false || ($chunk === '' && !feof($handle))) {
            fclose($handle);
            throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: source file read failed');
        }
        if ($chunk === '') {
            fclose($handle);
            if ($this->currentFileOffset !== $this->currentFileSize) {
                throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED: source file ended before declared size');
            }
            $this->currentFile = null;
            $this->currentFileOffset = 0;
            $this->currentFileSize = null;
            $this->currentFileStat = null;
            $this->lastFileChunkSize = 0;
            $this->lastFileChunkHash = null;
            $this->fileCount++;
            $this->maybeCheckpoint();
            return self::STATE_FILES;
        }
        if ($this->currentFileOffset + strlen($chunk) > $this->currentFileSize) {
            fclose($handle);
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED: source file exceeds declared size');
        }
        $afterRead = fstat($handle);
        fclose($handle);
        if (!is_array($afterRead) || (int) $afterRead['size'] !== $this->currentFileSize) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED: source file size changed during read');
        }
        if ($this->currentFileStat !== null) {
            $afterIdentity = [];
            foreach (['dev', 'ino', 'mtime', 'ctime'] as $key) {
                $afterIdentity[$key] = (int) ($afterRead[$key] ?? 0);
            }
            if ($afterIdentity !== $this->currentFileStat) {
                throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED: source file identity changed during read');
            }
        }
        $this->writer->writeFrame(FrameType::FILE_DATA, $chunk);
        $this->currentFileOffset += strlen($chunk);
        $this->fileBytes += strlen($chunk);
        $this->lastFileChunkSize = strlen($chunk);
        $this->lastFileChunkHash = hash('sha256', $chunk);
        $this->maybeCheckpoint();
        return self::STATE_FILES;
    }

    /**
     * @return array{path:string,size:int,mtime:int,mode:int,type:string,target?:string}|null
     */
    private function nextInventoryEntry(): ?array
    {
        $iterator = $this->fileIterator;
        if ($iterator === null) {
            return null;
        }
        // Skip entries already completed before a resume.
        // (Inventory iteration order must be deterministic - sorted.)
        while ($iterator->valid()) {
            /** @var array{path:string,size:int,mtime:int,mode:int,type:string,target?:string} $entry */
            $entry = $iterator->current();
            $iterator->next();
            if ($this->skipFiles > 0) {
                $this->skipFiles--;
                continue;
            }
            // Resume landed on a path that was already emitted (or jumped
            // backwards): the tree changed in a directory the previous walk
            // had not listed yet, so the skip count no longer matches the
            // archived prefix. Emitting it again would put two FILE_METADATA
            // frames for one path into a "verified" archive.
            if (
                $this->lastFilePath !== null
                && self::compareWalkPaths((string) $entry['path'], $this->lastFilePath) <= 0
            ) {
                throw new \RuntimeException(
                    'MUDRAVA_ARCHIVE_CHANGED: source tree changed during export, resume position collided with an archived file'
                );
            }
            return $entry;
        }
        return null;
    }

    /**
     * Order two inventory paths the way the depth-first walk emits them:
     * compare one path segment at a time with plain strcmp. A directory is
     * visited before its own children and before any later sibling, so
     * "a/x" precedes "a.txt" even though strcmp("a/x","a.txt") is greater.
     * Entries are never prefixes of one another (a file cannot contain a
     * path), but the shorter-first fallback keeps the comparator total.
     */
    private static function compareWalkPaths(string $a, string $b): int
    {
        $left = explode('/', $a);
        $right = explode('/', $b);
        $shared = min(count($left), count($right));
        for ($i = 0; $i < $shared; $i++) {
            $cmp = strcmp($left[$i], $right[$i]);
            if ($cmp !== 0) {
                return $cmp;
            }
        }
        return count($left) <=> count($right);
    }

    private function maybeCheckpoint(): void
    {
        $this->batchesSinceCheckpoint++;
        if ($this->batchesSinceCheckpoint >= self::CHECKPOINT_EVERY_BATCHES) {
            $this->emitCheckpoint($this->state);
        }
    }

    private function emitCheckpoint(string $state): void
    {
        // Capture the durable boundary BEFORE writing the checkpoint frame:
        // physical offset = end of the last data frame, sequence = last data
        // frame. Resume truncates to this offset and scans the surviving
        // prefix, so no frame is ever duplicated.
        $offset = (string) $this->writer->currentPartBytes();
        $part = $this->writer->currentPart();
        $rootHash = $this->writer->rootHashHex();
        $cp = new Checkpoint(
            $state,
            $state === self::STATE_DB ? 'db_stream' : ($state === self::STATE_FILES ? 'file_stream' : 'finalize'),
            $this->writer->sequence(),
            (string) $this->writer->logicalBytesEstimate(),
            [
                'table_index' => $this->tableIndex,
                'table'       => $this->currentTable,
                'pk'          => $this->pkCursor,
                'last_row_hash' => $this->lastRowHash,
                'row_count'   => $this->rowCount,
                'file_count'  => $this->fileCount,
                'file_bytes'  => $this->fileBytes,
                'path'        => $this->currentFile,
                'last_file_path' => $this->lastFilePath,
                'file_offset' => $this->currentFileOffset,
                'file_size'   => $this->currentFileSize,
                'file_stat'   => $this->currentFileStat,
                'last_chunk_size' => $this->lastFileChunkSize,
                'last_chunk_hash' => $this->lastFileChunkHash,
                'writer_state' => $this->writer->resumeState(),
            ],
            $offset,
            $part,
            $rootHash
        );
        $this->lastCheckpoint = $cp;
        $this->writer->writeJson(FrameType::CHECKPOINT, $cp->toArray());
        $this->batchesSinceCheckpoint = 0;
    }

    /**
     * Write manifest + footer + tail. Call after step() returns done.
     */
    public function finalize(): void
    {
        $this->writer->finalize([
            'file_count'  => $this->fileCount,
            'file_bytes'  => (string) $this->fileBytes,
            'row_count'   => $this->rowCount,
            'table_count' => count($this->tables),
        ]);
    }

    public function state(): string
    {
        return $this->state;
    }

    public function lastCheckpoint(): ?Checkpoint
    {
        return $this->lastCheckpoint;
    }
}
