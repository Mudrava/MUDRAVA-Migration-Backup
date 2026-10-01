<?php

/**
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Archive\Manifest;
use PHPUnit\Framework\TestCase;

final class ManifestTest extends TestCase
{
    public function testTypedAccessorsReturnStoredValues(): void
    {
        $data = [
            'root_hash' => str_repeat('a', 64),
            'frame_count' => '7',
            'file_count' => '3',
            'row_count' => 11,
            'file_bytes' => 4096,
        ];
        $manifest = new Manifest($data);

        $this->assertSame($data, $manifest->toArray());
        $this->assertSame(str_repeat('a', 64), $manifest->rootHash());
        $this->assertSame(7, $manifest->frameCount());
        $this->assertSame(3, $manifest->fileCount());
        $this->assertSame(11, $manifest->rowCount());
        $this->assertSame('4096', $manifest->fileBytes());
    }

    public function testTypedAccessorsHaveStableDefaults(): void
    {
        $manifest = new Manifest([]);

        $this->assertSame('', $manifest->rootHash());
        $this->assertSame(0, $manifest->frameCount());
        $this->assertSame(0, $manifest->fileCount());
        $this->assertSame(0, $manifest->rowCount());
        $this->assertSame('0', $manifest->fileBytes());
    }
}
