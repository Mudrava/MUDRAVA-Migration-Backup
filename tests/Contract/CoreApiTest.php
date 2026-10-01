<?php

/**
 * Add-on contract through the real checkpointed export and verifier.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Contract;

use Mudrava\Migration\Integration\CoreApi;
use Mudrava\Migration\Migration\JobRunner;
use Mudrava\Migration\Support\Paths;
use PHPUnit\Framework\TestCase;

final class CoreApiTest extends TestCase
{
    /** @var list<string> */
    private $archives = [];

    protected function setUp(): void
    {
        $GLOBALS['MUDRAVA_STUB_MULTISITE'] = false;
        $GLOBALS['wpdb'] = new \wpdb();
        $GLOBALS['MUDRAVA_STUB_OPTIONS'] = [];
        JobRunner::clear();
    }

    protected function tearDown(): void
    {
        foreach ($this->archives as $id) {
            $base = (new Paths())->storageDir() . '/' . $id . '.mudrava';
            foreach (glob($base . '*') ?: [] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
        JobRunner::clear();
    }

    public function testCompletedExportDescriptorUsesTheVerifiedProductionArchive(): void
    {
        $id = $this->start();
        for ($i = 0; $i < 100; $i++) {
            $job = CoreApi::tickExport($id);
            if ($job['state'] === 'done') {
                break;
            }
            $this->assertSame('running', $job['state']);
        }
        $this->assertSame('done', $job['state']);
        $this->assertTrue($job['verified']);
        $descriptor = CoreApi::completedExport($id);
        $this->assertSame($id, $descriptor['archive_id']);
        $this->assertFalse($descriptor['encrypted']);
        $this->assertCount(1, $descriptor['parts']);
        $this->assertSame($id . '.mudrava', $descriptor['parts'][0]['name']);
        $this->assertSame((string) filesize($descriptor['parts'][0]['path']), $descriptor['parts'][0]['bytes']);
        $this->assertSame(JobRunner::load()['archive_identity'], $descriptor['identity']);
        file_put_contents($descriptor['parts'][0]['path'], 'changed', FILE_APPEND);
        $this->expectException(\RuntimeException::class);
        CoreApi::completedExport($id);
    }

    public function testAnAutomationTickCannotAdvanceAReplacementImport(): void
    {
        $id = $this->start();
        JobRunner::save([
            'kind' => 'import', 'state' => 'running', 'archive_id' => $id,
            'restored_rows' => 0, 'restored_files' => 0,
        ]);
        try {
            CoreApi::tickExport($id);
            $this->fail('A replacement import must not be advanced');
        } catch (\RuntimeException $error) {
            $this->assertSame('MUDRAVA_JOB_CHANGED', $error->getMessage());
        }
        $this->assertSame(0, JobRunner::load()['restored_rows']);
        $this->assertSame(0, JobRunner::load()['restored_files']);
        $this->assertArrayNotHasKey('verified', JobRunner::load());
    }

    public function testAnAutomationTickCannotAdvanceAnotherExport(): void
    {
        $id = $this->start();
        $this->expectExceptionMessage('MUDRAVA_JOB_CHANGED');
        CoreApi::tickExport($id . '-other');
    }

    public function testAnUnverifiedJobCannotProduceADeliveryDescriptor(): void
    {
        $id = $this->start();
        JobRunner::save(['kind' => 'export', 'state' => 'done', 'archive_id' => $id]);
        $this->expectExceptionMessage('MUDRAVA_ARCHIVE_NOT_READY');
        CoreApi::completedExport($id);
    }

    public function testStatusExcludesCredentialsAndInternalPaths(): void
    {
        $this->assertNull(CoreApi::status());
        JobRunner::save([
            'kind' => 'export', 'state' => 'running', 'archive_id' => 'safe-fixture',
            'site_meta' => ['password' => 'secret'], 'checkpoint' => ['path' => '/private'],
            'transport_key' => 'secret',
        ]);
        $status = CoreApi::status();
        $this->assertSame('safe-fixture', $status['archive_id']);
        foreach (['site_meta', 'checkpoint', 'transport_key'] as $key) {
            $this->assertArrayNotHasKey($key, $status);
        }
    }

    public function testInvalidSplitDoesNotReplaceAnExistingJob(): void
    {
        JobRunner::save(['kind' => 'import', 'state' => 'running', 'archive_id' => 'destination']);
        try {
            CoreApi::startExport([], false, -1);
            $this->fail('Invalid split size must be rejected');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame('MUDRAVA_INVALID_SPLIT_SIZE', $error->getMessage());
        }
        $this->assertSame('destination', JobRunner::load()['archive_id']);
    }

    public function testPrivateAddonDirectoryIsStableAndOutsideSiteContent(): void
    {
        $directory = CoreApi::privateAddonDirectory('contract-fixture');
        $this->assertSame($directory, CoreApi::privateAddonDirectory('contract-fixture'));
        $this->assertSame(0700, fileperms($directory) & 0777);
        $this->assertStringStartsWith(realpath((new Paths())->ensureStorage()) . '/.addons/', $directory);
        $this->assertNotSame(0, strpos($directory, rtrim(ABSPATH, '/') . '/'));
        $this->assertNotSame(0, strpos($directory, rtrim(WP_CONTENT_DIR, '/') . '/'));
    }

    public function testAddonNamespaceCannotEscapePrivateStorage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CoreApi::privateAddonDirectory('../public');
    }

    public function testLinkedAddonDirectoryIsRejected(): void
    {
        $base = (new Paths())->ensureStorage() . '/.addons';
        CoreApi::privateAddonDirectory('contract-fixture');
        symlink(ABSPATH, $base . '/linked-fixture');
        try {
            $this->expectExceptionMessage('MUDRAVA_STORAGE_UNSAFE');
            CoreApi::privateAddonDirectory('linked-fixture');
        } finally {
            unlink($base . '/linked-fixture');
        }
    }

    public function testClaimedStartCanRecoverWithoutCreatingAnotherArchive(): void
    {
        $request = bin2hex(random_bytes(32));
        $snapshot = CoreApi::captureState();
        $job = CoreApi::startExport([], false, null, $request, $snapshot['token']);
        $this->archives[] = $job['archive_id'];
        $this->assertSame($job, CoreApi::startExport([], false, null, $request, $snapshot['token']));
        for ($i = 0; $i < 100 && $job['state'] !== 'done'; $i++) {
            $job = CoreApi::tickExport($job['archive_id']);
        }
        $this->assertSame('done', $job['state']);
        $recovered = CoreApi::startExport([], false, null, $request, $snapshot['token']);
        $this->assertSame($job['archive_id'], $recovered['archive_id']);
        $this->assertSame('done', $recovered['state']);
        $this->assertTrue($recovered['verified']);
    }

    public function testClaimedStartCannotReplaceAChangedCoreSlot(): void
    {
        $snapshot = CoreApi::captureState();
        JobRunner::save(['kind' => 'import', 'state' => 'done', 'archive_id' => 'manual-changed']);
        try {
            CoreApi::startExport([], false, null, bin2hex(random_bytes(32)), $snapshot['token']);
            $this->fail('A replaced slot must not be overwritten');
        } catch (\RuntimeException $error) {
            $this->assertSame('MUDRAVA_JOB_CHANGED', $error->getMessage());
        }
        $this->assertSame('manual-changed', JobRunner::load()['archive_id']);
    }

    private function start(): string
    {
        $job = CoreApi::startExport();
        $this->assertArrayNotHasKey('site_meta', $job);
        $id = (string) $job['archive_id'];
        $this->archives[] = $id;
        return $id;
    }
}
