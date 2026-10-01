<?php

/**
 * Archive traversal defense (spec §13). The ONLY path mapper allowed on the
 * restore path. Rejects everything that could escape the destination root.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Filesystem;

final class PathGuard
{
    /**
     * Normalize + validate an archive-relative path.
     * Returns the safe relative path or throws MUDRAVA_PATH_UNSAFE.
     */
    public static function normalize(string $path): string
    {
        if ($path === '' || strpos($path, "\0") !== false) {
            throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: empty or null byte in path');
        }
        // Windows drive absolute (C:\ or C:/)
        if (preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1) {
            throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: windows absolute path');
        }
        if ($path[0] === '/' || $path[0] === '\\') {
            throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: absolute path');
        }
        // Backslashes are not valid POSIX separators in this format.
        if (strpos($path, '\\') !== false) {
            throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: backslash in path');
        }

        $segments = explode('/', $path);
        $out = [];
        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: parent traversal');
            }
            $out[] = $seg;
        }
        if ($out === []) {
            throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: empty after normalization');
        }
        return implode('/', $out);
    }

    /**
     * Resolve a safe relative path against a destination root and guarantee
     * the result stays inside the root (realpath-aware for existing parents).
     */
    public static function resolveInside(string $root, string $relative): string
    {
        $safe = self::normalize($relative);
        $rootReal = realpath($root);
        if ($rootReal === false) {
            throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: destination root missing');
        }
        $candidate = $rootReal . '/' . $safe;
        // Check the deepest existing ancestor for containment.
        $probe = $candidate;
        $suffix = '';
        while (!file_exists($probe)) {
            $base = basename($probe);
            $probe = dirname($probe);
            $suffix = $suffix === '' ? $base : $base . '/' . $suffix;
            if ($probe === $rootReal || $probe === '/' || $probe === '.') {
                break;
            }
        }
        $probeReal = realpath($probe);
        if ($probeReal === false) {
            throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: unresolvable path');
        }
        $final = $suffix === '' ? $probeReal : $probeReal . '/' . $suffix;
        if (strpos($final, $rootReal . '/') !== 0 && $final !== $rootReal) {
            throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: escapes destination root');
        }
        return $final;
    }

    /**
     * Resolve the parent of a write destination while preserving the final
     * path component. Following an existing final symlink would redirect a
     * restore write into the symlink target instead of replacing the link.
     */
    public static function resolveDestinationInside(string $root, string $relative): string
    {
        $safe = self::normalize($relative);
        $parent = dirname($safe);
        $rootReal = realpath($root);
        if ($rootReal === false) {
            throw new \RuntimeException('MUDRAVA_PATH_UNSAFE: destination root missing');
        }
        $resolvedParent = $parent === '.' ? $rootReal : self::resolveInside($rootReal, $parent);
        return rtrim($resolvedParent, '/') . '/' . basename($safe);
    }

    /**
     * Symlink policy: allowed only when the resolved target stays inside root.
     */
    public static function symlinkTargetSafe(string $root, string $target): bool
    {
        if (strpos($target, "\0") !== false) {
            return false;
        }
        if ($target === '' || $target[0] === '/' || preg_match('#^[A-Za-z]:[/\\\\]#', $target) === 1) {
            return false; // absolute targets are never auto-safe
        }
        try {
            self::normalize($target);
        } catch (\RuntimeException $e) {
            return false;
        }
        return true;
    }
}
