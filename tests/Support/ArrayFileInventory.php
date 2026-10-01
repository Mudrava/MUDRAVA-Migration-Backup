<?php

/**
 * Deterministic in-memory FileInventory. Paths are sorted so resume-skip
 * counts are stable across runs.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Support;

use Mudrava\Migration\Migration\FileInventory;

final class ArrayFileInventory implements FileInventory
{
    /** @var array<string,string> path => content */
    private $files;

    /** @var array<string,array{mode?:int,type?:string,target?:string,size?:int}> */
    private $meta;

    /**
     * @param array<string,string> $files path => content
     * @param array<string,array{mode?:int,type?:string,target?:string,size?:int}> $meta
     */
    public function __construct(array $files, array $meta = [])
    {
        $this->files = $files;
        $this->meta = $meta;
    }

    public function iterate(): \Generator
    {
        $paths = array_keys($this->files);
        // Same order as the production depth-first walk: a directory is
        // visited before its siblings that sort after its name, so
        // "a/x.txt" precedes "a.txt" even though strcmp says the opposite.
        usort($paths, static function (string $a, string $b): int {
            $left = explode('/', $a);
            $right = explode('/', $b);
            $shared = min(count($left), count($right));
            for ($i = 0; $i < $shared; $i++) {
                $cmp = strcmp($left[$i], $right[$i]);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            return count($left) <=> count($right);
        });
        foreach ($paths as $path) {
            $m = $this->meta[$path] ?? [];
            yield [
                'path'  => $path,
                'size'  => $m['size'] ?? strlen($this->files[$path]),
                'mtime' => 1700000000,
                'mode'  => $m['mode'] ?? 0644,
                'type'  => $m['type'] ?? 'file',
                'target' => $m['target'] ?? null,
            ];
        }
    }

    public function open(string $relativePath)
    {
        if (!isset($this->files[$relativePath])) {
            throw new \RuntimeException('missing fixture file ' . $relativePath);
        }
        $h = fopen('php://memory', 'wb+');
        fwrite($h, $this->files[$relativePath]);
        rewind($h);
        return $h;
    }

    public function replaceContent(string $relativePath, string $content): void
    {
        $this->files[$relativePath] = $content;
    }

    /**
     * Simulate a file appearing in a directory the previous walk had not
     * listed yet (the A.3e scenario): the next iterate() yields it too.
     */
    public function addFile(string $relativePath, string $content): void
    {
        $this->files[$relativePath] = $content;
    }

    public function removeFile(string $relativePath): void
    {
        unset($this->files[$relativePath]);
    }
}
