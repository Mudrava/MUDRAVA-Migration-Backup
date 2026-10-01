<?php

/**
 * Browser chunked upload: the browser slices the selected .mudrava file
 * into fixed chunks; the server assembles one chunk per finalize request.
 * Memory and request time stay bounded regardless of archive size.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Http;

use Mudrava\Migration\Support\Paths;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Protected binary archives require seekable native streams and atomic file operations.

final class ChunkUpload
{
    // Must match the client-side ceiling in Plugin::uploadChunkBytes():
    // honest chunk = up to 16 MiB on healthy hosts. An 8 MiB cap here
    // silently rejected every chunk a healthy host legitimately produced.
    private const MAX_CHUNK_BYTES = 16 * 1048576;

    /**
     * Accept one chunk of one part of an upload. A split archive arrives as
     * several browser-selected files (X.mudrava, X.mudrava.part0002, ...);
     * each is sliced into chunks and tagged with its 1-based part number.
     * Once every chunk has arrived, finalize() assembles the split set.
     *
     * @return array{received:int,total:int,done:bool,archive_id?:string}|\WP_Error
     */
    public static function put(string $uploadId, int $part, int $parts, int $index, int $total, string $tmpSource, int $uploadError = UPLOAD_ERR_OK)
    {
        if (!preg_match('/^[a-z0-9\-]{6,64}$/', $uploadId)) {
            return new \WP_Error('MUDRAVA_UPLOAD_ID_INVALID', 'invalid upload id');
        }
        if ($parts < 1 || $parts > 4096 || $part < 1 || $part > $parts) {
            return new \WP_Error('MUDRAVA_UPLOAD_PART_RANGE', 'part index out of range');
        }
        if ($total < 1 || $total > 65536 || $index < 0 || $index >= $total) {
            return new \WP_Error('MUDRAVA_UPLOAD_CHUNK_RANGE', 'chunk index out of range');
        }
        if ($uploadError !== UPLOAD_ERR_OK || !is_file($tmpSource)) {
            return new \WP_Error('MUDRAVA_UPLOAD_CHUNK_MISSING', 'chunk missing');
        }
        if ((int) filesize($tmpSource) > self::MAX_CHUNK_BYTES) {
            return new \WP_Error('MUDRAVA_UPLOAD_CHUNK_TOO_LARGE', 'chunk exceeds limit');
        }

        $paths = new Paths();
        $dir = self::stagingDir($paths, $uploadId, true);
        if ($dir instanceof \WP_Error) {
            return $dir;
        }
        $lockPath = $dir . '/' . $uploadId . '.lock';
        $lock = @fopen($lockPath, 'c+');
        if ($lock === false) {
            return new \WP_Error('MUDRAVA_PERMISSION_DENIED', 'cannot lock upload');
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            return new \WP_Error('MUDRAVA_PERMISSION_DENIED', 'cannot lock upload');
        }
        try {
            @touch($lockPath);
            $donePath = $dir . '/' . $uploadId . '.done.json';
            if (is_file($donePath)) {
                $done = json_decode((string) @file_get_contents($donePath), true);
                if (!is_array($done) || (int) ($done['parts'] ?? 0) !== $parts) {
                    return new \WP_Error('MUDRAVA_UPLOAD_PART_RANGE', 'completed upload id reused');
                }
                for ($p = 1; $p <= $parts; $p++) {
                    $published = $paths->storageDir() . '/' . $uploadId . '.mudrava'
                        . ($p === 1 ? '' : sprintf('.part%04d', $p));
                    if (!is_file($published)) {
                        return new \WP_Error('MUDRAVA_UPLOAD_CHUNK_MISSING', 'published part missing');
                    }
                }
                return [
                    'received' => (int) ($done['total'] ?? 0),
                    'total' => (int) ($done['total'] ?? 0),
                    'done' => true,
                    'archive_id' => $uploadId,
                ];
            }
            $staging = $dir . '/' . $uploadId . '.p' . sprintf('%04d', $part) . '.part' . sprintf('%06d', $index);

            // Remember how many chunks each part promises, so completion is
            // provable without trusting a single request's counters.
            $metaPath = $dir . '/' . $uploadId . '.meta.json';
            $meta = [];
            if (is_file($metaPath)) {
                $decoded = json_decode((string) @file_get_contents($metaPath), true);
                if (!is_array($decoded)) {
                    return new \WP_Error('MUDRAVA_UPLOAD_STATE_INVALID', 'upload state damaged');
                }
                $meta = $decoded;
            }
            if (!empty($meta['assembling'])) {
                return new \WP_Error('MUDRAVA_UPLOAD_ASSEMBLING', 'upload is being assembled');
            }
            if (isset($meta['parts']) && (int) $meta['parts'] !== $parts) {
                return new \WP_Error('MUDRAVA_UPLOAD_PART_RANGE', 'part count changed');
            }
            if (isset($meta['expected'][$part]) && (int) $meta['expected'][$part] !== $total) {
                return new \WP_Error('MUDRAVA_UPLOAD_CHUNK_RANGE', 'chunk count changed');
            }
            $receivedInPart = (int) ($meta['received'][$part] ?? 0);
            if ($index > $receivedInPart) {
                return new \WP_Error('MUDRAVA_UPLOAD_CHUNK_RANGE', 'chunk arrived out of order');
            }
            if ($index < $receivedInPart && !is_file($staging)) {
                return new \WP_Error('MUDRAVA_UPLOAD_STATE_INVALID', 'previous chunk missing');
            }
            // Older uploads may not have aggregate counters. Derive them
            // once, then update in O(1) for each new chunk.
            if (!isset($meta['received_total'])) {
                $meta['received_total'] = array_sum((array) ($meta['received'] ?? []));
            }
            if (!isset($meta['expected_total'])) {
                $meta['expected_total'] = array_sum((array) ($meta['expected'] ?? []));
            }
            $newPart = !isset($meta['expected'][$part]);
            // Idempotent per chunk: re-sending the same index replaces it. The
            // lock protects metadata and finalization from concurrent requests.
            $stored = self::receiveChunk($tmpSource, $dir, $staging);
            if ($stored instanceof \WP_Error) {
                return $stored;
            }
            $meta['parts'] = $parts;
            $meta['expected'][$part] = $total;
            if ($newPart) {
                $meta['expected_total'] += $total;
            }
            if ($index === $receivedInPart) {
                $meta['received'][$part] = $receivedInPart + 1;
                $meta['received_total']++;
            }
            if (!self::saveState($dir, $metaPath, $meta)) {
                return new \WP_Error('MUDRAVA_PERMISSION_DENIED', 'cannot save upload state');
            }

            return ['received' => (int) $meta['received_total'],
                'total' => (int) $meta['expected_total'], 'done' => false];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Use WordPress upload handling without placing archive bytes in public uploads.
     * REST authentication and administrator capability checks replace the HTML form
     * action check. Arbitrary binary slices have no independently detectable MIME
     * type; the assembled archive is validated before inspection or restoration.
     *
     * @return true|\WP_Error
     */
    private static function receiveChunk(string $source, string $dir, string $staging)
    {
        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $privateDirectory = static function (array $uploads) use ($dir): array {
            $uploads['path'] = $dir;
            $uploads['basedir'] = $dir;
            $uploads['subdir'] = '';
            $uploads['url'] = '';
            $uploads['baseurl'] = '';
            $uploads['error'] = false;
            return $uploads;
        };
        // Ignore the browser filename. A fixed inert extension and private directory
        // prevent executable uploads, including when a chunk contains PHP source.
        $file = [
            'name' => 'chunk.mudrava',
            'type' => 'application/octet-stream',
            'tmp_name' => $source,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($source),
        ];
        add_filter('upload_dir', $privateDirectory, PHP_INT_MAX);
        try {
            $uploaded = wp_handle_upload($file, ['test_form' => false, 'test_type' => false]);
        } finally {
            remove_filter('upload_dir', $privateDirectory, PHP_INT_MAX);
        }
        if (isset($uploaded['error']) || !isset($uploaded['file'])) {
            return new \WP_Error('MUDRAVA_UPLOAD_CHUNK_REJECTED', 'WordPress rejected the upload');
        }
        $received = $uploaded['file'];
        if (dirname($received) !== $dir || is_link($received) || !is_file($received)) {
            return new \WP_Error('MUDRAVA_PERMISSION_DENIED', 'unexpected upload destination');
        }
        if (!@chmod($received, 0600) || !@rename($received, $staging)) {
            @unlink($received);
            return new \WP_Error('MUDRAVA_PERMISSION_DENIED', 'cannot store chunk');
        }
        return true;
    }

    /**
     * Assemble at most one chunk, returning a durable progress checkpoint.
     *
     * @return array{received:int,total:int,assembled:int,done:bool,archive_id?:string}|\WP_Error
     */
    public static function finalize(string $uploadId)
    {
        if (!preg_match('/^[a-z0-9\-]{6,64}$/', $uploadId)) {
            return new \WP_Error('MUDRAVA_UPLOAD_ID_INVALID', 'invalid upload id');
        }
        $paths = new Paths();
        $dir = self::stagingDir($paths, $uploadId, false);
        if ($dir instanceof \WP_Error) {
            return $dir;
        }
        $lockPath = $dir . '/' . $uploadId . '.lock';
        $lock = @fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if ($lock !== false) {
                fclose($lock);
            }
            return new \WP_Error('MUDRAVA_PERMISSION_DENIED', 'cannot lock upload');
        }
        try {
            @touch($lockPath);
            $donePath = $dir . '/' . $uploadId . '.done.json';
            if (is_file($donePath)) {
                $receipt = json_decode((string) @file_get_contents($donePath), true);
                $completedParts = (int) ($receipt['parts'] ?? 0);
                if (
                    !is_array($receipt) || (int) ($receipt['total'] ?? 0) < 1
                    || $completedParts < 1 || $completedParts > 4096
                ) {
                    return new \WP_Error('MUDRAVA_UPLOAD_STATE_INVALID', 'upload receipt damaged');
                }
                for ($p = 1; $p <= $completedParts; $p++) {
                    $published = $paths->storageDir() . '/' . $uploadId . '.mudrava'
                        . ($p === 1 ? '' : sprintf('.part%04d', $p));
                    if (!is_file($published)) {
                        return new \WP_Error('MUDRAVA_UPLOAD_CHUNK_MISSING', 'published part missing');
                    }
                }
                return ['received' => (int) $receipt['total'], 'total' => (int) $receipt['total'],
                    'assembled' => (int) $receipt['total'], 'done' => true, 'archive_id' => $uploadId];
            }
            $metaPath = $dir . '/' . $uploadId . '.meta.json';
            $meta = json_decode((string) @file_get_contents($metaPath), true);
            if (!is_array($meta) || !isset($meta['parts']) || (int) $meta['parts'] < 1 || (int) $meta['parts'] > 4096) {
                return new \WP_Error('MUDRAVA_UPLOAD_STATE_INVALID', 'upload state missing or damaged');
            }
            $parts = (int) $meta['parts'];
            if (empty($meta['assembling'])) {
                $total = 0;
                for ($p = 1; $p <= $parts; $p++) {
                    $want = (int) ($meta['expected'][$p] ?? 0);
                    if ($want < 1 || $want > 65536 || (int) ($meta['received'][$p] ?? 0) !== $want) {
                        return new \WP_Error('MUDRAVA_UPLOAD_INCOMPLETE', 'upload chunks incomplete');
                    }
                    $total += $want;
                }
                $meta['assembling'] = true;
                $meta['total_chunks'] = $total;
            } else {
                $total = (int) ($meta['total_chunks'] ?? array_sum((array) ($meta['expected'] ?? [])));
                $meta['total_chunks'] = $total;
            }
            $meta['current_part'] = (int) ($meta['current_part'] ?? 1);
            $meta['assembled_total'] = (int) ($meta['assembled_total'] ?? array_sum((array) ($meta['assembled'] ?? [])));
            if (
                $total < 1 || $meta['current_part'] < 1 || $meta['current_part'] > $parts + 1
                || $meta['assembled_total'] < 0 || $meta['assembled_total'] > $total
            ) {
                return new \WP_Error('MUDRAVA_UPLOAD_STATE_INVALID', 'assembly checkpoint damaged');
            }
            if (!self::saveState($dir, $metaPath, $meta)) {
                return new \WP_Error('MUDRAVA_PERMISSION_DENIED', 'cannot save upload state');
            }
            for ($p = $meta['current_part']; $p <= $parts; $p++) {
                $want = (int) $meta['expected'][$p];
                $index = (int) ($meta['assembled'][$p] ?? 0);
                $bytes = (int) ($meta['bytes'][$p] ?? 0);
                if ($index < 0 || $index > $want || $bytes < 0) {
                    return new \WP_Error('MUDRAVA_UPLOAD_STATE_INVALID', 'assembly checkpoint damaged');
                }
                $target = $paths->storageDir() . '/' . $uploadId . '.mudrava'
                    . ($p === 1 ? '' : sprintf('.part%04d', $p));
                $tmp = $dir . '/' . $uploadId . '.p' . sprintf('%04d', $p) . '.final';
                if ($index === $want) {
                    if (!is_file($target)) {
                        if (!is_file($tmp) || !@rename($tmp, $target)) {
                            return new \WP_Error('MUDRAVA_PERMISSION_DENIED', 'cannot publish assembled part');
                        }
                    }
                    $meta['current_part'] = $p + 1;
                    if (!self::saveState($dir, $metaPath, $meta)) {
                        return new \WP_Error('MUDRAVA_PERMISSION_DENIED', 'cannot save assembly checkpoint');
                    }
                    continue;
                }
                if (is_dir($target)) {
                    return new \WP_Error('MUDRAVA_PERMISSION_DENIED', 'archive target is a directory');
                }
                if (file_exists($target)) {
                    return new \WP_Error('MUDRAVA_UPLOAD_STATE_INVALID', 'archive id already exists');
                }
                $chunk = $dir . '/' . $uploadId . '.p' . sprintf('%04d', $p)
                    . '.part' . sprintf('%06d', $index);
                clearstatcache(true, $chunk);
                $chunkStat = @lstat($chunk);
                if ($chunkStat === false) {
                    return new \WP_Error('MUDRAVA_UPLOAD_CHUNK_MISSING', 'chunk or staging file missing');
                }
                if (
                    ((int) $chunkStat['mode'] & 0170000) !== 0100000
                    || (int) $chunkStat['nlink'] !== 1
                ) {
                    return new \WP_Error('MUDRAVA_UPLOAD_STATE_INVALID', 'staged chunk is unsafe');
                }
                clearstatcache(true, $tmp);
                $previous = @lstat($tmp);
                if (
                    $previous !== false
                    && (((int) $previous['mode'] & 0170000) !== 0100000 || (int) $previous['nlink'] !== 1)
                ) {
                    return new \WP_Error('MUDRAVA_UPLOAD_STATE_INVALID', 'assembly staging file is unsafe');
                }
                $in = @fopen($chunk, 'rb');
                $out = @fopen($tmp, 'c+b');
                if ($in === false || $out === false) {
                    if ($in !== false) {
                        fclose($in);
                    }
                    if ($out !== false) {
                        fclose($out);
                    }
                    return new \WP_Error('MUDRAVA_UPLOAD_CHUNK_MISSING', 'chunk or staging file missing');
                }
                $openedChunk = fstat($in);
                $openedStage = fstat($out);
                clearstatcache(true, $tmp);
                $namedStage = @lstat($tmp);
                if (
                    $openedChunk === false || $openedStage === false || $namedStage === false
                    || ((int) $openedChunk['mode'] & 0170000) !== 0100000
                    || ((int) $openedStage['mode'] & 0170000) !== 0100000
                    || (int) $openedChunk['nlink'] !== 1 || (int) $openedStage['nlink'] !== 1
                    || (int) $namedStage['nlink'] !== 1
                    || (int) $openedChunk['dev'] !== (int) $chunkStat['dev']
                    || (int) $openedChunk['ino'] !== (int) $chunkStat['ino']
                    || (int) $openedStage['dev'] !== (int) $namedStage['dev']
                    || (int) $openedStage['ino'] !== (int) $namedStage['ino']
                    || ($previous !== false && (
                        (int) $openedStage['dev'] !== (int) $previous['dev']
                        || (int) $openedStage['ino'] !== (int) $previous['ino']
                    ))
                ) {
                    fclose($in);
                    fclose($out);
                    return new \WP_Error('MUDRAVA_UPLOAD_STATE_INVALID', 'assembly staging file changed');
                }
                $length = (int) $openedChunk['size'];
                $stagedLength = (int) $openedStage['size'];
                if ($stagedLength < $bytes || !ftruncate($out, $bytes) || fseek($out, $bytes) !== 0) {
                    fclose($in);
                    fclose($out);
                    return new \WP_Error('MUDRAVA_UPLOAD_STATE_INVALID', 'assembly checkpoint exceeds staging file');
                }
                $copied = stream_copy_to_stream($in, $out);
                fclose($in);
                if ($copied !== $length || !fflush($out)) {
                    fclose($out);
                    return new \WP_Error('MUDRAVA_DISK_FULL', 'cannot copy complete chunk');
                }
                fclose($out);
                $meta['assembled'][$p] = $index + 1;
                $meta['bytes'][$p] = $bytes + $length;
                $meta['assembled_total']++;
                if (!self::saveState($dir, $metaPath, $meta)) {
                    return new \WP_Error('MUDRAVA_PERMISSION_DENIED', 'cannot save assembly checkpoint');
                }
                @unlink($chunk);
                if ($index + 1 === $want && !@rename($tmp, $target)) {
                    return new \WP_Error('MUDRAVA_PERMISSION_DENIED', 'cannot publish assembled part');
                }
                if ($index + 1 === $want) {
                    $meta['current_part'] = $p + 1;
                    if (!self::saveState($dir, $metaPath, $meta)) {
                        return new \WP_Error('MUDRAVA_PERMISSION_DENIED', 'cannot save assembly checkpoint');
                    }
                }
                return ['received' => $total, 'total' => $total,
                    'assembled' => (int) $meta['assembled_total'], 'done' => false];
            }
            if (!self::saveState($dir, $donePath, ['parts' => $parts, 'total' => $total])) {
                return new \WP_Error('MUDRAVA_PERMISSION_DENIED', 'cannot save upload receipt');
            }
            @unlink($metaPath);
            return ['received' => $total, 'total' => $total, 'assembled' => $total,
                'done' => true, 'archive_id' => $uploadId];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Return the next contiguous chunk per part for browser resume.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public static function status(string $uploadId)
    {
        if (!preg_match('/^[a-z0-9\-]{6,64}$/', $uploadId)) {
            return new \WP_Error('MUDRAVA_UPLOAD_ID_INVALID', 'invalid upload id');
        }
        $paths = new Paths();
        $root = $paths->uploadsDir();
        if (!is_dir($root . '/' . $uploadId) && !is_file($root . '/' . $uploadId . '.lock')) {
            return ['found' => false];
        }
        $dir = self::stagingDir($paths, $uploadId, false);
        if ($dir instanceof \WP_Error) {
            return $dir;
        }
        $lock = @fopen($dir . '/' . $uploadId . '.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if ($lock !== false) {
                fclose($lock);
            }
            return new \WP_Error('MUDRAVA_PERMISSION_DENIED', 'cannot lock upload');
        }
        try {
            @touch($dir . '/' . $uploadId . '.lock');
            $receipt = $dir . '/' . $uploadId . '.done.json';
            if (is_file($receipt)) {
                $done = json_decode((string) @file_get_contents($receipt), true);
                if (
                    !is_array($done) || (int) ($done['parts'] ?? 0) < 1
                    || (int) ($done['parts'] ?? 0) > 4096
                ) {
                    return new \WP_Error('MUDRAVA_UPLOAD_STATE_INVALID', 'upload receipt damaged');
                }
                for ($p = 1; $p <= (int) $done['parts']; $p++) {
                    $published = $paths->storageDir() . '/' . $uploadId . '.mudrava'
                        . ($p === 1 ? '' : sprintf('.part%04d', $p));
                    if (!is_file($published)) {
                        return new \WP_Error('MUDRAVA_UPLOAD_CHUNK_MISSING', 'published part missing');
                    }
                }
                return ['found' => true, 'done' => true, 'archive_id' => $uploadId,
                    'parts' => (int) $done['parts']];
            }
            $metaPath = $dir . '/' . $uploadId . '.meta.json';
            if (!is_file($metaPath)) {
                return ['found' => false];
            }
            $meta = json_decode((string) @file_get_contents($metaPath), true);
            if (!is_array($meta) || (int) ($meta['parts'] ?? 0) < 1) {
                return new \WP_Error('MUDRAVA_UPLOAD_STATE_INVALID', 'upload state damaged');
            }
            return ['found' => true, 'done' => false, 'parts' => (int) $meta['parts'],
                'expected' => $meta['expected'] ?? [], 'received' => $meta['received'] ?? [],
                'assembling' => !empty($meta['assembling'])];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string,mixed> $state */
    private static function saveState(string $dir, string $path, array $state): bool
    {
        $body = json_encode($state);
        // Keep abandoned temporary states under the upload ID so stale
        // upload cleanup can remove them after a killed worker.
        $tmp = @tempnam($dir, basename($path) . '.tmp-');
        if ($body === false || $tmp === false) {
            return false;
        }
        try {
            return @file_put_contents($tmp, $body) === strlen($body)
                && @chmod($tmp, 0600)
                && @rename($tmp, $path);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** @return string|\WP_Error */
    private static function stagingDir(Paths $paths, string $uploadId, bool $create)
    {
        $root = $paths->uploadsDir();
        // Continue uploads started by versions that staged every chunk in
        // the root. New uploads use one private directory per upload ID.
        if (is_file($root . '/' . $uploadId . '.lock')) {
            return $root;
        }
        $dir = $root . '/' . $uploadId;
        if (is_link($dir) || (file_exists($dir) && !is_dir($dir))) {
            return new \WP_Error('MUDRAVA_UPLOAD_STATE_INVALID', 'staging path is not a directory');
        }
        if (!is_dir($dir) && (!$create || (!@mkdir($dir, 0700) && !is_dir($dir)))) {
            return new \WP_Error('MUDRAVA_UPLOAD_STATE_INVALID', 'upload state missing or staging unavailable');
        }
        return $dir;
    }

    /** Remove at most 512 abandoned files per invocation. */
    public static function cleanupStale(): void
    {
        $root = (new Paths())->uploadsDir();
        $budget = 512;
        foreach (new \DirectoryIterator($root) as $entry) {
            if ($entry->isDot() || $entry->isLink()) {
                continue;
            }
            $name = $entry->getFilename();
            $legacy = $entry->isFile() && substr($name, -5) === '.lock';
            $id = $legacy ? substr($name, 0, -5) : $name;
            if (preg_match('/^[a-z0-9\-]{6,64}$/', $id) !== 1) {
                continue;
            }
            if (!$legacy && !$entry->isDir()) {
                continue;
            }
            $uploadDir = $legacy ? $root : $root . '/' . $id;
            $lockPath = $uploadDir . '/' . $id . '.lock';
            if (!$legacy && !is_file($lockPath)) {
                // A worker can die between mkdir and opening the lock. Only
                // remove an old empty directory; never guess its contents.
                if ((int) $entry->getMTime() < time() - DAY_IN_SECONDS) {
                    @rmdir($uploadDir);
                }
                continue;
            }
            $budget -= self::cleanupOne($uploadDir, $lockPath, $id, $budget, $legacy);
            if ($budget <= 0) {
                break;
            }
        }
    }

    private static function cleanupOne(string $dir, string $lockPath, string $id, int $budget, bool $legacy): int
    {
        clearstatcache(true, $lockPath);
        if (!is_file($lockPath) || (int) @filemtime($lockPath) >= time() - DAY_IN_SECONDS) {
            return 0;
        }
        $lock = @fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }
            return 0;
        }
        $removed = 0;
        try {
            clearstatcache(true, $lockPath);
            if ((int) @filemtime($lockPath) >= time() - DAY_IN_SECONDS) {
                return 0;
            }
            foreach (new \DirectoryIterator($dir) as $entry) {
                if ($entry->isDot() || $entry->getPathname() === $lockPath) {
                    continue;
                }
                if ($legacy && strpos($entry->getFilename(), $id . '.') !== 0) {
                    continue;
                }
                if (!$entry->isFile() && !$entry->isLink()) {
                    continue;
                }
                if ($removed >= $budget || !@unlink($entry->getPathname())) {
                    return $removed;
                }
                $removed++;
            }
            @unlink($lockPath);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if (!$legacy) {
            @rmdir($dir);
        }
        return $removed;
    }
}
