<?php
/**
 * Real-filesystem FileTarget for engine tests. Writes under a temp root,
 * exercising the same PathGuard + temp/rename flow as the WP adapter.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Support;

use Mudrava\Migration\Filesystem\PathGuard;
use Mudrava\Migration\Migration\FileTarget;

final class LocalFileTarget implements FileTarget
{
    /** @var string */
    private $root;

    /** @var resource|null */
    private $handle;

    /** @var string|null */
    private $tempPath;

    /** @var int */
    private $bytes = 0;

    /** @var int files symlinked */
    public $symlinks = 0;

    public function __construct(string $root)
    {
        $this->root = $root;
        if (!is_dir($root)) {
            mkdir($root, 0777, true);
        }
    }

    public function beginFile(string $relativePath, int $mode, bool $append = false): void
    {
        $full = PathGuard::resolveInside($this->root, $relativePath);
        $dir = dirname($full);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        if ($append && !is_file($full)) {
            throw new \RuntimeException('partial file missing: ' . $full);
        }
        $this->tempPath = $full;
        $this->handle = fopen($full, $append ? 'ab' : 'wb');
        if ($this->handle === false) {
            throw new \RuntimeException('cannot open ' . $full);
        }
        $this->bytes = $append ? filesize($full) : 0;
    }

    public function appendChunk(string $bytes): void
    {
        if ($this->handle === null) {
            throw new \LogicException('no file open');
        }
        fwrite($this->handle, $bytes);
        $this->bytes += strlen($bytes);
    }

    public function suspendFile(): void
    {
        if ($this->handle === null) {
            throw new \LogicException('no file open');
        }
        fflush($this->handle);
        fclose($this->handle);
        $this->handle = null;
    }

    public function truncateTo(int $bytes): void
    {
        if ($this->handle === null || $bytes < 0 || $bytes > $this->bytes) {
            throw new \RuntimeException('invalid partial file length');
        }
        if (!ftruncate($this->handle, $bytes) || fseek($this->handle, $bytes, SEEK_SET) !== 0) {
            throw new \RuntimeException('partial file truncate failed');
        }
        $this->bytes = $bytes;
    }

    public function endFile(string $relativePath, int $mtime): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
        if ($this->tempPath !== null && file_exists($this->tempPath)) {
            @chmod($this->tempPath, $mtime !== 0 ? 0644 : 0644);
        }
        $this->tempPath = null;
    }

    public function makeSymlink(string $relativePath, string $target): void
    {
        $full = PathGuard::resolveInside($this->root, $relativePath);
        $dir = dirname($full);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        if (is_link($full) || is_file($full)) {
            @unlink($full);
        }
        if (!@symlink($target, $full)) {
            throw new \RuntimeException('symlink failed: ' . $full);
        }
        $this->symlinks++;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function bytesWritten(): int
    {
        return $this->bytes;
    }
}
