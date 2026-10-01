<?php

/**
 * Filesystem restore target. Every path passes PathGuard before touching
 * disk; nothing is ever written outside the destination root.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Migration;

interface FileTarget
{
    /**
     * Open a temp stream for the next file chunk sequence.
     * $append = true reopens the existing partial file for mid-file resume.
     */
    public function beginFile(string $relativePath, int $mode, bool $append = false): void;

    public function appendChunk(string $bytes): void;

    /** Flush and close a partial temp file without publishing it. */
    public function suspendFile(): void;

    /** Discard bytes written after the last persisted checkpoint. */
    public function truncateTo(int $bytes): void;

    /** Flush + rename temp into place. */
    public function endFile(string $relativePath, int $mtime): void;

    public function makeSymlink(string $relativePath, string $target): void;

    public function root(): string;

    /** Bytes written to the current file (for durable checkpoints). */
    public function bytesWritten(): int;
}
