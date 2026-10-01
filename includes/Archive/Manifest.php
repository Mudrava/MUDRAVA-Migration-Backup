<?php

/**
 * Archive manifest (spec §10).
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Archive;

final class Manifest
{
    /** @var array<string,mixed> */
    private $data;

    /**
     * @param array<string,mixed> $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function rootHash(): string
    {
        return (string) ($this->data['root_hash'] ?? '');
    }

    public function frameCount(): int
    {
        return (int) ($this->data['frame_count'] ?? 0);
    }

    public function fileCount(): int
    {
        return (int) ($this->data['file_count'] ?? 0);
    }

    public function rowCount(): int
    {
        return (int) ($this->data['row_count'] ?? 0);
    }

    public function fileBytes(): string
    {
        return (string) ($this->data['file_bytes'] ?? '0');
    }
}
