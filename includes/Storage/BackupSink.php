<?php

/**
 * Durable destination for archive bytes. Free ships LocalFileSink and
 * BrowserDownloadSink; separately installed add-ons can register other sinks.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Storage;

use Mudrava\Migration\Archive\Checkpoint;
use Mudrava\Migration\Archive\Manifest;

interface BackupSink
{
    public function open(string $archiveId): void;

    public function append(string $bytes): void;

    public function checkpoint(Checkpoint $checkpoint): void;

    public function finalize(Manifest $manifest): void;

    public function close(): void;
}
