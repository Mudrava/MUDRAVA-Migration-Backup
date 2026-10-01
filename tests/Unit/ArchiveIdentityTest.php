<?php

/**
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */


declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Storage\SplitSetSource;
use PHPUnit\Framework\TestCase;

final class ArchiveIdentityTest extends TestCase
{
    public function testDetectsInPlaceChangeAndPartReplacement(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'mudrava-identity-');
        self::assertIsString($base);
        $part = $base . '.part0002';
        try {
            file_put_contents($base, str_repeat('a', 5000));
            file_put_contents($part, str_repeat('b', 5000));
            $initial = SplitSetSource::identity($base);
            self::assertSame($initial, SplitSetSource::identity($base));

            file_put_contents($part, str_repeat('c', 5000));
            self::assertNotSame($initial, SplitSetSource::identity($base));

            $changed = SplitSetSource::identity($base);
            $replacement = $part . '.new';
            file_put_contents($replacement, str_repeat('c', 5000));
            rename($replacement, $part);
            self::assertNotSame($changed, SplitSetSource::identity($base));
        } finally {
            @unlink($base);
            @unlink($part);
            @unlink($part . '.new');
        }
    }

    public function testRejectsSymlinkArchivePart(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'mudrava-identity-');
        self::assertIsString($base);
        $part = $base . '.part0002';
        try {
            file_put_contents($base, 'archive');
            symlink($base, $part);
            $this->expectException(\RuntimeException::class);
            SplitSetSource::identity($base);
        } finally {
            @unlink($part);
            @unlink($base);
        }
    }
}
