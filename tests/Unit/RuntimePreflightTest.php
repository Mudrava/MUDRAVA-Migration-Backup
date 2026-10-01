<?php

/**
 * Runtime and preflight contracts for the environment gate shown before a
 * migration starts.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Support\ErrorCode;
use Mudrava\Migration\Support\Preflight;
use Mudrava\Migration\Support\Runtime;
use PHPUnit\Framework\TestCase;

final class RuntimePreflightTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['MUDRAVA_STUB_MULTISITE'] = false;
        $GLOBALS['MUDRAVA_STUB_OPTIONS']['home'] = 'http://home.test';
        $GLOBALS['MUDRAVA_STUB_OPTIONS']['blog_charset'] = 'UTF-8';
        $GLOBALS['wpdb'] = (object) ['prefix' => 'custom_'];
    }

    protected function tearDown(): void
    {
        $GLOBALS['MUDRAVA_STUB_MULTISITE'] = false;
        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];
        parent::tearDown();
    }

    public function testRuntimeReportsEveryRequiredCapability(): void
    {
        $checks = Runtime::inspect();
        $byCode = [];
        foreach ($checks as $check) {
            $byCode[$check['code']] = $check;
        }

        $this->assertSame(['php_version', 'int64', 'zlib', 'crypto', 'json'], array_keys($byCode));
        $this->assertTrue($byCode['php_version']['ok']);
        $this->assertTrue($byCode['int64']['ok']);
        $this->assertTrue($byCode['zlib']['ok']);
        $this->assertTrue($byCode['json']['ok']);
        $this->assertStringContainsString('PHP ', $byCode['php_version']['detail']);
        $this->assertTrue(Runtime::isSupported());
    }

    public function testSiteMetadataContainsMigrationIdentityAndDestinationPrefix(): void
    {
        $meta = Preflight::siteMeta();

        $this->assertSame('http://testsite.local', $meta['site_url']);
        $this->assertSame('http://home.test', $meta['home_url']);
        $this->assertSame('6.8-test', $meta['wp_version']);
        $this->assertSame('UTF-8', $meta['charset']);
        $this->assertSame('custom_', $meta['db_prefix']);
        $this->assertFalse($meta['multisite']);
        $this->assertSame('mudrava-migration-backup ' . MUDRAVA_MB_VERSION, $meta['producer']);
        $this->assertIsInt($meta['created_at']);
    }

    public function testSiteSizeWalkCountsRegularFilesAndSkipsSymlinks(): void
    {
        $dir = rtrim(ABSPATH, '/') . '/preflight-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/one.bin', str_repeat('a', 37));
        mkdir($dir . '/nested');
        file_put_contents($dir . '/nested/two.bin', str_repeat('b', 19));
        $baseline = Preflight::siteSizeBytes() - 56;
        $symlinkCreated = @symlink($dir . '/one.bin', $dir . '/duplicate-link');

        try {
            $this->assertSame($baseline + 56, Preflight::siteSizeBytes());
            if ($symlinkCreated) {
                $this->assertSame($baseline + 56, Preflight::siteSizeBytes());
            }
        } finally {
            if ($symlinkCreated) {
                unlink($dir . '/duplicate-link');
            }
            unlink($dir . '/nested/two.bin');
            rmdir($dir . '/nested');
            unlink($dir . '/one.bin');
            rmdir($dir);
        }
    }

    public function testPreflightAllowsSupportedSingleSiteAndRejectsMultisite(): void
    {
        $single = Preflight::run();
        $this->assertTrue($single['can_run']);
        $this->assertSame('custom_', $single['site']['db_prefix']);
        $this->assertContains('Backups are in the system temporary directory and may be removed by the host. '
            . 'Set MUDRAVA_MB_STORAGE_DIR to a durable private path outside the web root.', $single['warnings']);

        $GLOBALS['MUDRAVA_STUB_MULTISITE'] = true;
        $multi = Preflight::run();
        $singleSite = array_values(array_filter($multi['checks'], static function (array $check): bool {
            return $check['code'] === 'single_site';
        }));

        $this->assertFalse($multi['can_run']);
        $this->assertCount(1, $singleSite);
        $this->assertFalse($singleSite[0]['ok']);
        $this->assertSame('Multisite is not supported by this edition', $singleSite[0]['detail']);
    }

    public function testPreflightWarnsAboutLegacyArchivesInPublicContent(): void
    {
        $legacy = rtrim(WP_CONTENT_DIR, '/') . '/mudrava-backups';
        $createdDir = !is_dir($legacy);
        if ($createdDir) {
            mkdir($legacy, 0777, true);
        }
        $archive = $legacy . '/legacy-' . bin2hex(random_bytes(4)) . '.mudrava';
        file_put_contents($archive, 'legacy');

        try {
            $result = Preflight::run();
            $matches = array_values(array_filter($result['warnings'], static function (string $warning): bool {
                return strpos($warning, 'archive file(s) remain in the old public') !== false;
            }));
            $this->assertCount(1, $matches);
            $this->assertStringContainsString('remove public copies before using this site in production', $matches[0]);
        } finally {
            unlink($archive);
            if ($createdDir) {
                rmdir($legacy);
            }
        }
    }

    public function testPreflightWarnsWhenRollbackHeadroomIsInsufficient(): void
    {
        $path = rtrim(ABSPATH, '/') . '/preflight-sparse-' . bin2hex(random_bytes(4));
        $free = disk_free_space((new \Mudrava\Migration\Support\Paths())->ensureStorage());
        $this->assertIsFloat($free);
        $handle = fopen($path, 'w+b');
        $this->assertIsResource($handle);
        $size = (int) floor($free / 2) + 1048576;
        if (!ftruncate($handle, $size)) {
            fclose($handle);
            unlink($path);
            $this->markTestSkipped('filesystem does not support sparse files');
        }
        fclose($handle);

        try {
            $warnings = Preflight::run()['warnings'];
            $matches = array_values(array_filter($warnings, static function (string $warning): bool {
                return strpos($warning, 'Safe rollback needs') === 0;
            }));
            $this->assertCount(1, $matches);
            $this->assertStringContainsString('more free space', $matches[0]);
        } finally {
            unlink($path);
        }
    }

    public function testEveryStableErrorCodeHasAUserMessage(): void
    {
        $reflection = new \ReflectionClass(ErrorCode::class);
        $messages = ErrorCode::userMessages();

        foreach ($reflection->getConstants() as $code) {
            $this->assertArrayHasKey($code, $messages);
            $this->assertNotSame('', $messages[$code]);
            $this->assertSame($messages[$code], ErrorCode::userMessage($code));
        }
        $this->assertSame('The migration could not be completed.', ErrorCode::userMessage('MUDRAVA_UNKNOWN'));
    }
}
