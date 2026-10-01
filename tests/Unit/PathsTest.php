<?php

/**
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Support\Paths;
use PHPUnit\Framework\TestCase;

final class PathsTest extends TestCase
{
    public function testLegacyArchiveCountIsZeroWhenDirectoryIsAbsent(): void
    {
        $legacy = WP_CONTENT_DIR . '/mudrava-backups';
        if (is_dir($legacy) && count((array) glob($legacy . '/*')) === 0) {
            rmdir($legacy);
        }
        $this->assertDirectoryDoesNotExist($legacy);
        $this->assertSame(0, (new Paths())->legacyPublicArchiveCount());
    }

    public function testArchiveStorageIsOutsideWebRoot(): void
    {
        $paths = new Paths();
        $dir = $paths->ensureStorage();
        $root = realpath(ABSPATH);
        $this->assertNotFalse($root);
        $this->assertFalse(strpos((string) realpath($dir) . '/', $root . '/') === 0);
        $this->assertSame('', $paths->storageRelative());
    }

    public function testDetectsArchivesLeftInPublicLegacyDirectory(): void
    {
        $legacy = WP_CONTENT_DIR . '/mudrava-backups';
        $created = !is_dir($legacy);
        if ($created) {
            mkdir($legacy, 0777, true);
        }
        $file = $legacy . '/old-test.mudrava';
        file_put_contents($file, 'test');
        try {
            $this->assertGreaterThanOrEqual(1, (new Paths())->legacyPublicArchiveCount());
        } finally {
            @unlink($file);
            if ($created) {
                @rmdir($legacy);
            }
        }
    }

    public function testMovesLegacyArchiveWithoutOverwritingPrivateCopy(): void
    {
        $paths = new Paths();
        $private = $paths->ensureStorage();
        $legacy = WP_CONTENT_DIR . '/mudrava-backups';
        if (!is_dir($legacy)) {
            mkdir($legacy, 0777, true);
        }
        $old = $legacy . '/legacy-test.mudrava';
        $new = $private . '/legacy-test.mudrava';
        @unlink($old);
        @unlink($new);
        file_put_contents($old, 'old');
        try {
            $this->assertSame(1, $paths->migrateLegacyArchives());
            $this->assertFileDoesNotExist($old);
            $this->assertSame('old', file_get_contents($new));
            $this->assertSame(0600, fileperms($new) & 0777);

            file_put_contents($old, 'another');
            $this->assertSame(0, $paths->migrateLegacyArchives());
            $this->assertSame('old', file_get_contents($new));
        } finally {
            @unlink($old);
            @unlink($new);
        }
    }

    public function testLegacyMigrationIgnoresPublicSymlinksAndUnrecognizedNames(): void
    {
        $paths = new Paths();
        $private = $paths->ensureStorage();
        $legacy = WP_CONTENT_DIR . '/mudrava-backups';
        if (!is_dir($legacy)) {
            mkdir($legacy, 0777, true);
        }
        $outside = $GLOBALS['MUDRAVA_TEST_TMP'] . '/legacy-outside-' . uniqid();
        $link = $legacy . '/linked-test.mudrava';
        $unrecognized = $legacy . '/unexpected.php';
        file_put_contents($outside, 'outside');
        file_put_contents($unrecognized, 'leave me');
        symlink($outside, $link);
        try {
            $this->assertSame(0, $paths->migrateLegacyArchives());
            $this->assertTrue(is_link($link));
            $this->assertSame('outside', file_get_contents($outside));
            $this->assertSame('leave me', file_get_contents($unrecognized));
            $this->assertFileDoesNotExist($private . '/linked-test.mudrava');
        } finally {
            unlink($link);
            unlink($unrecognized);
            unlink($outside);
        }
    }

    public function testLegacyMigrationLeavesHardlinkedArchiveInPlace(): void
    {
        $paths = new Paths();
        $private = $paths->ensureStorage();
        $legacy = WP_CONTENT_DIR . '/mudrava-backups';
        if (!is_dir($legacy)) {
            mkdir($legacy, 0777, true);
        }
        $outside = $GLOBALS['MUDRAVA_TEST_TMP'] . '/legacy-hardlink-' . uniqid();
        $linked = $legacy . '/hardlinked-test.mudrava';
        file_put_contents($outside, 'outside');
        link($outside, $linked);
        try {
            $this->assertSame(0, $paths->migrateLegacyArchives());
            $this->assertSame('outside', file_get_contents($outside));
            $this->assertSame(2, (int) lstat($outside)['nlink']);
            $this->assertFileExists($linked);
            $this->assertFileDoesNotExist($private . '/hardlinked-test.mudrava');
        } finally {
            unlink($linked);
            unlink($outside);
        }
    }

    public function testLegacyMigrationWillNotFollowASymlinkedPublicDirectory(): void
    {
        $legacy = WP_CONTENT_DIR . '/mudrava-backups';
        $hadDirectory = is_dir($legacy);
        if ($hadDirectory) {
            $this->assertSame([], array_values(array_diff(scandir($legacy) ?: [], ['.', '..'])));
            rmdir($legacy);
        }
        $outside = $GLOBALS['MUDRAVA_TEST_TMP'] . '/legacy-directory-' . uniqid();
        mkdir($outside);
        file_put_contents($outside . '/hidden.mudrava', 'untouched');
        symlink($outside, $legacy);
        try {
            $this->assertSame(0, (new Paths())->migrateLegacyArchives());
            $this->assertSame('untouched', file_get_contents($outside . '/hidden.mudrava'));
        } finally {
            unlink($legacy);
            unlink($outside . '/hidden.mudrava');
            rmdir($outside);
            if ($hadDirectory) {
                mkdir($legacy, 0777, true);
            }
        }
    }

    public function testExistingPrivateDirectoryWinsOverDifferentUserPermissions(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/storage-choice-' . uniqid();
        mkdir($root);
        $parent = $root . '/parent';
        $temporary = $root . '/temporary';
        try {
            $this->assertSame($parent, Paths::chooseDefaultStorageDir($parent, $temporary, true));
            $this->assertSame($temporary, Paths::chooseDefaultStorageDir($parent, $temporary, false));
            mkdir($temporary);
            $this->assertSame($temporary, Paths::chooseDefaultStorageDir($parent, $temporary, true));
            mkdir($parent);
            file_put_contents($temporary . '/archive.mudrava', 'test');
            $this->assertSame($temporary, Paths::chooseDefaultStorageDir($parent, $temporary, true));
            unlink($temporary . '/archive.mudrava');
            file_put_contents($parent . '/archive.mudrava', 'test');
            $this->assertSame($parent, Paths::chooseDefaultStorageDir($parent, $temporary, false));
            file_put_contents($temporary . '/archive.mudrava', 'test');
            $caught = null;
            try {
                Paths::chooseDefaultStorageDir($parent, $temporary, true);
            } catch (\RuntimeException $error) {
                $caught = $error;
            }
            $this->assertInstanceOf(\RuntimeException::class, $caught);
            $this->assertStringContainsString('two active backup directories', $caught->getMessage());
        } finally {
            @unlink($parent . '/archive.mudrava');
            @unlink($temporary . '/archive.mudrava');
            @rmdir($parent);
            @rmdir($temporary);
            @rmdir($root);
        }
    }

    public function testDefaultStorageSelectorRejectsSymlinkCandidate(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/storage-link-' . uniqid();
        mkdir($root);
        $target = $root . '/target';
        mkdir($target);
        $link = $root . '/parent';
        if (!@symlink($target, $link)) {
            rmdir($target);
            rmdir($root);
            $this->markTestSkipped('symlinks unavailable');
        }
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('private storage path is a symlink');
            Paths::chooseDefaultStorageDir($link, $root . '/temporary', true);
        } finally {
            unlink($link);
            rmdir($target);
            rmdir($root);
        }
    }
}
