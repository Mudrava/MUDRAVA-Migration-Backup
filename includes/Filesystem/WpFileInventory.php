<?php

/**
 * FileInventory over the WordPress filesystem. Walks ABSPATH in sorted
 * order, excludes volatile/protected directories, and yields symlinks as
 * metadata-only entries.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Filesystem;

use Mudrava\Migration\Migration\FileInventory;
use Mudrava\Migration\Integration\ProtectedDirectories;
use Mudrava\Migration\Support\Paths;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Protected binary archives require seekable native streams and atomic file operations.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are reduced to safe codes at the REST boundary.

final class WpFileInventory implements FileInventory
{
    /** Directories never included in an archive (relative to ABSPATH). */
    private const EXCLUDE_DIRS = [
        'wp-content/mudrava-backups',
        'wp-content/upgrade',
        'wp-content/cache',
        'wp-content/uploads/tmp',
        'node_modules',
        '.git',
        '.svn',
        '.hg',
    ];

    /**
     * Individual files never included in an archive. wp-config.php holds
     * environment-specific secrets (database credentials, salts, keys) that
     * belong to the host, not the content: restoring a source site's config
     * onto a target would repoint it at the wrong database and brick the
     * site. The operator keeps the destination's own config.
     *
     * @var list<string>
     */
    private const EXCLUDE_FILES = [
        'wp-config.php',
    ];

    /** @var string */
    private $root;

    /** @var list<string> */
    private $excludeDirs;

    /**
     * @param list<string> $extraExclude additional relative dirs to skip
     */
    public function __construct(?string $root = null, array $extraExclude = [])
    {
        $this->root = rtrim($root ?: ABSPATH, '/');
        $this->excludeDirs = self::defaultExcludes($this->root);
        foreach ($extraExclude as $dir) {
            $this->excludeDirs[] = trim($dir, '/');
        }
    }

    /**
     * The exclusion set every export walk starts from: volatile dirs,
     * the storage folder, and the currently running plugin. Importing an old
     * copy of this plugin over itself would replace code during the restore.
     * Public so the admin inventory measures exactly what travels.
     *
     * @return list<string>
     */
    public static function defaultExcludes(?string $root = null): array
    {
        $root = rtrim($root ?: ABSPATH, '/');
        $paths = new Paths();
        $storage = $paths->storageRelative();
        $dirs = self::EXCLUDE_DIRS;
        if ($storage !== '' && !in_array($storage, $dirs, true)) {
            $dirs[] = $storage;
        }
        foreach (ProtectedDirectories::relativeTo($root) as $dir) {
            $dirs[] = $dir;
        }
        return $dirs;
    }

    /**
     * Whether a relative path is excluded by a dir set (prefix match) or
     * is an exact-match root file. Shared by the export walk and the
     * admin inventory so the two can never drift apart.
     *
     * @param list<string> $excludeDirs
     */
    public static function pathExcluded(string $rel, array $excludeDirs): bool
    {
        foreach ($excludeDirs as $dir) {
            if ($dir === '') {
                continue;
            }
            if ($rel === $dir || strpos($rel, $dir . '/') === 0) {
                return true;
            }
        }
        // Exact-match files (root-level only): wp-config.php is host config,
        // never content, and must survive a restore untouched.
        return in_array($rel, self::EXCLUDE_FILES, true);
    }

    public function iterate(): \Generator
    {
        foreach ($this->walk('') as $entry) {
            yield $entry;
        }
    }

    /** Scan only one directory at a time; never materialize the whole site. */
    private function walk(string $rel): \Generator
    {
        $abs = $rel === '' ? $this->root : $this->root . '/' . $rel;
        $names = @scandir($abs, SCANDIR_SORT_ASCENDING);
        if (!is_array($names)) {
            throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: cannot list ' . ($rel === '' ? 'site root' : $rel));
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $childRel = $rel === '' ? $name : $rel . '/' . $name;
            if ($this->isExcluded($childRel)) {
                continue;
            }
            $childAbs = $this->root . '/' . $childRel;
            if (is_link($childAbs)) {
                $target = @readlink($childAbs);
                if ($target === false) {
                    throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: cannot read link ' . $childRel);
                }
                yield [
                    'path' => $childRel,
                    'size' => 0,
                    'mtime' => 0,
                    'mode' => 0777 & (int) (@fileperms($childAbs) ?: 0777),
                    'type' => 'symlink',
                    'target' => $target,
                ];
            } elseif (is_dir($childAbs)) {
                foreach ($this->walk($childRel) as $entry) {
                    yield $entry;
                }
            } elseif (is_file($childAbs)) {
                yield [
                    'path' => $childRel,
                    'size' => (int) filesize($childAbs),
                    'mtime' => (int) filemtime($childAbs),
                    'mode' => 0777 & (int) fileperms($childAbs),
                    'type' => 'file',
                    'target' => null,
                ];
            } else {
                throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: unsupported filesystem entry ' . $childRel);
            }
        }
    }

    private function isExcluded(string $rel): bool
    {
        return self::pathExcluded($rel, $this->excludeDirs);
    }

    public function open(string $relativePath)
    {
        $full = PathGuard::resolveInside($this->root, $relativePath);
        $handle = @fopen($full, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: cannot read ' . $relativePath);
        }
        return $handle;
    }
}
