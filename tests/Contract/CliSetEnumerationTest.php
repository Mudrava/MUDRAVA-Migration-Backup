<?php

/**
 * The WP-CLI bridge stages and counts split sets from disk, so the part
 * numbering convention is a contract: part 1 is the base NAME.mudrava,
 * continuation parts are numbered from 0002, and a gap must be a hard
 * refusal rather than a silent early stop (the bug that shipped against
 * MariaDB in development: scanning from 0001 made every split import
 * claim "+0 parts" and then die inside the restore).
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Contract;

use Mudrava\Migration\Migration\ImportOps;
use PHPUnit\Framework\TestCase;

final class CliSetEnumerationTest extends TestCase
{
    /** @var string */
    private $tmp;

    protected function setUp(): void
    {
        $this->tmp = $GLOBALS['MUDRAVA_TEST_TMP'] . '/cliset-' . uniqid();
        mkdir($this->tmp, 0777, true);
    }

    private function touch(string $base, string $suffix = ''): void
    {
        file_put_contents($base . $suffix, 'x');
    }

    public function testUnsplitArchiveIsOnePart(): void
    {
        $base = $this->tmp . '/a.mudrava';
        $this->touch($base);
        self::assertSame(1, ImportOps::countSetParts($base));
    }

    public function testContinuationPartsNumberFrom0002(): void
    {
        $base = $this->tmp . '/b.mudrava';
        $this->touch($base);
        $this->touch($base, '.part0002');
        $this->touch($base, '.part0003');
        self::assertSame(3, ImportOps::countSetParts($base));
    }

    public function testGapIsAHardRefusalNotASilentStop(): void
    {
        $base = $this->tmp . '/c.mudrava';
        $this->touch($base);
        $this->touch($base, '.part0002');
        $this->touch($base, '.part0004');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MUDRAVA_SET_INCOMPLETE');
        ImportOps::countSetParts($base);
    }

    public function testForeignPart0001IsRefused(): void
    {
        $base = $this->tmp . '/d.mudrava';
        $this->touch($base);
        $this->touch($base, '.part0001');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MUDRAVA_SET_INCOMPLETE');
        ImportOps::countSetParts($base);
    }
}
