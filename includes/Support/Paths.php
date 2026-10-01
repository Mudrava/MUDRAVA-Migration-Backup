<?php

/**
 * Private storage location for archives. A .htaccess file cannot protect
 * static archives on nginx, so backups must live outside the web root.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Support;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Protected binary archives require seekable native streams and atomic file operations.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are reduced to safe codes at the REST boundary.

final class Paths
{
    public const STORAGE_DIRNAME = 'mudrava-backups';

    /** The pre-release location was public on servers that ignore .htaccess. */
    public function legacyPublicArchiveCount(): int
    {
        $legacy = rtrim((string) WP_CONTENT_DIR, '/') . '/' . self::STORAGE_DIRNAME;
        if (!is_dir($legacy)) {
            return 0;
        }
        $count = 0;
        foreach ((array) glob($legacy . '/*.mudrava*') as $path) {
            if (is_string($path) && is_file($path)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Move pre-release archives out of the web root using same-filesystem
     * rename only. Never copy gigabytes during a PHP request, overwrite a
     * private archive, or follow a link supplied in the public directory.
     * Remaining files are reported by legacyPublicArchiveCount().
     */
    public function migrateLegacyArchives(): int
    {
        $legacy = rtrim((string) WP_CONTENT_DIR, '/') . '/' . self::STORAGE_DIRNAME;
        if (!is_dir($legacy) || is_link($legacy)) {
            return 0;
        }
        $private = $this->ensureStorage();
        $sourceStat = @stat($legacy);
        $targetStat = @stat($private);
        if ($sourceStat === false || $targetStat === false || $sourceStat['dev'] !== $targetStat['dev']) {
            return 0;
        }
        $handle = @opendir($legacy);
        if ($handle === false) {
            return 0;
        }
        $moved = 0;
        while (($name = readdir($handle)) !== false) {
            if (preg_match('/^[A-Za-z0-9_-]+\.mudrava(?:\.part[0-9]{4,})?$/', $name) !== 1) {
                continue;
            }
            $from = $legacy . '/' . $name;
            $to = $private . '/' . $name;
            clearstatcache(true, $from);
            $source = @lstat($from);
            if (
                $source === false || ((int) $source['mode'] & 0170000) !== 0100000
                || (int) $source['nlink'] !== 1
                || file_exists($to) || is_link($to)
            ) {
                continue;
            }
            // The destination is ensureStorage() outside the web root. Plugin
            // Check traces the *source* WP_CONTENT_DIR and mistakes it for
            // a write inside the plugin directory.
            // phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Destination is validated private storage.
            if (@rename($from, $to)) {
                @chmod($to, 0600);
                $moved++;
            }
        }
        closedir($handle);
        return $moved;
    }

    public function storageDir(): string
    {
        if (defined('MUDRAVA_MB_STORAGE_DIR')) {
            return rtrim((string) MUDRAVA_MB_STORAGE_DIR, '/');
        }
        $root = rtrim((string) ABSPATH, '/');
        $parent = dirname($root);
        $site = substr(hash('sha256', $root . '|' . (defined('DB_NAME') ? DB_NAME : '')), 0, 16);
        $suffix = '/' . self::STORAGE_DIRNAME . '-' . $site;
        return self::chooseDefaultStorageDir(
            rtrim($parent, '/') . $suffix,
            rtrim(sys_get_temp_dir(), '/') . $suffix,
            is_writable($parent)
        );
    }

    /**
     * Keep the chosen directory stable across web and CLI users. A root CLI
     * process may be able to write beside WordPress when the web user cannot.
     * Existing archives always take precedence over a fresh permission test.
     */
    public static function chooseDefaultStorageDir(string $parentDir, string $temporaryDir, bool $parentWritable): string
    {
        if (is_link($parentDir) || is_link($temporaryDir)) {
            throw new \RuntimeException('MUDRAVA_STORAGE_UNSAFE: private storage path is a symlink');
        }
        if ($parentDir === $temporaryDir) {
            return $parentDir;
        }
        $parentExists = is_dir($parentDir);
        $temporaryExists = is_dir($temporaryDir);
        if ($parentExists && $temporaryExists) {
            $parentHasState = self::directoryHasState($parentDir);
            $temporaryHasState = self::directoryHasState($temporaryDir);
            if ($parentHasState && $temporaryHasState) {
                throw new \RuntimeException(
                    'MUDRAVA_STORAGE_UNSAFE: two active backup directories; set MUDRAVA_MB_STORAGE_DIR'
                );
            }
            return $temporaryHasState ? $temporaryDir : $parentDir;
        }
        if ($parentExists) {
            return $parentDir;
        }
        if ($temporaryExists) {
            return $temporaryDir;
        }
        return $parentWritable ? $parentDir : $temporaryDir;
    }

    private static function directoryHasState(string $dir): bool
    {
        $handle = @opendir($dir);
        if ($handle === false) {
            throw new \RuntimeException('MUDRAVA_STORAGE_UNSAFE: cannot inspect private storage directory');
        }
        try {
            while (($name = readdir($handle)) !== false) {
                if (!in_array($name, ['.', '..', '.htaccess', 'index.php'], true)) {
                    return true;
                }
            }
            return false;
        } finally {
            closedir($handle);
        }
    }

    /**
     * Ensure the storage directory exists and is web-protected.
     * Returns the absolute path.
     */
    public function ensureStorage(): string
    {
        $dir = $this->storageDir();
        if ($dir === '' || $dir[0] !== '/') {
            throw new \RuntimeException('MUDRAVA_STORAGE_UNSAFE: storage path must be absolute');
        }
        if (is_link($dir)) {
            throw new \RuntimeException('MUDRAVA_STORAGE_UNSAFE: storage directory is a symlink');
        }
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: cannot create storage directory');
        }
        $real = realpath($dir);
        $root = realpath(ABSPATH);
        $content = realpath(WP_CONTENT_DIR);
        $rootPath = rtrim((string) ABSPATH, '/');
        $contentPath = rtrim((string) WP_CONTENT_DIR, '/');
        if (
            $real === false || $root === false
            || $real === $root || strpos($real, $root . '/') === 0
            || ($content !== false && ($real === $content || strpos($real, $content . '/') === 0))
            || $dir === $rootPath || strpos($dir, $rootPath . '/') === 0
            || $dir === $contentPath || strpos($dir, $contentPath . '/') === 0
        ) {
            throw new \RuntimeException('MUDRAVA_STORAGE_UNSAFE: backups must be outside the web root');
        }
        @chmod($dir, 0700);
        clearstatcache(true, $dir);
        if ((fileperms($dir) & 0077) !== 0) {
            throw new \RuntimeException('MUDRAVA_STORAGE_UNSAFE: storage permissions are too broad');
        }
        // A directory can exist yet be unwritable (e.g. created by a
        // root-owned wp-cli activation while PHP runs as www-data). Fail
        // early with an actionable code instead of mid-job on first write.
        if (!is_writable($dir)) {
            throw new \RuntimeException('MUDRAVA_STORAGE_NOT_WRITABLE: ' . $dir);
        }
        // Defence in depth for hosts that map an unexpected parent URL.
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
        }
        $index = $dir . '/index.php';
        if (!is_file($index)) {
            @file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
        return $dir;
    }

    /** Upload staging area for browser chunked uploads. */
    public function uploadsDir(): string
    {
        $dir = $this->ensureStorage() . '/uploads';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: cannot create uploads');
        }
        return $dir;
    }

    /** Relative path of storage from ABSPATH (normally empty). */
    public function storageRelative(): string
    {
        $root = trailingslashit(ABSPATH);
        $storage = $this->storageDir();
        if (strpos($storage . '/', $root) === 0) {
            return substr($storage, strlen($root));
        }
        return '';
    }
}
