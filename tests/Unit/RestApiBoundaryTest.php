<?php

/**
 * REST boundary contracts: route authorization, response redaction and safe
 * failures before the migration engine receives untrusted input.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Archive\FrameType;
use Mudrava\Migration\Archive\FrameWriter;
use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Compression\DeflateCodec;
use Mudrava\Migration\Http\RestApi;
use Mudrava\Migration\Http\RestoreToken;
use Mudrava\Migration\Migration\JobRunner;
use Mudrava\Migration\Support\Paths;
use PHPUnit\Framework\TestCase;

final class RestApiBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['MUDRAVA_STUB_MULTISITE'] = false;
        $GLOBALS['MUDRAVA_STUB_CURRENT_USER_CAN'] = false;
        $GLOBALS['MUDRAVA_STUB_CURRENT_USER_ID'] = 17;
        $GLOBALS['MUDRAVA_STUB_ROUTES'] = [];
        JobRunner::clear();
        RestoreToken::clear();
        $GLOBALS['MUDRAVA_STUB_OPTIONS'] = [];
    }

    protected function tearDown(): void
    {
        JobRunner::clear();
        $GLOBALS['MUDRAVA_STUB_CURRENT_USER_CAN'] = false;
        parent::tearDown();
    }

    public function testEveryRouteUsesTheCapabilityCallback(): void
    {
        RestApi::register();
        $routes = $GLOBALS['MUDRAVA_STUB_ROUTES'];

        $this->assertCount(15, $routes);
        foreach ($routes as $route) {
            $this->assertSame(RestApi::NS, $route['namespace']);
            $this->assertSame([RestApi::class, 'can'], $route['args']['permission_callback']);
        }

        $pairs = array_map(static function (array $route): string {
            return $route['args']['methods'] . ' ' . $route['route'];
        }, $routes);
        $this->assertContains('POST /job/import', $pairs);
        $this->assertContains('DELETE /job', $pairs);
        $this->assertContains('GET /archives/(?P<archive_id>[A-Za-z0-9\-]+)/download', $pairs);
    }

    public function testCapabilityDecisionComesFromWordPress(): void
    {
        $this->assertFalse(RestApi::can());
        $GLOBALS['MUDRAVA_STUB_CURRENT_USER_CAN'] = true;
        $this->assertTrue(RestApi::can());
    }

    public function testStatusRedactsInternalJobStateAndSecrets(): void
    {
        $idle = RestApi::status();
        $this->assertSame(200, $idle->get_status());
        $this->assertSame(['state' => 'idle'], $idle->get_data());

        JobRunner::save([
            'kind'       => JobRunner::KIND_IMPORT,
            'state'      => 'running',
            'archive_id' => 'safe-id',
            'percent'    => 41,
            'password'   => 'must-not-leak',
            'checkpoint' => ['private' => true],
            'secret'     => 'must-not-leak',
        ]);
        $GLOBALS['MUDRAVA_STUB_OPTIONS'][JobRunner::CRON_BEAT] = (string) time();

        $data = RestApi::status()->get_data();
        $this->assertSame('safe-id', $data['archive_id']);
        $this->assertSame(41, $data['percent']);
        $this->assertTrue($data['cron_alive']);
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('checkpoint', $data);
        $this->assertArrayNotHasKey('secret', $data);
    }

    public function testMissingJobBecomesSafeTypedTickError(): void
    {
        $response = RestApi::tick(new \WP_REST_Request());

        $this->assertSame(500, $response->get_status());
        $this->assertSame(['code' => 'MUDRAVA_JOB_NOT_FOUND'], $response->get_data());
    }

    public function testInvalidArchiveAndMissingUploadFileFailBeforeDiskAccess(): void
    {
        $archive = RestApi::archiveInfo(new \WP_REST_Request(['archive_id' => '../secret']));
        $upload = RestApi::uploadChunk(new \WP_REST_Request([
            'upload_id' => 'upload-1',
            'part'      => 1,
            'parts'     => 1,
            'index'     => 0,
            'total'     => 1,
        ]));

        $this->assertSame(404, $archive->get_status());
        $this->assertSame(['code' => 'MUDRAVA_ARCHIVE_NOT_FOUND'], $archive->get_data());
        $this->assertSame(400, $upload->get_status());
        $this->assertSame(['code' => 'MUDRAVA_UPLOAD_CHUNK_MISSING'], $upload->get_data());
    }

    public function testCancelOnlyAcceptsARunningExport(): void
    {
        $rejected = RestApi::cancel();
        $this->assertSame(409, $rejected->get_status());
        $this->assertSame(['code' => 'MUDRAVA_JOB_NOT_CANCELLABLE'], $rejected->get_data());

        JobRunner::startExportJob(['site_meta' => ['site_url' => 'https://source.test']]);
        $accepted = RestApi::cancel();
        $this->assertSame(200, $accepted->get_status());
        $this->assertSame(['state' => 'idle'], $accepted->get_data());
        $this->assertNull(JobRunner::load());
    }

    public function testStartExportSanitizesPickerAndReturnsOnlyPublicState(): void
    {
        $response = RestApi::startExport(new \WP_REST_Request([
            'split_bytes'  => 1048576,
            'password_hint' => 'company vault',
            'encrypted'    => false,
            'excludes'     => [
                'tables' => ['wp_cache', 'wp_posts;drop'],
                'dirs'   => ['wp-content/cache', '../private'],
            ],
        ]));

        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame(JobRunner::KIND_EXPORT, $data['kind']);
        $this->assertSame('running', $data['state']);
        $this->assertFalse($data['encrypted']);
        $this->assertArrayNotHasKey('site_meta', $data);
        $this->assertArrayNotHasKey('excludes', $data);

        $stored = JobRunner::load();
        $this->assertSame(['tables' => ['wp_cache'], 'dirs' => ['wp-content/cache']], $stored['excludes']);
    }

    public function testArchiveListInfoAndUnsafeImportStartUseARealArchive(): void
    {
        $id = 'rest-' . bin2hex(random_bytes(5));
        $path = $this->createArchive($id);
        try {
            $archives = RestApi::archives()->get_data();
            $listed = array_values(array_filter($archives['archives'], static function (array $archive) use ($id): bool {
                return $archive['archive_id'] === $id;
            }));
            $this->assertCount(1, $listed);
            $this->assertSame(1, $listed[0]['parts']);
            $this->assertSame('https://source.test', $listed[0]['source_url']);

            $info = RestApi::archiveInfo(new \WP_REST_Request(['archive_id' => $id]));
            $this->assertSame(200, $info->get_status());
            $this->assertSame('https://source.test', $info->get_data()['source_url']);

            $import = RestApi::startImport(new \WP_REST_Request([
                'archive_id'     => $id,
                'expected_parts' => 1,
                'proceed_unsafe' => true,
                'url_rewrite'    => [
                    'search'  => 'https://source.test',
                    'replace' => 'https://target.test',
                ],
            ]));
            $this->assertSame(200, $import->get_status());
            $this->assertSame(JobRunner::KIND_IMPORT, $import->get_data()['kind']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $import->get_data()['restore_token']);
            $this->assertSame(17, RestoreToken::verify($import->get_data()['restore_token']));
            $this->assertArrayNotHasKey('restore_token', JobRunner::load());
        } finally {
            JobRunner::clear();
            RestoreToken::clear();
            @unlink($path);
        }
    }

    public function testChunkUploadWrappersExposeProgressAndTypedErrors(): void
    {
        $id = 'rest-upload-' . bin2hex(random_bytes(4));
        $chunk = tempnam(sys_get_temp_dir(), 'mudrava-rest-upload-');
        $this->assertIsString($chunk);
        file_put_contents($chunk, 'archive bytes');
        $paths = new Paths();
        try {
            $put = RestApi::uploadChunk(new \WP_REST_Request([
                'upload_id' => $id,
                'part'      => 1,
                'parts'     => 1,
                'index'     => 0,
                'total'     => 1,
            ], ['file' => ['tmp_name' => $chunk]]));
            $this->assertSame(200, $put->get_status());
            $this->assertSame(1, $put->get_data()['received']);

            $status = RestApi::uploadStatus(new \WP_REST_Request(['upload_id' => $id]));
            $this->assertTrue($status->get_data()['found']);

            $first = RestApi::finalizeUpload(new \WP_REST_Request(['upload_id' => $id]));
            $done = RestApi::finalizeUpload(new \WP_REST_Request(['upload_id' => $id]));
            $this->assertFalse($first->get_data()['done']);
            $this->assertTrue($done->get_data()['done']);

            $bad = RestApi::uploadStatus(new \WP_REST_Request(['upload_id' => '../bad']));
            $this->assertSame(400, $bad->get_status());
            $this->assertSame(['code' => 'MUDRAVA_UPLOAD_ID_INVALID'], $bad->get_data());
        } finally {
            @unlink($chunk);
            @unlink($paths->storageDir() . '/' . $id . '.mudrava');
            $dir = $paths->uploadsDir() . '/' . $id;
            foreach ((array) glob($dir . '/*') as $file) {
                if (is_string($file)) {
                    @unlink($file);
                }
            }
            @rmdir($dir);
        }
    }

    public function testReadOnlyEndpointsReturnPreflightInventoryAndHeadroom(): void
    {
        $GLOBALS['wpdb'] = new \wpdb();
        $preflight = RestApi::preflight();
        $inventory = RestApi::inventory();
        $headroom = RestApi::restorePoint();

        $this->assertSame(200, $preflight->get_status());
        $this->assertTrue($preflight->get_data()['can_run']);
        $this->assertSame([], $inventory->get_data()['tables']);
        $this->assertArrayHasKey('files', $inventory->get_data());
        $this->assertIsString($headroom->get_data()['free']);
        $this->assertIsString($headroom->get_data()['needed']);
        $this->assertIsBool($headroom->get_data()['safe']);
    }

    public function testStartRestorePointCreatesPurposeBoundExport(): void
    {
        $response = RestApi::startRestorePoint();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(JobRunner::KIND_EXPORT, $response->get_data()['kind']);
        $stored = JobRunner::load();
        $this->assertSame('restore_point', $stored['purpose']);
    }

    /**
     * BUG-01 regression: headroom() touches private storage. An unsafe or
     * unwritable storage directory must return the same typed 409 as every
     * other pre-flight failure - never an uncaught 500.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testStartRestorePointReturnsTypedErrorOnUnusableStorage(): void
    {
        $paths = new Paths();
        $dir = $paths->storageDir();
        $aside = $dir . '.aside-' . bin2hex(random_bytes(4));
        $this->assertTrue(rename($dir, $aside));
        $this->assertTrue(symlink($aside . '-nowhere', $dir));
        try {
            $response = RestApi::startRestorePoint();
            $this->assertSame(409, $response->get_status());
            $this->assertSame('MUDRAVA_STORAGE_UNSAFE', $response->get_data()['code']);
            $this->assertNull(JobRunner::load());
        } finally {
            unlink($dir);
            rename($aside, $dir);
        }
    }

    public function testJobStartsMapMultisiteAndRestoreSafetyFailures(): void
    {
        $GLOBALS['MUDRAVA_STUB_MULTISITE'] = true;
        $blocked = RestApi::startExport(new \WP_REST_Request([
            'encrypted' => false,
            'excludes'  => [],
        ]));
        $this->assertSame(409, $blocked->get_status());
        $this->assertSame(['code' => 'MUDRAVA_MULTISITE_UNSUPPORTED'], $blocked->get_data());
        $GLOBALS['MUDRAVA_STUB_MULTISITE'] = false;

        $missing = RestApi::startImport(new \WP_REST_Request(['archive_id' => '']));
        $this->assertSame(400, $missing->get_status());
        $this->assertSame(['code' => 'MUDRAVA_ARCHIVE_NOT_FOUND'], $missing->get_data());

        $id = 'rest-' . bin2hex(random_bytes(5));
        $path = $this->createArchive($id);
        try {
            $unsafe = RestApi::startImport(new \WP_REST_Request([
                'archive_id'     => $id,
                'expected_parts' => 1,
                'proceed_unsafe' => false,
            ]));
            $this->assertSame(409, $unsafe->get_status());
            $this->assertSame(['code' => 'MUDRAVA_RESTORE_POINT_REQUIRED'], $unsafe->get_data());
        } finally {
            @unlink($path);
        }
    }

    public function testMissingArchiveAndUploadOperationsReturnTypedErrors(): void
    {
        $info = RestApi::archiveInfo(new \WP_REST_Request(['archive_id' => 'missing-archive']));
        $downloadBad = RestApi::downloadArchive(new \WP_REST_Request(['archive_id' => '../bad']));
        $downloadMissing = RestApi::downloadArchive(new \WP_REST_Request([
            'archive_id' => 'missing-archive',
            'part'       => 1,
        ]));
        $finalize = RestApi::finalizeUpload(new \WP_REST_Request(['upload_id' => 'bad']));

        $this->assertSame(404, $info->get_status());
        $this->assertSame(404, $downloadBad->get_status());
        $this->assertSame(404, $downloadMissing->get_status());
        $this->assertSame(400, $finalize->get_status());
        $this->assertSame(['code' => 'MUDRAVA_UPLOAD_ID_INVALID'], $finalize->get_data());
    }

    public function testRestorePointAndImportConflictsReturnTypedErrors(): void
    {
        JobRunner::startExportJob(['site_meta' => ['site_url' => 'https://busy.test']]);
        $restorePoint = RestApi::startRestorePoint();
        $this->assertSame(409, $restorePoint->get_status());
        $this->assertSame('MUDRAVA_JOB_RUNNING', $restorePoint->get_data()['code']);

        $id = 'rest-' . bin2hex(random_bytes(5));
        $path = $this->createArchive($id);
        try {
            $import = RestApi::startImport(new \WP_REST_Request([
                'archive_id' => $id,
                'expected_parts' => 1,
                'proceed_unsafe' => true,
            ]));
            $this->assertSame(409, $import->get_status());
            $this->assertSame('MUDRAVA_JOB_RUNNING', $import->get_data()['code']);
        } finally {
            @unlink($path);
        }
    }

    public function testMissingArchiveSetReportsSafeDetailedError(): void
    {
        $response = RestApi::startImport(new \WP_REST_Request([
            'archive_id' => 'missing-set',
            'expected_parts' => 2,
            'proceed_unsafe' => true,
        ]));

        $this->assertSame(400, $response->get_status());
        $this->assertSame('MUDRAVA_PART_MISSING', $response->get_data()['code']);
        $this->assertStringStartsWith('MUDRAVA_PART_MISSING', $response->get_data()['message']);
    }

    public function testVerifiedRestorePointStartsGuardedImport(): void
    {
        $incoming = 'incoming-' . bin2hex(random_bytes(4));
        $point = 'point-' . bin2hex(random_bytes(4));
        $incomingPath = $this->createArchive($incoming);
        $pointPath = $this->createArchive($point);
        $GLOBALS['wpdb'] = new \wpdb();
        JobRunner::save([
            'kind' => JobRunner::KIND_EXPORT,
            'purpose' => 'restore_point',
            'state' => 'done',
            'archive_id' => $point,
            'created_at' => time(),
            'updated_at' => time(),
            'lock_until' => 0,
        ]);
        try {
            $response = RestApi::startImport(new \WP_REST_Request([
                'archive_id' => $incoming,
                'expected_parts' => 1,
                'restore_point_archive_id' => $point,
                'proceed_unsafe' => false,
            ]));
            $this->assertSame(200, $response->get_status());
            $this->assertTrue(JobRunner::load()['journal_enabled']);
            $this->assertSame($point, JobRunner::load()['restore_point_archive_id']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $response->get_data()['restore_token']);
        } finally {
            JobRunner::clear();
            RestoreToken::clear();
            foreach ((array) glob((new Paths())->storageDir() . '/import-journal-*.jsonl') as $journal) {
                @unlink($journal);
            }
            @unlink($incomingPath);
            @unlink($pointPath);
        }
    }

    public function testMissingRestorePointArchiveInvalidatesClaim(): void
    {
        $incoming = 'incoming-' . bin2hex(random_bytes(4));
        $point = 'missing-point-' . bin2hex(random_bytes(4));
        $incomingPath = $this->createArchive($incoming);
        JobRunner::save([
            'kind' => JobRunner::KIND_EXPORT,
            'purpose' => 'restore_point',
            'state' => 'done',
            'archive_id' => $point,
            'created_at' => time(),
            'updated_at' => time(),
            'lock_until' => 0,
        ]);
        try {
            $response = RestApi::startImport(new \WP_REST_Request([
                'archive_id' => $incoming,
                'expected_parts' => 1,
                'restore_point_archive_id' => $point,
            ]));
            $this->assertSame(409, $response->get_status());
            $this->assertSame('MUDRAVA_RESTORE_POINT_REQUIRED', $response->get_data()['code']);
        } finally {
            @unlink($incomingPath);
        }
    }

    public function testSuccessfulTickAndChunkErrorAreWrapped(): void
    {
        JobRunner::startExportJob(['site_meta' => ['site_url' => 'https://tick.test']]);
        $tick = RestApi::tick(new \WP_REST_Request());
        $this->assertSame(200, $tick->get_status());
        $this->assertSame(JobRunner::KIND_EXPORT, $tick->get_data()['kind']);

        $chunk = tempnam(sys_get_temp_dir(), 'mudrava-rest-invalid-');
        $this->assertIsString($chunk);
        file_put_contents($chunk, 'x');
        $upload = RestApi::uploadChunk(new \WP_REST_Request([
            'upload_id' => 'bad', 'part' => 1, 'parts' => 1, 'index' => 0, 'total' => 1,
        ], ['file' => ['tmp_name' => $chunk]]));
        $this->assertSame(400, $upload->get_status());
        $this->assertSame('MUDRAVA_UPLOAD_ID_INVALID', $upload->get_data()['code']);
        @unlink($chunk);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDownloadStreamsTheRequestedArchivePart(): void
    {
        $id = 'download-' . bin2hex(random_bytes(4));
        $path = (new Paths())->ensureStorage() . '/' . $id . '.mudrava.part0002';
        file_put_contents($path, 'visible-download-body');
        $method = new \ReflectionMethod(RestApi::class, 'streamArchivePart');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        ob_start();
        $method->invoke(null, $path, (new Paths())->storageDir() . '/' . $id . '.mudrava', 2);
        $this->assertSame('visible-download-body', ob_get_clean());
        unlink($path);
    }

    private function createArchive(string $id): string
    {
        $path = (new Paths())->ensureStorage() . '/' . $id . '.mudrava';
        $stream = fopen($path, 'wb');
        $this->assertIsResource($stream);
        $header = new Header(
            Header::CONTAINER_FORMAT,
            0,
            random_bytes(16),
            'test',
            0,
            0,
            0,
            str_repeat("\0", 16),
            str_repeat("\0", 8)
        );
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), null);
        $writer->writeJson(FrameType::SITE_METADATA, ['site_url' => 'https://source.test']);
        $writer->finalize(['file_count' => 0, 'row_count' => 0]);
        fclose($stream);
        return $path;
    }
}
