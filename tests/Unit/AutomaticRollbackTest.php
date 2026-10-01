<?php

/**
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
use Mudrava\Migration\Migration\JobRunner;
use Mudrava\Migration\Support\Paths;
use PHPUnit\Framework\TestCase;

final class AutomaticRollbackTest extends TestCase
{
    protected function setUp(): void
    {
        JobRunner::clear();
        $GLOBALS['MUDRAVA_STUB_OPTIONS'] = [];
        $GLOBALS['wpdb'] = new \wpdb();
    }

    private function makeRestorePoint(string $id): void
    {
        $path = (new Paths())->ensureStorage() . '/' . $id . '.mudrava';
        $stream = fopen($path, 'wb');
        $header = new Header(Header::CONTAINER_FORMAT, 0, random_bytes(16), 'test', 0, 0, 0,
            str_repeat("\0", 16), str_repeat("\0", 8));
        $writer = new FrameWriter($stream, $header, new DeflateCodec(), null);
        $writer->writeJson(FrameType::SITE_METADATA, ['site_url' => 'https://source.test']);
        $writer->finalize(['file_count' => 0, 'row_count' => 0]);
        fclose($stream);
    }

    /** @return array<string,mixed> */
    private function failedOriginalJob(string $point): array
    {
        return [
            'kind' => JobRunner::KIND_IMPORT,
            'state' => 'running',
            'archive_id' => 'missing-original',
            'restore_point_archive_id' => $point,
            'verified' => true,
            'created_at' => time(),
            'lock_until' => 0,
        ];
    }

    public function testImportErrorRestoresPointAndPreservesOriginalError(): void
    {
        $this->makeRestorePoint('point-rollback');
        JobRunner::save($this->failedOriginalJob('point-rollback'));

        $job = JobRunner::tick();
        $this->assertSame('rolling_back', $job['state']);
        $this->assertSame('running', $job['rollback_state']);
        $this->assertSame('missing-original', $job['failed_archive_id']);
        $this->assertSame('MUDRAVA_PART_MISSING', $job['import_error']);

        for ($i = 0; $i < 10 && $job['state'] === 'rolling_back'; $i++) {
            JobRunner::tickCron();
            $job = JobRunner::load();
        }
        $this->assertSame('failed', $job['state']);
        $this->assertSame('done', $job['rollback_state']);
        $this->assertSame('MUDRAVA_PART_MISSING', $job['error']);

        $mirror = (new Paths())->storageDir() . '/job-mirror.json';
        $this->assertFileExists($mirror);
        $GLOBALS['MUDRAVA_STUB_OPTIONS'][JobRunner::OPTION] = [
            'kind' => JobRunner::KIND_EXPORT,
            'state' => 'done',
            'archive_id' => 'source-site-job',
            'revision' => 999,
        ];
        $property = new \ReflectionProperty(JobRunner::class, 'job');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $property->setValue(null, null);
        $this->assertSame('done', JobRunner::load()['rollback_state']);
    }

    public function testErrorBeforeVerificationDoesNotRewriteSite(): void
    {
        $this->makeRestorePoint('point-no-write');
        $job = $this->failedOriginalJob('point-no-write');
        unset($job['verified']);
        JobRunner::save($job);

        $result = JobRunner::tick();
        $this->assertSame('failed', $result['state']);
        $this->assertArrayNotHasKey('rollback_state', $result);
        $this->assertSame('missing-original', $result['archive_id']);
    }

    public function testCronDoesNotStartAnOrdinaryImport(): void
    {
        $job = $this->failedOriginalJob('point-no-cron');
        $job['updated_at'] = time();
        JobRunner::save($job);
        JobRunner::tickCron();
        $this->assertSame('running', JobRunner::load()['state']);
    }

    public function testCronStartsRecoveryForAStalledVerifiedImport(): void
    {
        $this->makeRestorePoint('point-stalled');
        $job = $this->failedOriginalJob('point-stalled');
        $job['updated_at'] = time() - JobRunner::STALLED_IMPORT_SECONDS - 1;
        JobRunner::save($job);

        JobRunner::tickCron();
        $result = JobRunner::load();
        $this->assertSame('rolling_back', $result['state']);
        $this->assertSame('MUDRAVA_IMPORT_STALLED', $result['import_error']);
        $this->assertSame('missing-original', $result['failed_archive_id']);
    }

    public function testMissingRestorePointReportsRollbackFailure(): void
    {
        JobRunner::save($this->failedOriginalJob('point-missing'));
        $result = JobRunner::tick();
        $this->assertSame('failed', $result['state']);
        $this->assertSame('failed', $result['rollback_state']);
        $this->assertSame('MUDRAVA_PART_MISSING', $result['error']);
        $this->assertStringContainsString('MUDRAVA_PART_MISSING', $result['rollback_error']);
    }

    public function testFailureDuringRollbackKeepsTheOriginalFailure(): void
    {
        $this->makeRestorePoint('point-vanishes');
        JobRunner::save($this->failedOriginalJob('point-vanishes'));
        $started = JobRunner::tick();
        $this->assertSame('rolling_back', $started['state']);

        unlink((new Paths())->storageDir() . '/point-vanishes.mudrava');
        $result = JobRunner::tick();
        $this->assertSame('failed', $result['state']);
        $this->assertSame('failed', $result['rollback_state']);
        $this->assertSame('MUDRAVA_PART_MISSING', $result['error']);
        $this->assertSame('MUDRAVA_PART_MISSING', $result['rollback_error']);
    }

    public function testGuardedRollbackCleansJournalBeforeReportingOriginalFailure(): void
    {
        $this->makeRestorePoint('point-journal');
        $journal = new \Mudrava\Migration\Rollback\ImportJournal('missing-original');
        $journal->create([]);
        $job = $this->failedOriginalJob('point-journal');
        $job['journal_enabled'] = true;
        JobRunner::save($job);

        for ($i = 0; $i < 12; $i++) {
            $result = JobRunner::tick();
            if (($result['state'] ?? '') === 'failed') {
                break;
            }
        }

        $this->assertSame('failed', $result['state']);
        $this->assertSame('done', $result['rollback_state']);
        $this->assertSame('rollback_done', $result['phase']);
        $this->assertSame('MUDRAVA_PART_MISSING', $result['error']);
        $this->assertSame([], glob((new Paths())->storageDir() . '/import-journal-*.jsonl'));
    }
}
