<?php

/**
 * Durable list of files and tables absent before a guarded import. Entries
 * are written before publication so a killed PHP worker can still recover.
 * File inode checks prevent cleanup from deleting a later replacement.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Rollback;

use Mudrava\Migration\Filesystem\PathGuard;
use Mudrava\Migration\Support\Paths;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Private, append-only recovery journal requires inode-aware native file operations.
// phpcs:disable WordPress.DB.PreparedSQL -- Journaled table identifiers are restricted to [A-Za-z0-9_].
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Journaled table identifiers pass a strict allowlist.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions become safe REST error codes.

final class ImportJournal
{
    /** @var string */
    private $path;

    /** @var array<string,bool>|null */
    private $baselineTables;

    public function __construct(string $archiveId)
    {
        $this->path = (new Paths())->ensureStorage() . '/import-journal-' . hash('sha256', $archiveId) . '.jsonl';
    }

    /** @param list<string> $baselineTables */
    public function create(array $baselineTables = []): void
    {
        foreach ($baselineTables as $table) {
            if (!is_string($table)) {
                throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: invalid baseline');
            }
        }
        $handle = @fopen($this->path, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: cannot create journal');
        }
        try {
            if (!@chmod($this->path, 0600)) {
                throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: cannot protect journal');
            }
            $json = json_encode(['type' => 'baseline', 'tables' => $baselineTables]);
            if ($json === false || @fwrite($handle, $json . "\n") !== strlen($json) + 1 || !fflush($handle)) {
                throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: cannot save baseline');
            }
            $this->baselineTables = array_fill_keys($baselineTables, true);
        } finally {
            fclose($handle);
        }
    }

    public function recordFile(string $relativePath, int $dev, int $ino): void
    {
        $this->append([
            'type' => 'file',
            'path' => PathGuard::normalize($relativePath),
            'dev' => $dev,
            'ino' => $ino,
        ]);
    }

    /**
     * Record a published file only after its final metadata is known. The
     * sampled content and metadata prevent cleanup from trusting an inode
     * that the filesystem immediately reused for a different file.
     */
    public function recordPublishedFile(string $relativePath, string $fullPath, int $dev, int $ino): void
    {
        $relative = PathGuard::normalize($relativePath);
        clearstatcache(true, $fullPath);
        $stat = @lstat($fullPath);
        if ($stat === false || (int) $stat['dev'] !== $dev || (int) $stat['ino'] !== $ino) {
            throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: published file changed');
        }
        $sample = self::sampleFile($fullPath, $stat);
        $this->append([
            'type' => 'file',
            'path' => $relative,
            'dev' => $dev,
            'ino' => $ino,
            'size' => (int) $stat['size'],
            'mtime' => (int) $stat['mtime'],
            'ctime' => (int) $stat['ctime'],
            'sample' => $sample,
        ]);
    }

    /** @param array<int|string,int> $stat */
    private static function sampleFile(string $path, array $stat): string
    {
        $kind = $stat['mode'] & 0170000;
        if ($kind === 0120000) {
            $target = @readlink($path);
            if ($target === false) {
                throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: cannot sample symlink');
            }
            return hash('sha256', 'link:' . $target);
        }
        if ($kind !== 0100000 || (int) $stat['nlink'] !== 1) {
            throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: unsafe published file');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: cannot sample file');
        }
        try {
            $opened = fstat($handle);
            if ($opened === false || (int) $opened['dev'] !== (int) $stat['dev'] || (int) $opened['ino'] !== (int) $stat['ino']) {
                throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: sampled file changed');
            }
            $first = fread($handle, 4096);
            if ($first === false) {
                throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: cannot sample file');
            }
            $last = '';
            if ((int) $stat['size'] > 4096) {
                if (fseek($handle, max(0, (int) $stat['size'] - 4096), SEEK_SET) !== 0) {
                    throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: cannot sample file');
                }
                $last = fread($handle, 4096);
                if ($last === false) {
                    throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: cannot sample file');
                }
            }
            return hash('sha256', 'file:' . $first . ':' . $last);
        } finally {
            fclose($handle);
        }
    }

    public function recordTable(string $table): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: invalid table');
        }
        if ($this->baselineTables === null) {
            $this->loadBaseline();
        }
        if (isset($this->baselineTables[$table])) {
            return;
        }
        $this->append(['type' => 'table', 'table' => $table]);
    }

    private function loadBaseline(): void
    {
        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: baseline missing');
        }
        try {
            $line = fgets($handle);
        } finally {
            fclose($handle);
        }
        $entry = is_string($line) ? json_decode($line, true) : null;
        if (!is_array($entry) || ($entry['type'] ?? '') !== 'baseline' || !is_array($entry['tables'] ?? null)) {
            throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: invalid baseline');
        }
        foreach ($entry['tables'] as $table) {
            if (!is_string($table)) {
                throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: invalid baseline');
            }
        }
        $this->baselineTables = array_fill_keys($entry['tables'], true);
    }

    /**
     * Reapplied restore-point contents stay untouched. Remove only entries
     * the original import created and only while their file inode matches.
     *
     * @param \wpdb $wpdb
     */
    public function cleanup(string $root, $wpdb): void
    {
        $phase = 'validate';
        $offset = 0;
        do {
            $result = $this->cleanupStep($root, $wpdb, $phase, $offset);
            $phase = $result['phase'];
            $offset = $result['offset'];
        } while (!$result['done']);
    }

    /**
     * Validate before deleting, then remove at most 256 entries per request.
     * Replaying a step after a worker crash is safe: missing files and tables
     * are already gone, and inode checks protect replacement files.
     *
     * @param \wpdb $wpdb
     * @return array{phase:string,offset:int,done:bool}
     */
    public function cleanupStep(string $root, $wpdb, string $phase, int $offset, ?float $deadline = null): array
    {
        if (!in_array($phase, ['validate', 'remove'], true) || $offset < 0) {
            throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: invalid cursor');
        }
        if (!is_file($this->path)) {
            throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: journal missing');
        }
        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: cannot read journal');
        }
        try {
            $opened = fstat($handle);
            clearstatcache(true, $this->path);
            $named = @lstat($this->path);
            if (
                $opened === false || $named === false
                || ($opened['mode'] & 0170000) !== 0100000
                || ($named['mode'] & 0170000) !== 0100000
                || $opened['nlink'] !== 1 || $named['nlink'] !== 1
                || $opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino']
            ) {
                throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: unsafe journal');
            }
            if ($offset > (int) $opened['size'] || fseek($handle, $offset, SEEK_SET) !== 0) {
                throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: read failed');
            }
            $count = 0;
            $line = false;
            while (
                $count < 256 && ($count === 0 || $deadline === null || microtime(true) < $deadline)
                && ($line = fgets($handle)) !== false
            ) {
                if (substr($line, -1) !== "\n") {
                    // A torn final append cannot have published a file/table.
                    break;
                }
                $this->validateLine($line);
                if ($phase === 'remove') {
                    $entry = json_decode($line, true);
                    if ($entry['type'] === 'file') {
                        $this->cleanupFile($root, $entry);
                    } elseif ($entry['type'] === 'table') {
                        $this->cleanupTable($wpdb, $entry);
                    }
                }
                ++$count;
            }
            if (!feof($handle) && $line === false) {
                throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: read failed');
            }
            $next = ftell($handle);
            if ($next === false) {
                throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: read failed');
            }
            if (feof($handle) || $next >= (int) $opened['size']) {
                return $phase === 'validate'
                    ? ['phase' => 'remove', 'offset' => 0, 'done' => false]
                    : ['phase' => 'remove', 'offset' => $next, 'done' => true];
            }
            return ['phase' => $phase, 'offset' => $next, 'done' => false];
        } finally {
            fclose($handle);
        }
    }

    private function validateLine(string $line): void
    {
        if (substr($line, -1) !== "\n") {
            return;
        }
        $entry = json_decode($line, true);
        if (!is_array($entry) || !isset($entry['type'])) {
            throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: invalid entry');
        }
        if ($entry['type'] === 'baseline') {
            if (!is_array($entry['tables'] ?? null)) {
                throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: invalid baseline');
            }
            foreach ($entry['tables'] as $table) {
                if (!is_string($table)) {
                    throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: invalid baseline');
                }
            }
            return;
        }
        if ($entry['type'] === 'file') {
            if (
                !isset($entry['path'], $entry['dev'], $entry['ino'])
                || !is_string($entry['path'])
                || !is_int($entry['dev']) || !is_int($entry['ino'])
            ) {
                throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: invalid file entry');
            }
            PathGuard::normalize($entry['path']);
            if (
                isset($entry['sample']) && (
                    !is_string($entry['sample'])
                    || preg_match('/^[a-f0-9]{64}$/', $entry['sample']) !== 1
                    || !is_int($entry['size'] ?? null)
                    || !is_int($entry['mtime'] ?? null)
                    || !is_int($entry['ctime'] ?? null)
                )
            ) {
                throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: invalid file sample');
            }
            return;
        }
        if (
            $entry['type'] !== 'table' || !isset($entry['table'])
            || !is_string($entry['table'])
            || preg_match('/^[A-Za-z0-9_]+$/', $entry['table']) !== 1
        ) {
            throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: invalid entry');
        }
    }

    public function remove(): void
    {
        if (is_file($this->path) && !@unlink($this->path)) {
            throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: cannot remove journal');
        }
    }

    /** True when any inode (regular, link or otherwise) sits at the path. */
    public function exists(): bool
    {
        clearstatcache(true, $this->path);
        return @lstat($this->path) !== false;
    }

    /**
     * Reclaim an orphaned journal only when it provably contains nothing
     * but its validated baseline. Every destructive write is journaled
     * BEFORE it happens (the file target records the temporary inode, the
     * database target records the table before DROP), so a baseline-only
     * journal proves the owning import never mutated a file or a table.
     * A torn final append preceded any publication and is tolerated.
     *
     * Returns false when a complete mutation record exists; throws for a
     * damaged, aliased or unreadable journal. Neither case deletes
     * anything, so the caller must surface a typed actionable refusal.
     * Callers must hold the cross-request job lock.
     */
    public function removeIfPristine(): bool
    {
        clearstatcache(true, $this->path);
        $named = @lstat($this->path);
        if ($named === false || ($named['mode'] & 0170000) !== 0100000 || (int) $named['nlink'] !== 1) {
            throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: unsafe journal');
        }
        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: cannot read journal');
        }
        try {
            $opened = fstat($handle);
            clearstatcache(true, $this->path);
            $named = @lstat($this->path);
            if (
                $opened === false || $named === false
                || ($opened['mode'] & 0170000) !== 0100000
                || ($named['mode'] & 0170000) !== 0100000
                || $opened['nlink'] !== 1 || $named['nlink'] !== 1
                || $opened['dev'] !== $named['dev'] || $opened['ino'] !== $named['ino']
            ) {
                throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: unsafe journal');
            }
            $first = fgets($handle);
            if (!is_string($first) || substr($first, -1) !== "\n") {
                throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: invalid baseline');
            }
            $baseline = json_decode($first, true);
            if (
                !is_array($baseline) || ($baseline['type'] ?? '') !== 'baseline'
                || !is_array($baseline['tables'] ?? null)
            ) {
                throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: invalid baseline');
            }
            foreach ($baseline['tables'] as $table) {
                if (!is_string($table)) {
                    throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: invalid baseline');
                }
            }
            while (($line = fgets($handle)) !== false) {
                if (substr($line, -1) !== "\n") {
                    // A torn final append cannot have published a file/table.
                    break;
                }
                // A complete second line is a mutation record (or damaged):
                // validate it, then refuse. The journal may guard real
                // writes and must survive for manual recovery.
                $this->validateLine($line);
                return false;
            }
            if (!feof($handle)) {
                throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: read failed');
            }
            if (!@unlink($this->path)) {
                throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: cannot remove journal');
            }
            return true;
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string,mixed> $entry */
    private function append(array $entry): void
    {
        $json = json_encode($entry, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: cannot encode entry');
        }
        clearstatcache(true, $this->path);
        $named = @lstat($this->path);
        if ($named === false || ($named['mode'] & 0170000) !== 0100000 || $named['nlink'] !== 1) {
            throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: unsafe journal');
        }
        $handle = @fopen($this->path, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: cannot open journal');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: cannot lock journal');
            }
            $opened = fstat($handle);
            if (
                $opened === false || ($opened['mode'] & 0170000) !== 0100000
                || $opened['nlink'] !== 1 || $opened['dev'] !== $named['dev']
                || $opened['ino'] !== $named['ino']
            ) {
                throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: unsafe journal');
            }
            // A killed writer may leave a partial final line. It preceded
            // publication, so discard that tail before appending a new entry.
            $size = (int) $opened['size'];
            if ($size > 0 && fseek($handle, -1, SEEK_END) === 0 && fgetc($handle) !== "\n") {
                $cursor = $size;
                do {
                    --$cursor;
                    if (fseek($handle, $cursor, SEEK_SET) !== 0) {
                        throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: seek failed');
                    }
                    $char = fgetc($handle);
                } while ($cursor > 0 && $char !== "\n");
                if ($char !== "\n" || !ftruncate($handle, $cursor + 1)) {
                    throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: torn baseline');
                }
            }
            if (fseek($handle, 0, SEEK_END) !== 0) {
                throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: seek failed');
            }
            $line = $json . "\n";
            if (fwrite($handle, $line) !== strlen($line) || !fflush($handle)) {
                throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: write failed');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<string,mixed> $entry */
    private function cleanupFile(string $root, array $entry): void
    {
        if (!isset($entry['path'], $entry['dev'], $entry['ino']) || !is_string($entry['path'])) {
            throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: invalid file entry');
        }
        $relative = PathGuard::normalize($entry['path']);
        $parent = dirname($relative);
        $safeParent = $parent === '.' ? realpath($root) : PathGuard::resolveInside($root, $parent);
        if ($safeParent === false) {
            throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: parent missing');
        }
        $full = $safeParent . '/' . basename($relative);
        clearstatcache(true, $full);
        $current = @lstat($full);
        if ($current === false) {
            return;
        }
        if (
            (int) $current['dev'] !== (int) $entry['dev']
            || (int) $current['ino'] !== (int) $entry['ino']
        ) {
            return;
        }
        if (isset($entry['sample'])) {
            if (
                (int) $current['size'] !== $entry['size']
                || (int) $current['mtime'] !== $entry['mtime']
                || (int) $current['ctime'] !== $entry['ctime']
                || self::sampleFile($full, $current) !== $entry['sample']
            ) {
                return;
            }
            clearstatcache(true, $full);
            $after = @lstat($full);
            if (
                $after === false || (int) $after['dev'] !== (int) $entry['dev']
                || (int) $after['ino'] !== (int) $entry['ino']
                || (int) $after['size'] !== $entry['size']
                || (int) $after['mtime'] !== $entry['mtime']
                || (int) $after['ctime'] !== $entry['ctime']
            ) {
                return;
            }
        } elseif (substr($relative, -12) !== '.mudrava-tmp') {
            // A pre-upgrade journal cannot prove a final path still names the
            // imported file. Leave it for manual inspection.
            return;
        }
        $kind = $current['mode'] & 0170000;
        if (($kind !== 0100000 && $kind !== 0120000) || !@unlink($full)) {
            throw new \RuntimeException('MUDRAVA_RECOVERY_INCOMPLETE: cannot remove created file');
        }
    }

    /** @param array<string,mixed> $entry
     *  @param \wpdb $wpdb
     */
    private function cleanupTable($wpdb, array $entry): void
    {
        $table = $entry['table'] ?? null;
        if (!is_string($table) || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new \RuntimeException('MUDRAVA_RECOVERY_JOURNAL: invalid table entry');
        }
        // Schema recovery must use direct uncached SQL. This table was absent
        // in the captured baseline and its name passed the identifier guard.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            if ($wpdb->query('DROP TABLE IF EXISTS `' . $table . '`') === false) {
                throw new \RuntimeException('MUDRAVA_RECOVERY_INCOMPLETE: cannot remove created table');
            }
        } finally {
            $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery
    }
}
