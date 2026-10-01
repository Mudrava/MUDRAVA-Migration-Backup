<?php

/**
 * Read the entire archive before an import is allowed to mutate the site.
 * The scan is resumable at frame boundaries and uses no database or file
 * target. It checks the same CRC/AEAD/root-hash chain as the importer.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Migration;

use Mudrava\Migration\Archive\FrameReader;
use Mudrava\Migration\Archive\FrameType;
use Mudrava\Migration\Database\RowCodec;
use Mudrava\Migration\Database\WpDatabaseTarget;
use Mudrava\Migration\Filesystem\PathGuard;

final class ArchiveVerifier
{
    /** @var FrameReader */
    private $reader;

    /** @var string */
    private $phase = 'start';

    /** @var bool */
    private $tableOpen = false;

    /** @var bool */
    private $schemaSeen = false;

    /** @var string|null */
    private $currentTable = null;

    /** @var int */
    private $columnCount = 0;

    /** @var bool */
    private $fileOpen = false;

    /** @var int|null Older archives may omit the declared file length. */
    private $expectedFileBytes;

    /** @var int */
    private $seenFileBytes = 0;

    /** @var array<string,mixed>|null */
    private $manifest = null;

    /** @var bool */
    private $footerSeen = false;

    /** @var int */
    private $seenRows = 0;

    /** @var int */
    private $seenFiles = 0;

    /** @var bool Older in-flight checkpoints did not record these counters. */
    private $countsKnown = true;

    /** @var bool Older in-flight checkpoints did not record this counter. */
    private $logicalBytesKnown = true;

    /** @var callable|null fn(string,bool):void */
    private $pathValidator;

    /**
     * @param array<string,mixed>|null $checkpoint
     */
    public function __construct(FrameReader $reader, ?array $checkpoint = null, ?callable $pathValidator = null)
    {
        $this->reader = $reader;
        $this->pathValidator = $pathValidator;
        if ($checkpoint !== null) {
            $this->logicalBytesKnown = isset($checkpoint['logical_bytes']);
            $this->countsKnown = isset($checkpoint['seen_rows'], $checkpoint['seen_files']);
            $this->seenRows = (int) ($checkpoint['seen_rows'] ?? 0);
            $this->seenFiles = (int) ($checkpoint['seen_files'] ?? 0);
            $reader->resumeTo(
                (int) ($checkpoint['part'] ?? 1),
                (int) ($checkpoint['offset'] ?? 0),
                (int) ($checkpoint['sequence'] ?? 0),
                (string) ($checkpoint['root_hash'] ?? ''),
                (int) ($checkpoint['logical_bytes'] ?? 0)
            );
            $this->phase = (string) ($checkpoint['phase'] ?? 'start');
            $this->tableOpen = (bool) ($checkpoint['table_open'] ?? false);
            $this->schemaSeen = (bool) ($checkpoint['schema_seen'] ?? false);
            $this->fileOpen = (bool) ($checkpoint['file_open'] ?? false);
            $this->expectedFileBytes = isset($checkpoint['expected_file_bytes'])
                ? (int) $checkpoint['expected_file_bytes'] : null;
            $this->seenFileBytes = (int) ($checkpoint['seen_file_bytes'] ?? 0);
            $this->currentTable = isset($checkpoint['table']) ? (string) $checkpoint['table'] : null;
            $this->columnCount = (int) ($checkpoint['column_count'] ?? 0);
        }
    }

    /** Return true only after the manifest, footer, and tail are verified. */
    public function step(float $deadline): bool
    {
        while (true) {
            $frame = $this->reader->next();
            if ($frame === null) {
                $this->assertFileComplete();
                if ($this->manifest === null || !$this->footerSeen || $this->tableOpen) {
                    throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: incomplete archive structure');
                }
                $this->reader->verifyAgainstManifest($this->manifest);
                return true;
            }
            $this->inspect($frame->type, $frame->payload);
            // Once the manifest is seen, finish the short footer/tail without
            // checkpointing: FrameReader keeps its manifest hash in memory.
            if ($this->manifest === null && microtime(true) >= $deadline) {
                return false;
            }
        }
    }

    /** @return array<string,mixed> */
    public function checkpoint(): array
    {
        $position = $this->reader->position();
        return [
            'part'        => $position['part'],
            'offset'      => $position['offset'],
            'sequence'    => $this->reader->lastSequence(),
            'root_hash'   => $this->reader->rootHashHex(),
            'logical_bytes' => $this->reader->logicalBytes(),
            'seen_rows'   => $this->seenRows,
            'seen_files'  => $this->seenFiles,
            'phase'       => $this->phase,
            'table_open'  => $this->tableOpen,
            'schema_seen' => $this->schemaSeen,
            'file_open'   => $this->fileOpen,
            'expected_file_bytes' => $this->expectedFileBytes,
            'seen_file_bytes' => $this->seenFileBytes,
            'table'       => $this->currentTable,
            'column_count' => $this->columnCount,
        ];
    }

    private function inspect(int $type, string $payload): void
    {
        if ($this->footerSeen) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: frame after footer');
        }
        if ($this->phase === 'start' && $type !== FrameType::SITE_METADATA) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: site metadata missing');
        }
        switch ($type) {
            case FrameType::SITE_METADATA:
                if ($this->phase !== 'start' || !is_array(json_decode($payload, true))) {
                    throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: invalid site metadata');
                }
                $this->phase = 'database';
                return;
            case FrameType::DB_TABLE_BEGIN:
                $data = json_decode($payload, true);
                if (
                    $this->phase !== 'database' || $this->tableOpen || !is_array($data)
                    || !isset($data['table']) || !is_string($data['table'])
                    || preg_match('/^[A-Za-z0-9_]+$/', $data['table']) !== 1
                    || !isset($data['columns']) || !is_array($data['columns'])
                    || $data['columns'] === []
                ) {
                    throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: invalid table metadata');
                }
                foreach ($data['columns'] as $column) {
                    if (!is_string($column) || preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1) {
                        throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: invalid column name');
                    }
                }
                $this->tableOpen = true;
                $this->schemaSeen = false;
                $this->currentTable = $data['table'];
                $this->columnCount = count($data['columns']);
                return;
            case FrameType::DB_SCHEMA:
                if (
                    !$this->tableOpen || $this->schemaSeen
                    || WpDatabaseTarget::validateSchema($payload) !== $this->currentTable
                ) {
                    throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: invalid table schema');
                }
                $this->schemaSeen = true;
                return;
            case FrameType::DB_ROWS:
                if (!$this->tableOpen || !$this->schemaSeen) {
                    throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: rows outside table');
                }
                foreach (RowCodec::decode($payload) as $row) {
                    if (count($row) !== $this->columnCount) {
                        throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: row column count mismatch');
                    }
                    $this->seenRows++;
                }
                return;
            case FrameType::DB_TABLE_END:
                $end = json_decode($payload, true);
                if (
                    !$this->tableOpen || !$this->schemaSeen || !is_array($end)
                    || ($end['table'] ?? null) !== $this->currentTable
                    || ($this->countsKnown && array_key_exists('row_count', $end) && $end['row_count'] !== $this->seenRows)
                ) {
                    throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: invalid table end');
                }
                $this->tableOpen = false;
                $this->currentTable = null;
                $this->columnCount = 0;
                return;
            case FrameType::FILE_METADATA:
                $data = json_decode($payload, true);
                $this->assertFileComplete();
                if ($this->tableOpen || !is_array($data) || !isset($data['path']) || !is_string($data['path'])) {
                    throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: invalid file metadata');
                }
                PathGuard::normalize($data['path']);
                if (array_key_exists('size', $data) && (!is_int($data['size']) || $data['size'] < 0)) {
                    throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: invalid file size');
                }
                $isSymlink = ($data['type'] ?? 'file') === 'symlink';
                if ($this->pathValidator !== null) {
                    ($this->pathValidator)($data['path'], $isSymlink);
                }
                $this->phase = 'files';
                $this->fileOpen = !$isSymlink;
                $this->expectedFileBytes = $this->fileOpen && isset($data['size']) ? $data['size'] : null;
                $this->seenFileBytes = 0;
                if (!$this->fileOpen && !PathGuard::symlinkTargetSafe('', (string) ($data['target'] ?? ''))) {
                    throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: invalid symlink target');
                }
                $this->seenFiles++;
                return;
            case FrameType::FILE_DATA:
                if (!$this->fileOpen) {
                    throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: file data without metadata');
                }
                $this->seenFileBytes += strlen($payload);
                if ($this->expectedFileBytes !== null && $this->seenFileBytes > $this->expectedFileBytes) {
                    throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: file exceeds declared size');
                }
                return;
            case FrameType::CHECKPOINT:
                return;
            case FrameType::MANIFEST:
                $this->assertFileComplete();
                $data = json_decode($payload, true);
                if (
                    $this->tableOpen || !is_array($data) || $this->manifest !== null
                    || ($this->countsKnown && array_key_exists('row_count', $data) && $data['row_count'] !== $this->seenRows)
                    || ($this->countsKnown && array_key_exists('file_count', $data) && $data['file_count'] !== $this->seenFiles)
                ) {
                    throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: invalid manifest');
                }
                $this->manifest = $data;
                return;
            case FrameType::FOOTER:
                $footer = json_decode($payload, true);
                $position = $this->reader->position();
                if (
                    $this->manifest === null || !is_array($footer)
                    || ($footer['manifest_seq'] ?? null) !== $this->reader->lastSequence() - 1
                    || !in_array(($footer['total_frames'] ?? null), [
                        $this->reader->lastSequence(),
                        $this->reader->lastSequence() - 1,
                    ], true)
                    || ($this->logicalBytesKnown && ($footer['logical_bytes'] ?? null) !== (string) ($this->reader->logicalBytes() - strlen($payload)))
                    || ($footer['parts_expected'] ?? null) !== $position['part']
                ) {
                    throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: invalid footer');
                }
                $this->footerSeen = true;
                return;
            default:
                if (FrameType::isOptional($type)) {
                    return;
                }
                throw new \RuntimeException('MUDRAVA_FORMAT_UNSUPPORTED: unexpected frame type');
        }
    }

    private function assertFileComplete(): void
    {
        if (
            $this->fileOpen && $this->expectedFileBytes !== null
            && $this->seenFileBytes !== $this->expectedFileBytes
        ) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: file size mismatch');
        }
    }
}
