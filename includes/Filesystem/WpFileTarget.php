<?php

/**
 * FileTarget writing into the WordPress document root. Every path passes
 * PathGuard; files are written to a temp path and published only after the
 * last chunk. New paths use an exclusive hardlink, and existing paths use
 * rename, so a crash never exposes a half-written final file.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Filesystem;

use Mudrava\Migration\Migration\FileTarget;
use Mudrava\Migration\Rollback\ImportJournal;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Protected binary archives require seekable native streams and atomic file operations.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are reduced to safe codes at the REST boundary.

final class WpFileTarget implements FileTarget
{
    /** @var string */
    private $root;

    /** @var resource|null */
    private $handle;

    /** @var string|null */
    private $tempPath;

    /** @var array{dev:int,ino:int}|null */
    private $tempIdentity;

    /** @var string|null */
    private $destinationPath;

    /** @var array{dev:int,ino:int,mode:int}|null */
    private $destinationIdentity;

    /** @var resource|null */
    private $destinationHandle;

    /** @var int */
    private $bytes = 0;

    /** @var ImportJournal|null */
    private $journal;

    /** @var bool */
    private $journalCreatedDestination = false;

    public function __construct(?string $root = null, ?ImportJournal $journal = null)
    {
        $this->root = rtrim($root ?: ABSPATH, '/');
        $this->journal = $journal;
    }

    /**
     * Validate an incoming archive path without creating or replacing
     * anything. Archive verification calls this before the import is marked
     * verified, so common ownership and stale-temp failures are reported
     * before database or destination-file mutation begins.
     */
    public function assertPathWritable(string $relativePath, bool $symlink = false): void
    {
        $full = PathGuard::resolveDestinationInside($this->root, $relativePath);
        $parent = dirname($full);
        while (!file_exists($parent) && !is_link($parent)) {
            $next = dirname($parent);
            if ($next === $parent) {
                break;
            }
            $parent = $next;
        }
        if (!is_dir($parent) || !is_writable($parent)) {
            throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: destination directory is not writable');
        }

        clearstatcache(true, $full);
        $destination = @lstat($full);
        if ($destination !== false) {
            $kind = (int) $destination['mode'] & 0170000;
            if ($kind === 0040000 || (!$symlink && $kind !== 0100000)) {
                throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: destination type cannot be replaced safely');
            }
            if (!$symlink && !is_readable($full)) {
                throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: destination file is not readable');
            }
        }

        $temp = $full . '.mudrava-tmp';
        clearstatcache(true, $temp);
        $existing = @lstat($temp);
        if ($existing !== false) {
            $kind = (int) $existing['mode'] & 0170000;
            if ((int) $existing['nlink'] !== 1 || ($kind !== 0100000 && (!$symlink || $kind !== 0120000))) {
                throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: temporary path is unsafe');
            }
        }
    }

    public function beginFile(string $relativePath, int $mode, bool $append = false): void
    {
        $full = PathGuard::resolveDestinationInside($this->root, $relativePath);
        $dir = dirname($full);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: cannot create ' . $dir);
        }
        // Temp lives next to the target so publication stays on one fs.
        // Mid-file resume must continue the existing temp file. Never fall
        // back to the published file or silently create a new partial file.
        $temp = $full . '.mudrava-tmp';
        if ($append && !file_exists($temp) && !is_link($temp)) {
            throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: partial file missing');
        }
        // A fresh file gets a new inode. Discard only a single-link regular
        // stale temp; never truncate a symlink or a hardlink to another file.
        clearstatcache(true, $temp);
        $existing = @lstat($temp);
        if (
            $existing !== false
            && (($existing['mode'] & 0170000) !== 0100000 || $existing['nlink'] !== 1)
        ) {
            throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: temporary path is unsafe');
        }
        if (!$append && $existing !== false && !@unlink($temp)) {
            throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: cannot replace temporary file');
        }
        // x+b creates a fresh inode exclusively; r+b requires the existing
        // inode on resume. Neither mode truncates a path before validation.
        $handle = @fopen($temp, $append ? 'r+b' : 'x+b');
        if ($handle === false) {
            throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: cannot write ' . $relativePath);
        }
        clearstatcache(true, $temp);
        $opened = fstat($handle);
        $named = @lstat($temp);
        if (
            $opened === false || $named === false
            || ($opened['mode'] & 0170000) !== 0100000
            || ($named['mode'] & 0170000) !== 0100000
            || $opened['nlink'] !== 1 || $named['nlink'] !== 1
            || $opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino']
        ) {
            fclose($handle);
            throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: temporary path changed');
        }
        if ($this->journal !== null) {
            try {
                // The worker may die before rename(), leaving this partial
                // inode at the temporary path. Journal it even on overwrite.
                $this->journal->recordFile($relativePath . '.mudrava-tmp', (int) $opened['dev'], (int) $opened['ino']);
            } catch (\Throwable $e) {
                fclose($handle);
                if (!$append) {
                    @unlink($temp);
                }
                throw $e;
            }
        }
        clearstatcache(true, $full);
        $destination = @lstat($full);
        $destinationHandle = null;
        if ($destination !== false) {
            if (($destination['mode'] & 0170000) !== 0100000) {
                fclose($handle);
                throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: destination is not a regular file');
            }
            // Keep the original inode alive until publication. Otherwise a
            // deleted destination could be replaced using the same inode.
            $destinationHandle = @fopen($full, 'rb');
            $openedDestination = $destinationHandle === false ? false : fstat($destinationHandle);
            if (
                $destinationHandle === false || $openedDestination === false
                || $openedDestination['dev'] !== $destination['dev']
                || $openedDestination['ino'] !== $destination['ino']
            ) {
                if (is_resource($destinationHandle)) {
                    fclose($destinationHandle);
                }
                fclose($handle);
                throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: destination changed while opening');
            }
        }
        $this->destinationPath = $full;
        $this->destinationHandle = $destinationHandle;
        $this->destinationIdentity = $destination === false ? null : [
            'dev' => (int) $destination['dev'],
            'ino' => (int) $destination['ino'],
            'mode' => (int) ($destination['mode'] & 0170000),
        ];
        $this->journalCreatedDestination = $destination === false;
        $this->tempPath = $temp;
        $this->tempIdentity = ['dev' => $opened['dev'], 'ino' => $opened['ino']];
        if ($append) {
            fseek($handle, 0, SEEK_END);
        } else {
            if (!ftruncate($handle, 0)) {
                fclose($handle);
                if ($this->destinationHandle !== null) {
                    fclose($this->destinationHandle);
                    $this->destinationHandle = null;
                }
                throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: cannot truncate ' . $relativePath);
            }
            rewind($handle);
        }
        $this->handle = $handle;
        $this->bytes = $append ? (int) $opened['size'] : 0;
    }

    public function appendChunk(string $bytes): void
    {
        if ($this->handle === null) {
            throw new \LogicException('No file open.');
        }
        $handle = $this->handle;
        $this->assertOpenTempSafe();
        $total = strlen($bytes);
        $written = 0;
        while ($written < $total) {
            $n = fwrite($handle, substr($bytes, $written));
            if ($n === false || $n === 0) {
                throw new \RuntimeException('MUDRAVA_DISK_FULL: write failed');
            }
            $written += $n;
        }
        $this->bytes += $total;
    }

    public function suspendFile(): void
    {
        if ($this->handle === null) {
            throw new \LogicException('No file open.');
        }
        if (!fflush($this->handle)) {
            throw new \RuntimeException('MUDRAVA_DISK_FULL: flush failed');
        }
        fclose($this->handle);
        $this->handle = null;
        if ($this->destinationHandle !== null) {
            fclose($this->destinationHandle);
            $this->destinationHandle = null;
        }
    }

    public function truncateTo(int $bytes): void
    {
        if ($this->handle === null || $bytes < 0 || $bytes > $this->bytes) {
            throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: invalid partial file length');
        }
        $handle = $this->handle;
        $this->assertOpenTempSafe();
        if (!ftruncate($handle, $bytes) || fseek($handle, $bytes, SEEK_SET) !== 0) {
            throw new \RuntimeException('MUDRAVA_DISK_FULL: partial file truncate failed');
        }
        $this->bytes = $bytes;
    }

    public function endFile(string $relativePath, int $mtime): void
    {
        if ($this->tempPath === null) {
            return;
        }
        if ($this->handle === null) {
            throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: temporary file is not open');
        }
        $handle = $this->handle;
        $temp = $this->tempPath;
        try {
            if (!fflush($handle)) {
                throw new \RuntimeException('MUDRAVA_DISK_FULL: flush failed');
            }
            $this->assertOpenTempSafe();
            $full = PathGuard::resolveDestinationInside($this->root, $relativePath);
            if ($full !== $this->destinationPath) {
                throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: destination path changed before publication');
            }
            clearstatcache(true, $temp);
            $named = @lstat($temp);
            if (
                $named === false || $this->tempIdentity === null
                || ($named['mode'] & 0170000) !== 0100000
                || $named['nlink'] !== 1
                || $named['dev'] !== $this->tempIdentity['dev']
                || $named['ino'] !== $this->tempIdentity['ino']
            ) {
                throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: temporary path changed before publication');
            }
            if ($this->journalCreatedDestination) {
                // link() creates the destination only if it is still absent.
                // It avoids rename() overwriting a file that appeared after
                // beginFile() and keeps the inode alive while this handle is
                // open, so it cannot be recycled during publication.
                if (!@link($temp, $full)) {
                    throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: cannot publish new destination');
                }
                if (!@unlink($temp)) {
                    throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: cannot remove temporary link');
                }
            } else {
                clearstatcache(true, $full);
                $destination = @lstat($full);
                if (
                    $destination === false || $this->destinationIdentity === null
                    || $this->destinationHandle === null
                    || $destination['dev'] !== $this->destinationIdentity['dev']
                    || $destination['ino'] !== $this->destinationIdentity['ino']
                    || ($destination['mode'] & 0170000) !== $this->destinationIdentity['mode']
                ) {
                    throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: destination changed before publication');
                }
                // The check detects a replacement made during preparation.
                // POSIX rename has no portable compare-and-swap guarantee;
                // external writes must still be quiesced during restore.
                if (!@rename($temp, $full)) {
                    throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: rename failed for ' . $relativePath);
                }
            }
            if ($mtime > 0) {
                @touch($full, $mtime);
            }
            if ($this->journal !== null && $this->journalCreatedDestination && $this->tempIdentity !== null) {
                $this->journal->recordPublishedFile(
                    $relativePath,
                    $full,
                    (int) $this->tempIdentity['dev'],
                    (int) $this->tempIdentity['ino']
                );
            }
            $this->tempPath = null;
            $this->tempIdentity = null;
            $this->destinationPath = null;
            $this->destinationIdentity = null;
            $this->journalCreatedDestination = false;
        } finally {
            fclose($handle);
            $this->handle = null;
            if ($this->destinationHandle !== null) {
                fclose($this->destinationHandle);
                $this->destinationHandle = null;
            }
        }
    }

    public function makeSymlink(string $relativePath, string $target): void
    {
        if (!PathGuard::symlinkTargetSafe($this->root, $target)) {
            throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: unsafe symlink target');
        }
        $full = PathGuard::resolveDestinationInside($this->root, $relativePath);
        $dir = dirname($full);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: cannot create ' . $dir);
        }
        clearstatcache(true, $full);
        $newDestination = @lstat($full) === false;
        $temp = $full . '.mudrava-tmp';
        clearstatcache(true, $temp);
        $existing = @lstat($temp);
        if ($existing !== false) {
            $kind = (int) $existing['mode'] & 0170000;
            if ((int) $existing['nlink'] !== 1 || ($kind !== 0100000 && $kind !== 0120000)) {
                throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: temporary path is unsafe');
            }
            if (!@unlink($temp)) {
                throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: cannot replace temporary symlink');
            }
        }
        if (!@symlink($target, $temp)) {
            throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: symlink failed for ' . $relativePath);
        }
        $named = @lstat($temp);
        if ($named === false || ($named['mode'] & 0170000) !== 0120000) {
            @unlink($temp);
            throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: temporary symlink changed');
        }
        if ($this->journal !== null) {
            try {
                $this->journal->recordFile($relativePath . '.mudrava-tmp', (int) $named['dev'], (int) $named['ino']);
            } catch (\Throwable $e) {
                @unlink($temp);
                throw $e;
            }
        }
        clearstatcache(true, $temp);
        $ready = @lstat($temp);
        if (
            $ready === false || ($ready['mode'] & 0170000) !== 0120000
            || $ready['nlink'] !== 1
            || $ready['dev'] !== $named['dev'] || $ready['ino'] !== $named['ino']
            || @readlink($temp) !== $target
        ) {
            throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: temporary symlink changed before publication');
        }
        if ($newDestination) {
            // symlink() fails atomically when the destination appeared during
            // preparation; rename() would silently overwrite it.
            if (!@symlink($target, $full)) {
                throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: cannot publish new symlink');
            }
            if ($this->journal !== null) {
                clearstatcache(true, $full);
                $published = @lstat($full);
                if ($published === false) {
                    throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: published symlink missing');
                }
                $this->journal->recordPublishedFile(
                    $relativePath,
                    $full,
                    (int) $published['dev'],
                    (int) $published['ino']
                );
            }
            if (!@unlink($temp)) {
                throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: cannot remove temporary symlink');
            }
        } elseif (!@rename($temp, $full)) {
            throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: symlink rename failed for ' . $relativePath);
        }
    }

    public function root(): string
    {
        return $this->root;
    }

    public function bytesWritten(): int
    {
        return $this->bytes;
    }

    private function assertOpenTempSafe(): void
    {
        if ($this->handle === null || $this->tempPath === null || $this->tempIdentity === null) {
            throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: temporary file is not open');
        }
        clearstatcache(true, $this->tempPath);
        $opened = fstat($this->handle);
        $named = @lstat($this->tempPath);
        if (
            $opened === false || $named === false
            || ($opened['mode'] & 0170000) !== 0100000
            || ($named['mode'] & 0170000) !== 0100000
            || $opened['nlink'] !== 1 || $named['nlink'] !== 1
            || $opened['dev'] !== $this->tempIdentity['dev']
            || $opened['ino'] !== $this->tempIdentity['ino']
            || $named['dev'] !== $this->tempIdentity['dev']
            || $named['ino'] !== $this->tempIdentity['ino']
        ) {
            throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: temporary path changed');
        }
    }
}
