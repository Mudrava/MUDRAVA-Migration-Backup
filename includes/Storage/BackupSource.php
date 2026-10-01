<?php

/**
 * Durable source of archive bytes for import. One forward-only parser
 * (FrameReader) consumes any implementation.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Storage;

interface BackupSource
{
    /** @return resource readable stream positioned at the current offset */
    public function stream();

    public function eof(): bool;

    public function close(): void;
}
