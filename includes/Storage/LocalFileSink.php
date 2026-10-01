<?php

/**
 * Local filesystem sink with split support. All archives live under a
 * protected directory inside wp-content with direct web access denied.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Storage;

use Mudrava\Migration\Archive\Checkpoint;
use Mudrava\Migration\Archive\Manifest;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Protected binary archives require seekable native streams and atomic file operations.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are reduced to safe codes at the REST boundary.

final class LocalFileSink implements BackupSink
{
    /** @var string */
    private $basePath;

    /** @var resource|null */
    private $handle;

    /** @var int */
    private $part = 1;

    /** @var int */
    private $bytes = 0;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    public function open(string $archiveId): void
    {
        $dir = dirname($this->basePath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: cannot create ' . $dir);
        }
        $handle = @fopen($this->basePath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: cannot open ' . $this->basePath);
        }
        $this->handle = $handle;
        $this->part = 1;
        $this->bytes = 0;
    }

    public function append(string $bytes): void
    {
        if ($this->handle === null) {
            throw new \LogicException('Sink not open.');
        }
        $total = strlen($bytes);
        $written = 0;
        while ($written < $total) {
            $n = fwrite($this->handle, substr($bytes, $written));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('MUDRAVA_DISK_FULL: append failed');
            }
            $written += $n;
        }
        $this->bytes += $total;
    }

    public function checkpoint(Checkpoint $checkpoint): void
    {
        if ($this->handle !== null) {
            fflush($this->handle);
        }
    }

    public function finalize(Manifest $manifest): void
    {
        $this->close();
    }

    public function close(): void
    {
        if ($this->handle !== null) {
            fflush($this->handle);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /** Rotate to the next physical part (called by the exporter splitter). */
    public function rotatePart(): void
    {
        $this->close();
        $this->part++;
        $path = sprintf('%s.part%04d', $this->basePath, $this->part);
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: cannot open ' . $path);
        }
        $this->handle = $handle;
    }

    public function path(): string
    {
        return $this->basePath;
    }
}
