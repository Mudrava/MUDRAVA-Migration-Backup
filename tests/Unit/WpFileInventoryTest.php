<?php

/**
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Filesystem\WpFileInventory;
use PHPUnit\Framework\TestCase;

final class WpFileInventoryTest extends TestCase
{
    public function testWalkIsDeterministicAndRespectsExclusions(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/inventory-' . bin2hex(random_bytes(5));
        mkdir($root . '/a', 0777, true);
        mkdir($root . '/wp-content/cache', 0777, true);
        file_put_contents($root . '/z.txt', 'z');
        file_put_contents($root . '/a/b.txt', 'b');
        file_put_contents($root . '/a/a.txt', 'a');
        file_put_contents($root . '/wp-content/cache/skip.txt', 'skip');
        symlink('a/b.txt', $root . '/link');

        $inventory = new WpFileInventory($root);
        $first = array_values(iterator_to_array($inventory->iterate()));
        $second = array_values(iterator_to_array($inventory->iterate()));
        self::assertSame($first, $second);
        self::assertSame(['a/a.txt', 'a/b.txt', 'link', 'z.txt'], array_column($first, 'path'));
        self::assertSame('symlink', $first[2]['type']);
    }

    public function testExtraExclusionsAndHostConfigAreNeverYielded(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/inventory-' . bin2hex(random_bytes(5));
        mkdir($root . '/private', 0777, true);
        file_put_contents($root . '/private/secret.txt', 'secret');
        file_put_contents($root . '/wp-config.php', 'credentials');
        file_put_contents($root . '/public.txt', 'public');

        $inventory = new WpFileInventory($root, ['private']);
        $this->assertSame(['public.txt'], array_column(iterator_to_array($inventory->iterate()), 'path'));
        $handle = $inventory->open('public.txt');
        $this->assertIsResource($handle);
        $this->assertSame('public', stream_get_contents($handle));
        fclose($handle);
    }

    public function testMissingRootFailsInsteadOfProducingIncompleteArchive(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/inventory-missing-' . uniqid();
        $inventory = new WpFileInventory($root);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MUDRAVA_PERMISSION_DENIED');
        iterator_to_array($inventory->iterate());
    }

    public function testMissingFileFailsSafely(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/inventory-missing-' . uniqid();
        mkdir($root);
        $inventory = new WpFileInventory($root);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MUDRAVA_PERMISSION_DENIED');
        $inventory->open('missing.txt');
    }

    public function testSpecialFileIsRejectedInsteadOfSilentlySkipped(): void
    {
        if (!function_exists('posix_mkfifo')) {
            $this->markTestSkipped('POSIX FIFO support unavailable');
        }
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/inventory-fifo-' . uniqid();
        mkdir($root);
        $this->assertTrue(posix_mkfifo($root . '/pipe', 0600));
        $inventory = new WpFileInventory($root);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MUDRAVA_PATH_UNSAFE');
        iterator_to_array($inventory->iterate());
    }
}
