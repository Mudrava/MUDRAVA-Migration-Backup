<?php

/**
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */


declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Archive\FrameReader;
use Mudrava\Migration\Archive\FrameType;
use Mudrava\Migration\Archive\FrameWriter;
use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Compression\DeflateCodec;
use Mudrava\Migration\Filesystem\WpFileInventory;
use Mudrava\Migration\Migration\Importer;
use Mudrava\Migration\Integration\CoreApi;
use Mudrava\Migration\Integration\ProtectedDirectories;
use Mudrava\Migration\Tests\Support\LocalFileTarget;
use Mudrava\Migration\Tests\Support\RecordingDatabaseTarget;
use PHPUnit\Framework\TestCase;

final class SelfPluginProtectionTest extends TestCase
{
    /** @runInSeparateProcess */
    public function testExportExcludesCurrentPluginAndOldArchiveCannotOverwriteIt(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/self-' . bin2hex(random_bytes(6));
        $plugin = $root . '/wp-content/plugins/mudrava-migration-backup';
        mkdir($plugin, 0777, true);
        file_put_contents($plugin . '/marker.txt', 'current');
        $addon = $root . '/wp-content/plugins/custom-addon-name';
        mkdir($addon, 0777, true);
        file_put_contents($addon . '/marker.txt', 'paid-current');
        CoreApi::protectDirectory($addon);
        file_put_contents($root . '/other.txt', 'other');
        define('MUDRAVA_MB_PLUGIN_DIR', $plugin . '/');
        $inventory = new WpFileInventory($root);
        self::assertSame(['other.txt'], array_column(iterator_to_array($inventory->iterate()), 'path'));

        $archive = $root . '/old.mudrava';
        $out = fopen($archive, 'wb');
        $header = new Header(1, 0, random_bytes(16), 'test', 0, 0, 0, str_repeat("\0", 16), str_repeat("\0", 8));
        $writer = new FrameWriter($out, $header, new DeflateCodec(), null);
        $writer->writeJson(FrameType::SITE_METADATA, ['site_url' => 'https://old.test']);
        $writer->writeJson(FrameType::FILE_METADATA, ['path' => 'wp-content/plugins/mudrava-migration-backup/marker.txt']);
        $writer->writeFrame(FrameType::FILE_DATA, 'obsolete');
        $writer->writeJson(FrameType::FILE_METADATA, ['path' => 'wp-content/plugins/custom-addon-name/marker.txt']);
        $writer->writeFrame(FrameType::FILE_DATA, 'paid-obsolete');
        $writer->writeJson(FrameType::FILE_METADATA, ['path' => 'restored.txt']);
        $writer->writeFrame(FrameType::FILE_DATA, 'ordinary-restored');
        $writer->finalize(['file_count' => 3, 'row_count' => 0]);
        fclose($out);

        $in = fopen($archive, 'rb');
        $importer = new Importer(new FrameReader($in, new DeflateCodec()), new RecordingDatabaseTarget(), new LocalFileTarget($root));
        self::assertSame(Importer::STATE_DONE, $importer->step());
        fclose($in);
        self::assertSame('current', file_get_contents($plugin . '/marker.txt'));
        self::assertSame('paid-current', file_get_contents($addon . '/marker.txt'));
        self::assertSame('ordinary-restored', file_get_contents($root . '/restored.txt'));
        self::assertSame(1, $importer->restoredFiles());
    }

    public function testRootAndOutsideDirectoriesCannotExcludeTheSite(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/protected-' . bin2hex(random_bytes(4));
        mkdir($root);
        $outside = $root . '-other';
        mkdir($outside);
        CoreApi::protectDirectory($root);
        CoreApi::protectDirectory($outside);
        self::assertSame([], ProtectedDirectories::relativeTo($root));
        self::assertFalse(ProtectedDirectories::contains('ordinary.txt', $root));
    }

    public function testDirectoryPrefixDoesNotProtectSiblingNames(): void
    {
        $root = $GLOBALS['MUDRAVA_TEST_TMP'] . '/protected-' . bin2hex(random_bytes(4));
        mkdir($root . '/addon', 0777, true);
        CoreApi::protectDirectory($root . '/addon');
        self::assertTrue(ProtectedDirectories::contains('addon/./code.php', $root));
        self::assertFalse(ProtectedDirectories::contains('addon-copy/code.php', $root));
    }

    public function testInvalidDirectoryIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CoreApi::protectDirectory($GLOBALS['MUDRAVA_TEST_TMP'] . '/missing-addon');
    }

}
