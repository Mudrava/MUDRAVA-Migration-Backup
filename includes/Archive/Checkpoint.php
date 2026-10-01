<?php

/**
 * Durable resume position (spec §8).
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Archive;

final class Checkpoint
{
    /** @var string */
    public $state;
    /** @var string */
    public $stage;
    /** @var int */
    public $sequence;
    /** @var string decimal string - never a float */
    public $logicalBytes;
    /** @var array<string,mixed> */
    public $cursor;
    /** @var string decimal string - physical byte offset of this checkpoint's start */    public $physicalOffset;
    /** @var int */
    public $part;
    /** @var string hex root-hash state at the checkpoint boundary */
    public $rootHash;

    /**
     * @param array<string,mixed> $cursor
     */
    public function __construct(
        string $state,
        string $stage,
        int $sequence,
        string $logicalBytes,
        array $cursor = [],
        string $physicalOffset = '0',
        int $part = 1,
        string $rootHash = ''
    ) {
        $this->state = $state;
        $this->stage = $stage;
        $this->sequence = $sequence;
        $this->logicalBytes = $logicalBytes;
        $this->cursor = $cursor;
        $this->physicalOffset = $physicalOffset;
        $this->part = $part;
        $this->rootHash = $rootHash;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'state'           => $this->state,
            'stage'           => $this->stage,
            'sequence'        => $this->sequence,
            'logical_bytes'   => $this->logicalBytes,
            'physical_offset' => $this->physicalOffset,
            'part'            => $this->part,
            'root_hash'       => $this->rootHash,
            'cursor'          => (object) $this->cursor,
            'updated_at'      => time(),
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        // The cursor is persisted as an object (JSON/option round-trip);
        // accept both stdClass and plain arrays so a resumed tick never
        // silently loses its position and restarts the current table.
        $cursor = $data['cursor'] ?? [];
        if ($cursor instanceof \stdClass) {
            $cursor = (array) $cursor;
        }
        return new self(
            (string) ($data['state'] ?? 'unknown'),
            (string) ($data['stage'] ?? 'unknown'),
            (int) ($data['sequence'] ?? 0),
            (string) ($data['logical_bytes'] ?? '0'),
            is_array($cursor) ? $cursor : [],
            (string) ($data['physical_offset'] ?? '0'),
            (int) ($data['part'] ?? 1),
            (string) ($data['root_hash'] ?? '')
        );
    }
}
