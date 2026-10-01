<?php
/**
 * Stale-job guard regression (E2E run 7).
 *
 * A finished export must not block the next job. A running job remains
 * resumable even between ticks, when its worker lock has expired.
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
use Mudrava\Migration\Crypto\CryptoCapability;
use Mudrava\Migration\Crypto\FrameCipher;
use Mudrava\Migration\Crypto\KeyDerivation;
use Mudrava\Migration\Migration\JobRunner;
use Mudrava\Migration\Support\Paths;
use PHPUnit\Framework\TestCase;

final class StaleJobGuardTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['MUDRAVA_STUB_MULTISITE'] = false;
        JobRunner::clear();
        $GLOBALS['MUDRAVA_STUB_OPTIONS'] = [];
        $GLOBALS['MUDRAVA_STUB_JOB_OPTION_WRITE_FAIL'] = false;
        $GLOBALS['wpdb'] = new \wpdb();
    }

    public function testMultisiteJobsAreRejectedBeforeAnyJobIsSaved(): void
    {
        $GLOBALS['MUDRAVA_STUB_MULTISITE'] = true;
        try {
            foreach ([JobRunner::KIND_EXPORT, JobRunner::KIND_IMPORT] as $kind) {
                try {
                    if ($kind === JobRunner::KIND_EXPORT) {
                        JobRunner::startExportJob(['site_meta' => ['site_url' => 'https://a.test']]);
                    } else {
                        JobRunner::startImportJob(['archive_id' => 'abc']);
                    }
                    $this->fail('multisite job must not start');
                } catch (\RuntimeException $e) {
                    $this->assertSame('MUDRAVA_MULTISITE_UNSUPPORTED', $e->getMessage());
                    $this->assertNull(JobRunner::load());
                }
            }
        } finally {
            $GLOBALS['MUDRAVA_STUB_MULTISITE'] = false;
        }
    }

    public function testRunningJobCannotAdvanceAfterMultisiteIsEnabled(): void
    {
        JobRunner::save($this->job(JobRunner::KIND_IMPORT, 'running', 0));
        $GLOBALS['MUDRAVA_STUB_MULTISITE'] = true;
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('MUDRAVA_MULTISITE_UNSUPPORTED');
            JobRunner::tick();
        } finally {
            $GLOBALS['MUDRAVA_STUB_MULTISITE'] = false;
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testVerificationSkipsTheCurrentlyExecutingPluginDirectory(): void
    {
        $relative = 'wp-content/plugins/current-mudrava';
        define('MUDRAVA_MB_PLUGIN_DIR', rtrim(ABSPATH, '/') . '/' . $relative . '/');
        $id = 'self-verify-' . bin2hex(random_bytes(4));
        $path = (new Paths())->ensureStorage() . '/' . $id . '.mudrava';
        $stream = fopen($path, 'wb');
        $this->assertIsResource($stream);
        $header = new Header(1, 0, random_bytes(16), 'test', 0, 0, 0,
            str_repeat("\0", 16), str_repeat("\0", 8));
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), null);
        $writer->writeJson(FrameType::SITE_METADATA, []);
        $writer->writeJson(FrameType::FILE_METADATA, [
            'path' => $relative . '/marker.php',
            'size' => 4,
            'mode' => 0644,
            'mtime' => 0,
        ]);
        $writer->writeFrame(FrameType::FILE_DATA, 'code');
        $writer->finalize();
        fclose($stream);

        try {
            JobRunner::startImportJob(['archive_id' => $id]);
            $job = JobRunner::tick();
            $this->assertTrue($job['verified']);
            $this->assertSame('verified', $job['phase']);
        } finally {
            JobRunner::clear();
            @unlink($path);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function job(string $kind, string $state, int $lockUntil): array
    {
        return [
            'kind'        => $kind,
            'state'       => $state,
            'archive_id'  => 'test-archive',
            'percent'     => $state === 'done' ? 100 : 0,
            'created_at'  => time() - 60,
            'updated_at'  => time() - 60,
            'lock_until'  => $lockUntil,
        ];
    }

    public function testDoneJobDoesNotBlockNewExport(): void
    {
        JobRunner::save($this->job(JobRunner::KIND_EXPORT, 'done', 0));
        $job = JobRunner::startExportJob(['site_meta' => ['site_url' => 'https://a.test']]);
        $this->assertSame('running', $job['state']);
        $this->assertSame(JobRunner::KIND_EXPORT, $job['kind']);
    }

    public function testFailedJobDoesNotBlockNewImport(): void
    {
        JobRunner::save($this->job(JobRunner::KIND_IMPORT, 'failed', 0));
        $job = JobRunner::startImportJob(['archive_id' => 'abc']);
        $this->assertSame('running', $job['state']);
        $this->assertSame(JobRunner::KIND_IMPORT, $job['kind']);
    }

    public function testRunningJobWithExpiredLockStillBlocks(): void
    {
        JobRunner::save($this->job(JobRunner::KIND_EXPORT, 'running', time() - 10));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MUDRAVA_JOB_RUNNING');
        JobRunner::startExportJob(['site_meta' => ['site_url' => 'https://a.test']]);
    }

    public function testActiveJobStillBlocks(): void
    {
        JobRunner::save($this->job(JobRunner::KIND_EXPORT, 'running', time() + 120));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MUDRAVA_JOB_RUNNING');
        JobRunner::startExportJob(['site_meta' => ['site_url' => 'https://a.test']]);
    }

    public function testRunningMirrorIsNotSilentlyCleared(): void
    {
        // A crashed import leaves a mirror but no option (restore wiped it).
        $paths = new Paths();
        $dir = $paths->ensureStorage();
        $stale = $this->job(JobRunner::KIND_IMPORT, 'running', time() - 999);
        file_put_contents($dir . '/job-mirror.json', (string) json_encode($stale));

        try {
            JobRunner::startExportJob(['site_meta' => ['site_url' => 'https://a.test']]);
            $this->fail('running import mirror must block replacement');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MUDRAVA_JOB_RUNNING', $e->getMessage());
            $this->assertFileExists($dir . '/job-mirror.json');
        }
    }

    public function testAtomicFileLockBlocksParallelJobStart(): void
    {
        $path = (new Paths())->ensureStorage() . '/job.lock';
        $handle = fopen($path, 'c+');
        $this->assertNotFalse($handle);
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('MUDRAVA_JOB_RUNNING');
            JobRunner::startExportJob(['site_meta' => ['site_url' => 'https://a.test']]);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function testTerminalJobTickIsIdempotent(): void
    {
        $done = $this->job(JobRunner::KIND_EXPORT, 'done', 0);
        JobRunner::save($done);
        $saved = JobRunner::load();
        $this->assertSame($saved, JobRunner::tick());
    }

    public function testCronIgnoresMissingAndTerminalJobs(): void
    {
        JobRunner::clear();
        JobRunner::tickCron();
        $this->assertNull(JobRunner::load());

        JobRunner::save($this->job(JobRunner::KIND_EXPORT, 'failed', 0));
        JobRunner::tickCron();
        $this->assertSame('failed', JobRunner::load()['state']);
    }

    public function testLockedTickReturnsWithoutChangingCheckpoint(): void
    {
        $job = $this->job(JobRunner::KIND_EXPORT, 'running', 0);
        $job['checkpoint'] = ['sequence' => 9];
        JobRunner::save($job);

        $this->withExternalLock(function (): void {
            $result = JobRunner::tick();
            $this->assertSame('locked', $result['note']);
            $this->assertSame(9, $result['checkpoint']['sequence']);
        });
    }

    public function testStateMutationsRejectAnExternallyHeldLock(): void
    {
        JobRunner::save($this->job(JobRunner::KIND_EXPORT, 'running', 0));
        $this->withExternalLock(function (): void {
            foreach (['clear', 'cancel', 'import'] as $operation) {
                try {
                    if ($operation === 'clear') {
                        JobRunner::clear();
                    } elseif ($operation === 'cancel') {
                        JobRunner::cancelExport();
                    } else {
                        JobRunner::startImportJob(['archive_id' => 'locked-import']);
                    }
                    $this->fail($operation . ' accepted while the state lock was held');
                } catch (\RuntimeException $e) {
                    $this->assertSame('MUDRAVA_JOB_RUNNING', $e->getMessage());
                }
            }
        });
    }

    public function testCronRecordsRollbackFailureForMissingRecoveryArchive(): void
    {
        $job = $this->job(JobRunner::KIND_IMPORT, 'running', 0);
        $job['verified'] = true;
        $job['restore_point_archive_id'] = 'missing-recovery-archive';
        $job['updated_at'] = time() - JobRunner::STALLED_IMPORT_SECONDS - 1;
        JobRunner::save($job);

        JobRunner::tickCron();
        $saved = JobRunner::load();
        $this->assertSame('failed', $saved['state']);
        $this->assertSame('MUDRAVA_IMPORT_STALLED', $saved['error']);
        $this->assertSame('failed', $saved['rollback_state']);
        $this->assertStringStartsWith('MUDRAVA_PART_MISSING', $saved['rollback_error']);
    }

    public function testRestorePointMustBeCompletedMatchingExport(): void
    {
        $previous = $this->job(JobRunner::KIND_EXPORT, 'done', 0);
        $previous['purpose'] = 'backup';
        $previous['archive_id'] = 'point-id';
        JobRunner::save($previous);

        $this->expectExceptionMessage('MUDRAVA_RESTORE_POINT_REQUIRED');
        JobRunner::startImportJob([
            'archive_id' => 'incoming',
            'restore_point_archive_id' => 'point-id',
        ]);
    }

    public function testGuardedImportRejectsDatabaseTableInventoryFailure(): void
    {
        $previous = $this->job(JobRunner::KIND_EXPORT, 'done', 0);
        $previous['purpose'] = 'restore_point';
        $previous['archive_id'] = 'point-id';
        JobRunner::save($previous);
        $db = new \wpdb();
        $db->colCallback = static function () {
            return null;
        };
        $GLOBALS['wpdb'] = $db;

        $this->expectExceptionMessage('MUDRAVA_DB_ERROR');
        JobRunner::startImportJob([
            'archive_id' => 'incoming',
            'restore_point_archive_id' => 'point-id',
        ]);
    }

    public function testJobMirrorRejectsUnencodableStateAndIgnoresEmptyMirror(): void
    {
        $recursive = $this->job(JobRunner::KIND_EXPORT, 'running', 0);
        $recursive['recursive'] = &$recursive;
        try {
            JobRunner::save($recursive);
            $this->fail('recursive state was encoded');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot encode job mirror', $e->getMessage());
        }
        unset($recursive['recursive']);

        $option = $this->job(JobRunner::KIND_EXPORT, 'done', 0);
        $GLOBALS['MUDRAVA_STUB_OPTIONS'][JobRunner::OPTION] = $option;
        file_put_contents((new Paths())->storageDir() . '/job-mirror.json', '');
        $this->simulateNewRequest();
        $this->assertSame($option, JobRunner::load());
    }

    public function testCancelExportRemovesOnlyTheRunningExport(): void
    {
        JobRunner::startExportJob(['site_meta' => ['site_url' => 'https://a.test']]);
        $this->simulateNewRequest();
        JobRunner::cancelExport();
        $this->assertNull(JobRunner::load());
    }

    /** @dataProvider nonExportJobs */
    public function testCancelExportPreservesImportAndRollback(string $state): void
    {
        $job = $this->job(JobRunner::KIND_IMPORT, $state, 0);
        JobRunner::save($job);
        $this->simulateNewRequest();
        try {
            JobRunner::cancelExport();
            $this->fail('an import checkpoint must not be cancelled');
        } catch (\RuntimeException $e) {
            $this->assertSame('MUDRAVA_JOB_NOT_CANCELLABLE', $e->getMessage());
            $saved = JobRunner::load();
            $this->assertSame($state, $saved['state']);
            $this->assertSame($job['archive_id'], $saved['archive_id']);
        }
    }

    /** @return array<string,array{string}> */
    public static function nonExportJobs(): array
    {
        return ['import' => ['running'], 'rollback' => ['rolling_back']];
    }

    public function testEncryptedExportWaitsForPasswordAfterPageReload(): void
    {
        $job = JobRunner::startExportJob([
            'site_meta' => ['site_url' => 'https://a.test'],
            'encrypted' => true,
        ]);
        $this->simulateNewRequest();
        $result = JobRunner::tick();
        $this->assertSame('running', $result['state']);
        $this->assertSame('password_required', $result['note']);
        $this->assertTrue($result['encrypted']);
        $this->assertFileDoesNotExist((new Paths())->ensureStorage() . '/' . $job['archive_id'] . '.mudrava');
    }

    public function testMissingPasswordAfterVerificationKeepsImportResumable(): void
    {
        if (!CryptoCapability::supports(CryptoCapability::STACK_OPENSSL)) {
            $this->markTestSkipped('OpenSSL AES-GCM is unavailable');
        }
        $salt = random_bytes(16);
        $nonce = random_bytes(8);
        $header = new Header(
            Header::CONTAINER_FORMAT,
            Header::FLAG_ENCRYPTED,
            random_bytes(16),
            'test',
            KeyDerivation::KDF_PBKDF2,
            1000,
            0,
            $salt,
            $nonce
        );
        $cipher = new FrameCipher(
            KeyDerivation::derive('correct', KeyDerivation::KDF_PBKDF2, 1000, 0, $salt),
            $nonce,
            CryptoCapability::STACK_OPENSSL
        );
        $id = 'encrypted-resume-probe';
        $path = (new Paths())->ensureStorage() . '/' . $id . '.mudrava';
        $stream = fopen($path, 'wb');
        $this->assertIsResource($stream);
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), $cipher);
        $writer->writeJson(FrameType::SITE_METADATA, ['site_url' => 'https://example.test']);
        $writer->finalize([]);
        fclose($stream);

        $job = JobRunner::startImportJob(['archive_id' => $id]);
        $job['verified'] = true;
        $job['phase'] = 'verified';
        JobRunner::save($job);
        $this->simulateNewRequest();
        $result = JobRunner::tick();
        $this->assertSame('running', $result['state']);
        $this->assertSame('password_required', $result['note']);
        $this->assertTrue($result['verified']);
        $this->assertSame('verified', $result['phase']);
        $this->assertArrayNotHasKey('rollback_state', $result);
        unlink($path);
    }

    private function simulateNewRequest(): void
    {
        $property = new \ReflectionProperty(JobRunner::class, 'job');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $property->setValue(null, null);
    }

    private function withExternalLock(callable $callback): void
    {
        $path = (new Paths())->ensureStorage() . '/job.lock';
        $handle = fopen($path, 'c+');
        $this->assertIsResource($handle);
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        try {
            $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function testNewerPrivateMirrorWinsOverStaleDatabaseCheckpoint(): void
    {
        $job = $this->job(JobRunner::KIND_IMPORT, 'running', 0);
        $job['checkpoint'] = ['sequence' => 1];
        JobRunner::save($job);
        $oldOption = $GLOBALS['MUDRAVA_STUB_OPTIONS'][JobRunner::OPTION];
        $job['checkpoint'] = ['sequence' => 2];
        JobRunner::save($job);
        $GLOBALS['MUDRAVA_STUB_OPTIONS'][JobRunner::OPTION] = $oldOption;

        $this->simulateNewRequest();
        $loaded = JobRunner::load();
        $this->assertSame(2, $loaded['checkpoint']['sequence']);
        $this->assertGreaterThan($oldOption['revision'], $loaded['revision']);
    }

    public function testMirrorSurvivesDatabaseWriteFailureAndSourceJobReplacement(): void
    {
        $job = $this->job(JobRunner::KIND_IMPORT, 'running', 0);
        $job['checkpoint'] = ['sequence' => 3];
        $GLOBALS['MUDRAVA_STUB_JOB_OPTION_WRITE_FAIL'] = true;
        try {
            JobRunner::save($job);
        } finally {
            $GLOBALS['MUDRAVA_STUB_JOB_OPTION_WRITE_FAIL'] = false;
        }
        $this->simulateNewRequest();
        $this->assertSame(3, JobRunner::load()['checkpoint']['sequence']);

        // Restoring wp_options can bring in an unrelated source-site job,
        // even one with a superficially larger revision number.
        $GLOBALS['MUDRAVA_STUB_OPTIONS'][JobRunner::OPTION] = [
            'kind' => JobRunner::KIND_EXPORT,
            'state' => 'done',
            'archive_id' => 'source-site',
            'revision' => 100,
        ];
        $this->simulateNewRequest();
        $this->assertSame('test-archive', JobRunner::load()['archive_id']);
    }

    public function testExportCheckpointSurvivesDatabaseWriteFailure(): void
    {
        $job = $this->job(JobRunner::KIND_EXPORT, 'running', 0);
        $job['checkpoint'] = ['sequence' => 7];
        $GLOBALS['MUDRAVA_STUB_JOB_OPTION_WRITE_FAIL'] = true;
        try {
            JobRunner::save($job);
        } finally {
            $GLOBALS['MUDRAVA_STUB_JOB_OPTION_WRITE_FAIL'] = false;
        }
        $this->simulateNewRequest();
        $this->assertSame(7, JobRunner::load()['checkpoint']['sequence']);
    }

    public function testMirrorWriteIsAtomicAndPrivate(): void
    {
        JobRunner::save($this->job(JobRunner::KIND_IMPORT, 'running', 0));
        $dir = (new Paths())->storageDir();
        $path = $dir . '/job-mirror.json';
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertIsArray(json_decode((string) file_get_contents($path), true));
        $this->assertSame([], glob($dir . '/.job-*'));
    }

    /**
     * BUG-05 regression (validation B.4, disk-full tmpfs): when the mirror
     * cannot be written but the database still accepts the option, save()
     * must fall back to the option store instead of throwing. Otherwise a
     * tick's terminal "failed" state is lost, the job stays running
     * forever, and the mirror error masks the real DISK_FULL code.
     */
    public function testSaveFallsBackToOptionStoreWhenMirrorCannotBeWritten(): void
    {
        $dir = (new Paths())->storageDir();
        $mirror = $dir . '/job-mirror.json';
        // A directory can never receive rename(file -> dir): the mirror
        // write fails deterministically while the storage dir stays usable.
        @unlink($mirror);
        $this->assertTrue(mkdir($mirror));
        try {
            $job = $this->job(JobRunner::KIND_EXPORT, 'running', 0);
            $job['checkpoint'] = ['sequence' => 9];
            JobRunner::save($job);
            $this->simulateNewRequest();
            $loaded = JobRunner::load();
            $this->assertSame('running', $loaded['state']);
            $this->assertSame(9, $loaded['checkpoint']['sequence']);
        } finally {
            @rmdir($mirror);
        }
    }

    public function testSaveThrowsOnlyWhenBothStoresRejectTheJob(): void
    {
        $dir = (new Paths())->storageDir();
        $mirror = $dir . '/job-mirror.json';
        @unlink($mirror);
        $this->assertTrue(mkdir($mirror));
        $GLOBALS['MUDRAVA_STUB_JOB_OPTION_WRITE_FAIL'] = true;
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('MUDRAVA_PERMISSION_DENIED');
            JobRunner::save($this->job(JobRunner::KIND_EXPORT, 'running', 0));
        } finally {
            $GLOBALS['MUDRAVA_STUB_JOB_OPTION_WRITE_FAIL'] = false;
            @rmdir($mirror);
        }
    }

    /**
     * End-to-end shape of B.4: a running export whose next step fails hard
     * (here: missing part, standing in for the full-disk write failure)
     * with an unwritable mirror must still reach a durable failed state,
     * not escape tick() with the mirror error.
     */
    public function testTickPersistsTerminalFailureWhenMirrorIsUnwritable(): void
    {
        $job = JobRunner::startExportJob(['site_meta' => ['site_url' => 'https://a.test']]);
        // A checkpoint whose part file does not exist: the resume step
        // fails immediately with a deterministic error.
        $job['checkpoint'] = [
            'state'           => 'database',
            'stage'           => 'db_stream',
            'sequence'        => 1,
            'logical_bytes'   => '10',
            'physical_offset' => '0',
            'part'            => 1,
            'root_hash'       => '',
            'cursor'          => (object) ['table_index' => 0],
        ];
        JobRunner::save($job);

        $dir = (new Paths())->storageDir();
        $mirror = $dir . '/job-mirror.json';
        @unlink($mirror);
        $this->assertTrue(mkdir($mirror));
        try {
            $this->simulateNewRequest();
            $result = JobRunner::tick();
            $this->assertSame('failed', $result['state']);
            $this->assertStringContainsString('MUDRAVA_', (string) $result['error']);
            $this->simulateNewRequest();
            $this->assertSame('failed', JobRunner::load()['state']);
        } finally {
            @rmdir($mirror);
        }
    }
}
