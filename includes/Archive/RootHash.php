<?php

/**
 * Rolling root-hash chain over every frame (spec §10).
 * h0 = SHA-256("MUDRAVA-ROOT-V1" || archive_uuid)
 * h(i+1) = SHA-256(h(i) || frame_type || pack('P', sequence) || pack('N', payload_crc32))
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Archive;

final class RootHash
{
    public const SEED = 'MUDRAVA-ROOT-V1';

    /** @var string raw 32-byte state */
    private $state;

    public function __construct(string $archiveUuid)
    {
        if (strlen($archiveUuid) !== 16) {
            throw new \InvalidArgumentException('archive_uuid must be 16 bytes.');
        }
        $this->state = hash('sha256', self::SEED . $archiveUuid, true);
    }

    /** Rehydrate mid-chain state (resume). */
    public static function fromState(string $rawState): self
    {
        if (strlen($rawState) !== 32) {
            throw new \InvalidArgumentException('root hash state must be 32 bytes.');
        }
        $self = new self(str_repeat("\0", 16));
        $self->state = $rawState;
        return $self;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function update(int $frameType, int $sequence, int $payloadCrc32): void
    {
        $this->state = hash(
            'sha256',
            $this->state . chr($frameType) . pack('P', $sequence) . pack('N', $payloadCrc32),
            true
        );
    }

    public function hex(): string
    {
        return bin2hex($this->state);
    }
}
