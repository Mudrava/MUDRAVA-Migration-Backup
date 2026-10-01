<?php

/**
 * Import engine: streams a .mudrava archive forward and restores it.
 *
 * The reader is forward-only, but resume is SEEK-based, not skip-based. A
 * checkpoint records the physical frame boundary, root-hash chain and
 * sequence. Tables replay from their first frame; a partial file resumes
 * at its next data frame after validating its persisted byte count. This
 * is why the reader never re-reads the completed
 * prefix: a naive skip-forward would cost O(n) per tick and livelock once
 * the resume point sits deeper than one tick's frame budget.
 *
 * Success is declared ONLY after: root-hash integrity verified against the
 * manifest, DB done, files done, transforms applied, footer + tail reached.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Migration;

use Mudrava\Migration\Archive\Checkpoint;
use Mudrava\Migration\Archive\FrameReader;
use Mudrava\Migration\Archive\FrameType;
use Mudrava\Migration\Database\RowCodec;
use Mudrava\Migration\Database\ValueTransformer;
use Mudrava\Migration\Database\WpDatabaseTarget;
use Mudrava\Migration\Filesystem\PathGuard;
use Mudrava\Migration\Integration\ProtectedDirectories;

final class Importer
{
    public const STATE_RESTORE = 'restoring';
    public const STATE_DONE    = 'done';

    public const PHASE_DB    = 'database';
    public const PHASE_FILES = 'files';

    /** @var FrameReader */
    private $reader;

    /** @var DatabaseTarget */
    private $db;

    /** @var FileTarget */
    private $files;

    /** @var ValueTransformer|null */
    private $transformer;

    /** @var string */
    private $state = self::STATE_RESTORE;

    /** @var string current phase, for checkpoint state */
    private $phase = self::PHASE_DB;

    /** @var array<string,mixed>|null */
    private $siteMeta = null;

    /** @var list<string> */
    private $currentColumns = [];

    /** @var list<int> column indexes whose SQL types contain binary bytes */
    private $binaryColumns = [];

    /** @var ?string */
    private $currentTable = null;

    /** @var ?string Name stored in the archive, before prefix mapping. */
    private $sourceTable = null;

    /** @var string */
    private $sourcePrefix = '';

    /** @var string */
    private $targetPrefix = '';

    /** @var int seen DB_TABLE_BEGIN count */
    private $tableIndexSeen = 0;

    /** @var bool */
    private $writingFile = false;

    /** @var bool Skip archive files from an older copy of this plugin. */
    private $skipFile = false;

    /** @var ?string */
    private $pendingPath = null;

    /** @var int */
    private $pendingMode = 0644;

    /** @var int */
    private $pendingMtime = 0;

    /** @var int bytes in the open partial file at the checkpoint */
    private $pendingBytes = 0;

    /** @var int */
    private $restoredFiles = 0;

    /** @var int */
    private $restoredRows = 0;

    /** @var array<string,mixed>|null */
    private $manifest = null;

    /** @var string target site URL for auto rewrite (this site) */
    private $autoTarget = '';

    /** @var array{search:string,replace:string}|null rewrite actually in force */
    private $resolvedRewrite = null;

    // ---- resume boundary captured when a bounded step stops ---------------

    /** @var array{part:int,offset:int}|null */
    private $stopPosition = null;

    /** @var int */
    private $stopSequence = 0;

    /** @var string */
    private $stopHash = '';

    /** @var int|null Bytes at the saved boundary, before any replayed frame. */
    private $stopLogicalBytes;

    /**
     * @param array{search?:string,replace?:string,target?:string,target_prefix?:string} $urlRewrite
     *        Manual search/replace pair wins; when both are empty and a
     *        target is given, the rewrite is auto-derived from the
     *        archive's SITE_METADATA (source site URL -> this site).
     */
    public function __construct(
        FrameReader $reader,
        DatabaseTarget $db,
        FileTarget $files,
        array $urlRewrite = [],
        ?Checkpoint $resume = null
    ) {
        $this->reader = $reader;
        $this->db = $db;
        $this->files = $files;
        $this->autoTarget = (string) ($urlRewrite['target'] ?? '');
        $this->targetPrefix = (string) ($urlRewrite['target_prefix'] ?? '');
        if ($this->targetPrefix !== '' && preg_match('/^[A-Za-z0-9_]+$/', $this->targetPrefix) !== 1) {
            throw new \RuntimeException('MUDRAVA_DB_ERROR: invalid target prefix');
        }
        if (!empty($urlRewrite['search']) && !empty($urlRewrite['replace'])) {
            $this->transformer = new ValueTransformer($urlRewrite['search'], $urlRewrite['replace']);
            $this->resolvedRewrite = [
                'search' => (string) $urlRewrite['search'],
                'replace' => (string) $urlRewrite['replace'],
            ];
        }
        if ($resume !== null) {
            $this->restoreFrom($resume);
        }
    }

    /**
     * The rewrite actually applied (manual or auto-derived). Persisted with
     * the checkpoint so resumed ticks keep rewriting even though
     * SITE_METADATA was consumed by an earlier tick.
     *
     * @return array{search:string,replace:string}|null
     */
    public function resolvedRewrite(): ?array
    {
        return $this->resolvedRewrite;
    }

    /**
     * Rehydrate counters and seek the reader to the durable boundary. The
     * unit that begins at this boundary is reprocessed idempotently.
     */
    private function restoreFrom(Checkpoint $resume): void
    {
        $cursor = $resume->cursor;
        $this->phase = $resume->state === self::PHASE_FILES ? self::PHASE_FILES : self::PHASE_DB;
        $this->tableIndexSeen = (int) ($cursor['table_index'] ?? 0);
        $this->currentTable = isset($cursor['table']) ? (string) $cursor['table'] : null;
        $this->sourceTable = isset($cursor['source_table']) ? (string) $cursor['source_table'] : null;
        $this->sourcePrefix = (string) ($cursor['source_prefix'] ?? '');
        $this->currentColumns = is_array($cursor['columns'] ?? null)
            ? array_values(array_map('strval', (array) $cursor['columns']))
            : [];
        $this->binaryColumns = is_array($cursor['binary_columns'] ?? null)
            ? array_values(array_map('intval', (array) $cursor['binary_columns']))
            : [];
        $this->pendingPath = isset($cursor['path']) ? (string) $cursor['path'] : null;
        $this->pendingMode = (int) ($cursor['mode'] ?? 0644);
        $this->pendingMtime = (int) ($cursor['mtime'] ?? 0);
        $this->writingFile = (bool) ($cursor['writing'] ?? false);
        $this->skipFile = (bool) ($cursor['skip_file'] ?? false);
        $this->pendingBytes = (int) ($cursor['bytes_written'] ?? 0);
        $this->restoredRows = (int) ($cursor['row_count'] ?? 0);
        $this->restoredFiles = (int) ($cursor['file_count'] ?? 0);

        // SITE_METADATA was consumed by an earlier tick; the rewrite that
        // tick derived must survive into every resumed tick, or rows would
        // restore with stale source URLs.
        $rw = is_array($cursor['rewrite'] ?? null) ? $cursor['rewrite'] : null;
        if ($rw !== null && $this->transformer === null) {
            $search = (string) ($rw['search'] ?? '');
            $replace = (string) ($rw['replace'] ?? '');
            if ($search !== '' && $replace !== '') {
                $this->transformer = new ValueTransformer($search, $replace);
                $this->resolvedRewrite = ['search' => $search, 'replace' => $replace];
            }
        }

        $this->reader->resumeTo(
            $resume->part,
            (int) $resume->physicalOffset,
            $resume->sequence,
            $resume->rootHash,
            (int) $resume->logicalBytes
        );
        if ($this->writingFile) {
            if ($this->skipFile || $this->pendingPath === null || $this->pendingBytes < 0) {
                throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: invalid partial file checkpoint');
            }
            $this->files->beginFile($this->pendingPath, $this->pendingMode, true);
            if ($this->files->bytesWritten() < $this->pendingBytes) {
                throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: partial file shortened');
            }
            if ($this->files->bytesWritten() > $this->pendingBytes) {
                // The prior PHP request may have died after writing a frame
                // but before committing its checkpoint. Discard only that
                // uncommitted suffix and replay it from the archive.
                $this->files->truncateTo($this->pendingBytes);
            }
        }
    }

    /**
     * Process frames until the deadline elapses at a durable frame boundary,
     * or EOF. A file can stop after any complete FILE_DATA frame.
     * A null deadline runs to completion (single-shot import).
     */
    public function step(?float $deadline = null): string
    {
        if ($this->state === self::STATE_DONE) {
            return $this->state;
        }
        // Forward-progress guard: a tick may only stop at a boundary AFTER
        // having handled at least one boundary itself. Without this a
        // resumed tick would stop again at the very frame it just seeked
        // to and livelock forever (the exact shape of bug #9).
        $progress = false;
        while (true) {
            $posBefore = $this->reader->position();
            $seqBefore = $this->reader->lastSequence();
            $hashBefore = $this->reader->rootHashHex();
            $logicalBefore = $this->reader->logicalBytes();

            $frame = $this->reader->next();
            if ($frame === null) {
                return $this->finish();
            }

            // Stop at a unit boundary once the budget is spent, so the next
            // tick seeks here and reprocesses the unit idempotently. The
            // boundary frame itself is NOT handled yet; its position,
            // sequence, and chain state are recorded for the resume. Any
            // file still open is closed first, so the resumed tick (with a
            // fresh FileTarget that has no in-flight temp handle) starts
            // clean and the completed file is durably renamed into place.
            $isBoundary = $frame->type === FrameType::DB_TABLE_BEGIN
                || $frame->type === FrameType::FILE_METADATA;
            if ($isBoundary && $progress && $deadline !== null && microtime(true) >= $deadline) {
                if ($this->writingFile && $this->pendingPath !== null) {
                    $this->files->endFile($this->pendingPath, $this->pendingMtime);
                    $this->restoredFiles++;
                    $this->writingFile = false;
                    $this->pendingPath = null;
                }
                $this->stopPosition = $posBefore;
                $this->stopSequence = $seqBefore;
                $this->stopHash = $hashBefore;
                $this->stopLogicalBytes = $logicalBefore;
                return $this->state;
            }

            $this->handle($frame->type, $frame->payload);
            if ($isBoundary) {
                $progress = true;
            }
            if ($frame->type === FrameType::FILE_DATA && $deadline !== null && microtime(true) >= $deadline) {
                if ($this->writingFile) {
                    $this->pendingBytes = $this->files->bytesWritten();
                    $this->files->suspendFile();
                }
                $this->stopPosition = $this->reader->position();
                $this->stopSequence = $this->reader->lastSequence();
                $this->stopHash = $this->reader->rootHashHex();
                $this->stopLogicalBytes = $this->reader->logicalBytes();
                return $this->state;
            }
        }
    }

    private function handle(int $type, string $payload): void
    {
        switch ($type) {
            case FrameType::SITE_METADATA:
                $this->siteMeta = json_decode($payload, true);
                $prefix = is_array($this->siteMeta) ? (string) ($this->siteMeta['db_prefix'] ?? '') : '';
                if ($prefix !== '' && preg_match('/^[A-Za-z0-9_]+$/', $prefix) !== 1) {
                    throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: invalid source prefix');
                }
                $this->sourcePrefix = $prefix;
                // Auto rewrite: nobody typed URLs, but the archive knows
                // where it came from. Source site -> this site.
                if (
                    $this->transformer === null
                    && $this->autoTarget !== ''
                    && is_array($this->siteMeta)
                ) {
                    $source = (string) ($this->siteMeta['site_url'] ?? '');
                    if ($source !== '' && $source !== $this->autoTarget) {
                        $this->transformer = new ValueTransformer($source, $this->autoTarget);
                        $this->resolvedRewrite = ['search' => $source, 'replace' => $this->autoTarget];
                    }
                }
                return;

            case FrameType::DB_TABLE_BEGIN:
                $this->phase = self::PHASE_DB;
                $this->tableIndexSeen++;
                $data = json_decode($payload, true);
                $this->sourceTable = (string) $data['table'];
                $this->currentTable = $this->mappedTable($this->sourceTable);
                $this->currentColumns = is_array($data['columns'] ?? null)
                    ? array_values(array_map('strval', (array) $data['columns']))
                    : [];
                $this->binaryColumns = [];
                return;

            case FrameType::DB_SCHEMA:
                if ($this->currentTable === null) {
                    return;
                }
                $this->binaryColumns = $this->binaryColumnIndexes($payload);
                if ($this->sourceTable === null || WpDatabaseTarget::validateSchema($payload) !== $this->sourceTable) {
                    throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: schema table mismatch');
                }
                if ($this->currentTable !== $this->sourceTable) {
                    $payload = (string) preg_replace(
                        '/^CREATE TABLE\s+`?[A-Za-z0-9_]+`?/i',
                        'CREATE TABLE `' . $this->currentTable . '`',
                        $payload,
                        1
                    );
                }
                // DROP+CREATE: idempotent under replay of a resumed table.
                $this->db->createTable($payload);
                return;

            case FrameType::DB_ROWS:
                if ($this->currentTable === null) {
                    return;
                }
                $rows = RowCodec::decode($payload);
                $rows = $this->transformRows($rows);
                $this->db->insertBatch($this->currentTable, $this->currentColumns, $rows);
                $this->restoredRows += count($rows);
                return;

            case FrameType::DB_TABLE_END:
                return;

            case FrameType::FILE_METADATA:
                $this->phase = self::PHASE_FILES;
                $data = json_decode($payload, true);
                $path = (string) $data['path'];
                // PathGuard is mandatory on the restore path - before anything else.
                PathGuard::normalize($path);

                // Deterministically close the previous file (idempotent: a
                // resumed tick re-reads this metadata and re-closes it).
                if ($this->writingFile && $this->pendingPath !== null) {
                    $this->files->endFile($this->pendingPath, $this->pendingMtime);
                    $this->restoredFiles++;
                    $this->writingFile = false;
                }

                // A historical archive may contain the source checkout of
                // this very plugin. Do not replace the code executing the
                // import, even when the archive predates the export exclude.
                $this->skipFile = $this->isCurrentPluginPath($path);
                if ($this->skipFile) {
                    $this->pendingPath = null;
                    return;
                }

                if (($data['type'] ?? 'file') === 'symlink') {
                    $target = (string) ($data['target'] ?? '');
                    if (PathGuard::symlinkTargetSafe($this->files->root(), $target)) {
                        $this->files->makeSymlink($path, $target);
                    }
                    // A symlink has no FILE_DATA stream: never leave a pending
                    // regular file open on this path.
                    $this->writingFile = false;
                    $this->pendingPath = null;
                    $this->restoredFiles++;
                    return;
                }

                $this->files->beginFile($path, (int) ($data['mode'] ?? 0644), false);
                $this->writingFile = true;
                $this->pendingPath = $path;
                $this->pendingMode = (int) ($data['mode'] ?? 0644);
                $this->pendingMtime = (int) ($data['mtime'] ?? 0);
                return;

            case FrameType::FILE_DATA:
                if ($this->skipFile || !$this->writingFile) {
                    return;
                }
                $this->files->appendChunk($payload);
                return;

            case FrameType::CHECKPOINT:
                return;

            case FrameType::MANIFEST:
                $this->manifest = json_decode($payload, true);
                return;

            case FrameType::FOOTER:
                return;
        }
    }

    private function isCurrentPluginPath(string $path): bool
    {
        return ProtectedDirectories::contains($path, $this->files->root());
    }

    /** Map only tables that belong to the source WordPress prefix. */
    private function mappedTable(string $table): string
    {
        if (
            $this->sourcePrefix === '' || $this->targetPrefix === ''
            || $this->sourcePrefix === $this->targetPrefix
            || strpos($table, $this->sourcePrefix) !== 0
        ) {
            return $table;
        }
        return $this->targetPrefix . substr($table, strlen($this->sourcePrefix));
    }

    /**
     * @param list<list<?string>> $rows
     * @return list<list<?string>>
     */
    private function transformRows(array $rows): array
    {
        $keyColumn = null;
        if ($this->sourcePrefix !== '' && $this->targetPrefix !== '' && $this->sourcePrefix !== $this->targetPrefix) {
            if ($this->sourceTable === $this->sourcePrefix . 'options') {
                $keyColumn = array_search('option_name', $this->currentColumns, true);
            } elseif ($this->sourceTable === $this->sourcePrefix . 'usermeta') {
                $keyColumn = array_search('meta_key', $this->currentColumns, true);
            }
        }
        foreach ($rows as &$row) {
            foreach ($row as $index => &$cell) {
                if ($this->transformer !== null && is_string($cell) && !in_array($index, $this->binaryColumns, true)) {
                    $cell = $this->transformer->transform($cell);
                }
            }
            unset($cell);
            if (
                $keyColumn !== false && $keyColumn !== null
                && isset($row[$keyColumn]) && is_string($row[$keyColumn])
                && strpos($row[$keyColumn], $this->sourcePrefix) === 0
            ) {
                $row[$keyColumn] = $this->targetPrefix . substr($row[$keyColumn], strlen($this->sourcePrefix));
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * SHOW CREATE TABLE quotes column identifiers. Read only their declared
     * types, so URL rewriting never touches BLOB, VARBINARY, BIT or geometry
     * payloads even when their bytes happen to contain the source URL.
     *
     * @return list<int>
     */
    private function binaryColumnIndexes(string $schema): array
    {
        $indexes = [];
        foreach ($this->currentColumns as $index => $column) {
            $binaryTypes = 'tinyblob|mediumblob|longblob|blob|varbinary|binary|bit|'
                . 'geometry|point|linestring|polygon|multipoint|multilinestring|'
                . 'multipolygon|geometrycollection';
            $pattern = '/(?:\(|,)\s*`' . preg_quote($column, '/') . '`\s+(?:' . $binaryTypes . ')\b/i';
            if (preg_match($pattern, $schema) === 1) {
                $indexes[] = $index;
            }
        }
        return $indexes;
    }

    private function finish(): string
    {
        if ($this->writingFile && $this->pendingPath !== null) {
            $this->files->endFile($this->pendingPath, $this->pendingMtime);
            $this->restoredFiles++;
            $this->writingFile = false;
        }
        if ($this->manifest === null) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_TRUNCATED: no manifest before EOF');
        }
        // Authoritative integrity check - the ONLY thing that may declare success.
        $this->reader->verifyAgainstManifest($this->manifest);
        $this->state = self::STATE_DONE;
        return $this->state;
    }

    /**
     * Durable checkpoint for the job runner. When a bounded step stopped at
     * a unit boundary, this points at that boundary (the not-yet-handled
     * frame) so the next tick seeks there and reprocesses the unit.
     *
     * @return array<string,mixed>
     */
    public function checkpointData(): array
    {
        $pos = $this->stopPosition ?? $this->reader->position();
        $sequence = $this->stopPosition !== null ? $this->stopSequence : $this->reader->lastSequence();
        $hash = $this->stopPosition !== null ? $this->stopHash : $this->reader->rootHashHex();
        return [
            'state'           => $this->phase,
            'stage'           => $this->phase === self::PHASE_FILES ? 'file_stream' : 'db_stream',
            'part'            => (int) $pos['part'],
            'physical_offset' => (string) $pos['offset'],
            'sequence'        => (int) $sequence,
            'logical_bytes'   => (string) ($this->stopLogicalBytes ?? $this->reader->logicalBytes()),
            'root_hash'       => $hash,
            'cursor'          => (object) [
                'table_index' => $this->tableIndexSeen,
                'table'       => $this->currentTable,
                'source_table' => $this->sourceTable,
                'source_prefix' => $this->sourcePrefix,
                'columns'     => $this->currentColumns,
                'binary_columns' => $this->binaryColumns,
                'path'        => $this->pendingPath,
                'mode'        => $this->pendingMode,
                'mtime'       => $this->pendingMtime,
                'writing'     => $this->writingFile,
                'bytes_written' => $this->pendingBytes,
                'skip_file'   => $this->skipFile,
                'row_count'   => $this->restoredRows,
                'file_count'  => $this->restoredFiles,
                'rewrite'     => $this->resolvedRewrite,
            ],
            'updated_at'      => time(),
        ];
    }

    public function state(): string
    {
        return $this->state;
    }

    /** Current restore phase: database or files (for human progress). */
    public function phase(): string
    {
        return $this->phase;
    }

    public function restoredFiles(): int
    {
        return $this->restoredFiles;
    }

    public function restoredRows(): int
    {
        return $this->restoredRows;
    }

    /** @return array<string,mixed>|null */
    public function siteMeta(): ?array
    {
        return $this->siteMeta;
    }

    public function transformFailures(): int
    {
        return $this->transformer !== null ? $this->transformer->parseFailures() : 0;
    }
}
