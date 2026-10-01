<?php

/**
 * Traversal and symlink policy contract for every restored filesystem path.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Filesystem\PathGuard;
use PHPUnit\Framework\TestCase;

final class PathGuardTest extends TestCase
{
    public function testNormalizationCollapsesHarmlessSegments(): void
    {
        $this->assertSame('wp-content/file.txt', PathGuard::normalize('./wp-content//file.txt'));
    }

    /** @dataProvider unsafePaths */
    public function testUnsafePathsAreRejected(string $path, string $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        PathGuard::normalize($path);
    }

    /** @return array<string,array{string,string}> */
    public static function unsafePaths(): array
    {
        return [
            'empty' => ['', 'empty or null byte'],
            'null byte' => ["a\0b", 'empty or null byte'],
            'windows absolute' => ['C:\\Windows\\file', 'windows absolute'],
            'unix absolute' => ['/etc/passwd', 'absolute path'],
            'leading backslash' => ['\\etc\\passwd', 'absolute path'],
            'backslash' => ['dir\\file', 'backslash in path'],
            'parent' => ['dir/../file', 'parent traversal'],
            'empty after cleanup' => ['./', 'empty after normalization'],
        ];
    }

    public function testResolveInsideRejectsMissingRootAndEscapingParentSymlink(): void
    {
        try {
            PathGuard::resolveInside($GLOBALS['MUDRAVA_TEST_TMP'] . '/missing-root', 'file.txt');
            $this->fail('missing root must fail');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('destination root missing', $error->getMessage());
        }

        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/guard-' . uniqid();
        $outside = $GLOBALS['MUDRAVA_TEST_TMP'] . '/guard-outside-' . uniqid();
        mkdir($root);
        mkdir($outside);
        symlink($outside, $root . '/escape');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('escapes destination root');
            PathGuard::resolveInside($root, 'escape/file.txt');
        } finally {
            unlink($root . '/escape');
            rmdir($root);
            rmdir($outside);
        }
    }

    public function testDestinationResolverRejectsMissingRoot(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('destination root missing');
        PathGuard::resolveDestinationInside($GLOBALS['MUDRAVA_TEST_TMP'] . '/missing-destination-root', 'file.txt');
    }

    /** @dataProvider symlinkTargets */
    public function testSymlinkTargetPolicy(string $target, bool $safe): void
    {
        $this->assertSame($safe, PathGuard::symlinkTargetSafe('/unused', $target));
    }

    /** @return array<string,array{string,bool}> */
    public static function symlinkTargets(): array
    {
        return [
            'relative' => ['images/current.jpg', true],
            'empty' => ['', false],
            'absolute' => ['/etc/passwd', false],
            'windows' => ['C:\\secret', false],
            'traversal' => ['../secret', false],
            'null' => ["file\0name", false],
        ];
    }
}
