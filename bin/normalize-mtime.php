<?php
/**
 * Normalize a distribution staging tree to one reproducible timestamp.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

if ($argc !== 3 || !is_dir($argv[1]) || preg_match('/^[0-9]+$/', $argv[2]) !== 1) {
    fwrite(STDERR, "usage: normalize-mtime.php DIR EPOCH\n");
    exit(2);
}

$root = rtrim($argv[1], '/');
$epoch = (int) $argv[2];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iterator as $entry) {
    if (!touch($entry->getPathname(), $epoch)) {
        fwrite(STDERR, 'cannot normalize timestamp: ' . $entry->getPathname() . "\n");
        exit(1);
    }
}
if (!touch($root, $epoch)) {
    fwrite(STDERR, 'cannot normalize timestamp: ' . $root . "\n");
    exit(1);
}
