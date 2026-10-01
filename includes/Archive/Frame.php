<?php

/**
 * A decoded frame. Payload is logical (decrypted, decompressed) bytes.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Archive;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are reduced to safe codes at the REST boundary.

final class Frame
{
    /** @var int */
    public $type;
    /** @var int */
    public $sequence;
    /** @var string */
    public $payload;
    /** @var bool */
    public $compressed;
    /** @var bool */
    public $encrypted;

    public function __construct(int $type, int $sequence, string $payload, bool $compressed = false, bool $encrypted = false)
    {
        $this->type = $type;
        $this->sequence = $sequence;
        $this->payload = $payload;
        $this->compressed = $compressed;
        $this->encrypted = $encrypted;
    }

    /**
     * @return array<mixed> decoded JSON payload
     */
    public function json(): array
    {
        $decoded = json_decode($this->payload, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: frame ' . $this->sequence . ' is not valid JSON');
        }
        return $decoded;
    }
}
