<?php

/**
 * Filesystem inventory for export. Yields normalized relative paths.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Migration;

interface FileInventory
{
    /**
     * @return \Generator<array{path:string,size:int,mtime:int,mode:int,type:string,target:?string}>
     */
    public function iterate(): \Generator;

    /** @return resource readable stream for a relative path */
    public function open(string $relativePath);
}
