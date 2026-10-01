<?php

/**
 * JobRunner contract through the real WordPress filesystem/database adapters.
 * The wpdb fixture is empty, but framing, checkpoints, verification and
 * publication all use the production implementations.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Contract;

use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Crypto\CryptoCapability;
use Mudrava\Migration\Migration\JobRunner;
use Mudrava\Migration\Storage\SplitSetSource;
use Mudrava\Migration\Support\Paths;
use PHPUnit\Framework\TestCase;

final class WordPressJobRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['MUDRAVA_STUB_MULTISITE'] = false;
        $GLOBALS['wpdb'] = new \wpdb();
        $GLOBALS['MUDRAVA_STUB_OPTIONS'] = [];
        $GLOBALS['MUDRAVA_STUB_REWRITE_FLUSHES'] = [];
        JobRunner::clear();
    }

    protected function tearDown(): void
    {
        $job = JobRunner::load();
        if (is_array($job) && !empty($job['archive_id'])) {
            $this->removeSet((string) $job['archive_id']);
        }
        JobRunner::clear();
        parent::tearDown();
    }

    public function testPlainSplitExportVerifiesAndImportsThroughProductionAdapters(): void
    {
        $export = JobRunner::startExportJob([
            'site_meta'   => [
                'site_url' => 'https://source.test',
                'home_url' => 'https://source.test',
                'db_prefix' => 'wp_',
            ],
            'split_bytes' => 1024 * 1024,
            'excludes'    => ['tables' => [], 'dirs' => []],
        ]);
        $archiveId = (string) $export['archive_id'];

        $finished = $this->finishJob(null);
        $this->assertSame('done', $finished['state']);
        $this->assertSame('finalize', $finished['phase']);
        $this->assertTrue($finished['verified']);
        $this->assertFalse($finished['encrypted']);
        $this->assertGreaterThan(0, $finished['logical_bytes']);

        $base = (new Paths())->storageDir() . '/' . $archiveId . '.mudrava';
        $this->assertFileExists($base);
        $source = new SplitSetSource($base);
        $reader = new \Mudrava\Migration\Archive\FrameReader(
            $source->stream(),
            new \Mudrava\Migration\Compression\DeflateCodec(),
            null,
            $source->nextPartProvider()
        );
        $this->assertTrue(($reader->readHeader()->flags & Header::FLAG_SPLIT_SET) !== 0);
        $source->close();

        JobRunner::startImportJob([
            'archive_id'  => $archiveId,
            'url_rewrite' => ['search' => 'https://source.test', 'replace' => 'https://target.test'],
        ]);
        $GLOBALS['MUDRAVA_STUB_OPTIONS'][JobRunner::REWRITE_FLUSH_OPTION] = $archiveId;
        JobRunner::flushRewriteRulesAfterImport();
        $this->assertSame([], $GLOBALS['MUDRAVA_STUB_REWRITE_FLUSHES']);
        $this->assertSame($archiveId, get_option(JobRunner::REWRITE_FLUSH_OPTION));

        $imported = $this->finishJob(null);
        $this->assertSame('done', $imported['state']);
        $this->assertTrue($imported['verified']);
        $this->assertSame(0, $imported['restored_rows']);
        $this->assertSame(0, $imported['restored_files']);
        $this->assertSame(0, $imported['transform_failures']);
        $this->assertSame($archiveId, get_option(JobRunner::REWRITE_FLUSH_OPTION));

        JobRunner::flushRewriteRulesAfterImport();
        JobRunner::flushRewriteRulesAfterImport();
        $this->assertSame([false], $GLOBALS['MUDRAVA_STUB_REWRITE_FLUSHES']);
        $this->assertFalse(get_option(JobRunner::REWRITE_FLUSH_OPTION));

        $this->removeSet($archiveId);
    }

    public function testEncryptedExportAndImportRequireAndAcceptPassword(): void
    {
        $capability = CryptoCapability::detect();
        if (!$capability->available) {
            $this->markTestSkipped('No supported encryption backend');
        }
        $password = 'correct horse battery staple';
        $export = JobRunner::startExportJob([
            'site_meta'    => ['site_url' => 'https://encrypted.test', 'db_prefix' => 'wp_'],
            'encrypted'    => true,
            'password_hint' => 'company vault',
        ]);
        $archiveId = (string) $export['archive_id'];

        $waiting = JobRunner::tick();
        $this->assertSame('password_required', $waiting['note']);
        $finished = $this->finishJob($password);
        $this->assertSame('done', $finished['state']);
        $this->assertTrue($finished['encrypted']);
        $this->assertTrue($finished['verified']);

        JobRunner::startImportJob(['archive_id' => $archiveId]);
        $retry = JobRunner::tick('wrong password');
        $this->assertSame('password_required', $retry['note']);
        $this->assertSame('running', $retry['state']);
        $imported = $this->finishJob($password);
        $this->assertSame('done', $imported['state']);
        $this->assertTrue($imported['verified']);

        $this->removeSet($archiveId);
    }

    public function testExportRemainsRunningUntilArchiveVerificationAndRejectsCorruption(): void
    {
        $export = JobRunner::startExportJob([
            'site_meta' => ['site_url' => 'https://source.test', 'db_prefix' => 'wp_'],
        ]);
        $archiveId = (string) $export['archive_id'];
        $job = JobRunner::tick();
        for ($i = 0; $i < 30 && ($job['phase'] ?? '') !== 'verifying'; $i++) {
            $job = JobRunner::tick();
        }
        $this->assertSame('verifying', $job['phase']);
        $this->assertSame('running', $job['state']);
        $this->assertEmpty($job['verified'] ?? false);

        $base = (new Paths())->storageDir() . '/' . $archiveId . '.mudrava';
        $handle = fopen($base, 'r+b');
        $this->assertIsResource($handle);
        $header = (new \Mudrava\Migration\Archive\FrameReader($handle, new \Mudrava\Migration\Compression\DeflateCodec()))->readHeader();
        fseek($handle, strlen($header->encode()) + 26);
        fwrite($handle, 'X');
        fclose($handle);

        $failed = JobRunner::tick();
        $this->assertSame('failed', $failed['state']);
        $this->assertSame('MUDRAVA_ARCHIVE_CORRUPT', $failed['error']);
        $this->removeSet($archiveId);
    }

    public function testFailedImportCannotFlushRestoredRewriteRules(): void
    {
        JobRunner::save([
            'kind' => JobRunner::KIND_IMPORT,
            'archive_id' => 'failed-fixture',
            'state' => 'failed',
            'verified' => true,
        ]);
        update_option(JobRunner::REWRITE_FLUSH_OPTION, 'failed-fixture', false);

        JobRunner::flushRewriteRulesAfterImport();

        $this->assertSame([], $GLOBALS['MUDRAVA_STUB_REWRITE_FLUSHES']);
        $this->assertFalse(get_option(JobRunner::REWRITE_FLUSH_OPTION));
    }

    public function testCompletedRestorePointEnablesJournaledImport(): void
    {
        $export = JobRunner::startExportJob([
            'site_meta' => ['site_url' => 'https://restore-point.test', 'db_prefix' => 'wp_'],
            'purpose' => 'restore_point',
        ]);
        $archiveId = (string) $export['archive_id'];
        $finished = $this->finishJob(null);
        $this->assertSame('done', $finished['state']);

        $import = JobRunner::startImportJob([
            'archive_id' => $archiveId,
            'restore_point_archive_id' => $archiveId,
        ]);
        $this->assertTrue($import['journal_enabled']);
        $this->assertNotEmpty(glob((new Paths())->storageDir() . '/import-journal-*.jsonl'));

        $finished = $this->finishJob(null);
        $this->assertSame('done', $finished['state']);
        $this->assertSame([], glob((new Paths())->storageDir() . '/import-journal-*.jsonl'));
        $this->removeSet($archiveId);
    }

    /**
     * BUG-02: a guarded import that failed during verification (before any
     * destructive write) leaves a baseline-only journal. The honest retry
     * first erases the failed job with a restore point, so ownership must
     * survive via the receipt clear() records. The retry must succeed.
     */
    public function testPristineJournalFromFailedImportIsReclaimedOnRetry(): void
    {
        // A real archive to import, plus the first restore point.
        $backup = JobRunner::startExportJob([
            'site_meta' => ['site_url' => 'https://retry.test', 'db_prefix' => 'wp_'],
        ]);
        $archiveId = (string) $backup['archive_id'];
        $this->assertSame('done', $this->finishJob(null)['state']);

        $first = JobRunner::startExportJob([
            'site_meta' => ['site_url' => 'https://retry.test', 'db_prefix' => 'wp_'],
            'purpose' => 'restore_point',
        ]);
        $pointId = (string) $first['archive_id'];
        $this->assertSame('done', $this->finishJob(null)['state']);

        $import = JobRunner::startImportJob([
            'archive_id' => $archiveId,
            'restore_point_archive_id' => $pointId,
        ]);
        $this->assertTrue($import['journal_enabled']);
        $this->assertCount(1, glob((new Paths())->storageDir() . '/import-journal-*.jsonl'));

        // Simulate the verify-phase refusal: the job fails terminally with
        // the journal still pristine (no file/table records were appended).
        $failed = JobRunner::load();
        $failed['state'] = 'failed';
        $failed['error'] = 'MUDRAVA_PERMISSION_DENIED';
        JobRunner::save($failed);

        // The honest operator retry: a new restore point erases the failed
        // import job (and records the ownership receipt), then the same
        // archive is imported again.
        JobRunner::clear();
        $this->assertNull(JobRunner::load());
        $receipt = get_option(JobRunner::FAILED_IMPORT_OPTION, null);
        $this->assertIsArray($receipt);
        $this->assertSame($archiveId, $receipt['archive_id']);

        $second = JobRunner::startExportJob([
            'site_meta' => ['site_url' => 'https://retry.test', 'db_prefix' => 'wp_'],
            'purpose' => 'restore_point',
        ]);
        $point2 = (string) $second['archive_id'];
        $this->assertSame('done', $this->finishJob(null)['state']);

        $retry = JobRunner::startImportJob([
            'archive_id' => $archiveId,
            'restore_point_archive_id' => $point2,
        ]);
        $this->assertSame('running', $retry['state']);
        $this->assertTrue($retry['journal_enabled']);
        // Exactly one journal again: the orphan was reclaimed, not stacked.
        $this->assertCount(1, glob((new Paths())->storageDir() . '/import-journal-*.jsonl'));
        // The receipt is consumed by the successful reclaim.
        $this->assertFalse(get_option(JobRunner::FAILED_IMPORT_OPTION, false));

        $finished = $this->finishJob(null);
        $this->assertSame('done', $finished['state']);
        $this->assertSame([], glob((new Paths())->storageDir() . '/import-journal-*.jsonl'));
        $this->removeSet($archiveId);
        $this->removeSet($pointId);
        $this->removeSet($point2);
    }

    /**
     * Ownership may also be proven by the failed import job itself when it
     * is still stored (no restore point erased it in between).
     */
    public function testPristineJournalReclaimedWhenFailedJobStillStored(): void
    {
        $import = JobRunner::startImportJob([
            'archive_id' => 'orphan-archive-2',
            'restore_point_archive_id' => '',
        ]);
        $this->assertFalse($import['journal_enabled']);
        // Force a journaled failed import without a real restore point set.
        $failed = JobRunner::load();
        $failed['journal_enabled'] = true;
        $failed['state'] = 'failed';
        $failed['error'] = 'MUDRAVA_PERMISSION_DENIED';
        JobRunner::save($failed);
        $journal = new \Mudrava\Migration\Rollback\ImportJournal('orphan-archive-2');
        $journal->create(['wp_a']);

        $retry = JobRunner::startImportJob([
            'archive_id' => 'orphan-archive-2',
            'restore_point_archive_id' => '',
        ]);
        $this->assertSame('running', $retry['state']);
        $journal->remove();
    }

    /**
     * A journal that recorded a mutation guards real work: reclaim must
     * refuse with MUDRAVA_RECOVERY_INCOMPLETE and delete nothing.
     */
    public function testJournalWithMutationRecordIsNeverReclaimed(): void
    {
        $journal = new \Mudrava\Migration\Rollback\ImportJournal('orphan-archive-3');
        $journal->create(['wp_a']);
        $journal->recordTable('wp_created');

        $failed = JobRunner::load();
        $this->assertNull($failed);
        JobRunner::save([
            'kind' => JobRunner::KIND_IMPORT,
            'archive_id' => 'orphan-archive-3',
            'state' => 'failed',
            'journal_enabled' => true,
            'error' => 'MUDRAVA_PERMISSION_DENIED',
        ]);

        try {
            JobRunner::startImportJob(['archive_id' => 'orphan-archive-3']);
            $this->fail('mutated journal must block retry');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MUDRAVA_RECOVERY_INCOMPLETE', $e->getMessage());
        }
        // The journal survives untouched for manual recovery.
        $this->assertTrue($journal->exists());
        $property = new \ReflectionProperty(\Mudrava\Migration\Rollback\ImportJournal::class, 'path');
        if (PHP_VERSION_ID < 80100) {
            $property->setAccessible(true);
        }
        $lines = file((string) $property->getValue($journal));
        $this->assertIsArray($lines);
        $this->assertCount(2, $lines);
        $journal->remove();
    }

    /**
     * A failed import that began (but did not finish) a rollback owns a
     * journal that may guard half-restored state: retry must refuse.
     */
    public function testJournalFromInterruptedRollbackIsNeverReclaimed(): void
    {
        JobRunner::save([
            'kind' => JobRunner::KIND_IMPORT,
            'archive_id' => 'orphan-archive-4',
            'state' => 'failed',
            'journal_enabled' => true,
            'error' => 'MUDRAVA_INTERNAL',
            'rollback_state' => 'failed',
        ]);
        $journal = new \Mudrava\Migration\Rollback\ImportJournal('orphan-archive-4');
        $journal->create(['wp_a']);

        try {
            JobRunner::startImportJob(['archive_id' => 'orphan-archive-4']);
            $this->fail('interrupted rollback must block retry');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('rollback', $e->getMessage());
            $this->assertStringContainsString('MUDRAVA_RECOVERY_JOURNAL', $e->getMessage());
        }
        $this->assertTrue($journal->exists());
        $journal->remove();
    }

    /**
     * Without a stored failed job AND without a receipt, ownership is
     * unknown: the journal must survive and the retry must refuse.
     */
    public function testJournalWithUnknownOwnerIsNeverReclaimed(): void
    {
        $journal = new \Mudrava\Migration\Rollback\ImportJournal('orphan-archive-5');
        $journal->create(['wp_a']);

        try {
            JobRunner::startImportJob(['archive_id' => 'orphan-archive-5']);
            $this->fail('unknown-owner journal must block retry');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MUDRAVA_RECOVERY_JOURNAL', $e->getMessage());
            $this->assertStringContainsString('unknown', $e->getMessage());
        }
        $this->assertTrue($journal->exists());
        $journal->remove();
    }

    /**
     * A receipt for a DIFFERENT archive proves nothing about this journal.
     */
    public function testReceiptForOtherArchiveDoesNotProveOwnership(): void
    {
        update_option(JobRunner::FAILED_IMPORT_OPTION, [
            'archive_id' => 'some-other-archive',
            'error' => 'MUDRAVA_PERMISSION_DENIED',
            'rollback_state' => '',
            'failed_at' => time(),
        ], false);
        $journal = new \Mudrava\Migration\Rollback\ImportJournal('orphan-archive-6');
        $journal->create(['wp_a']);

        try {
            JobRunner::startImportJob(['archive_id' => 'orphan-archive-6']);
            $this->fail('foreign receipt must not prove ownership');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MUDRAVA_RECOVERY_JOURNAL', $e->getMessage());
        }
        $this->assertTrue($journal->exists());
        $journal->remove();
        delete_option(JobRunner::FAILED_IMPORT_OPTION);
    }

    /**
     * A running import must never be replaced, journal or not.
     */
    public function testRunningImportStillBlocksNewImport(): void
    {
        JobRunner::save([
            'kind' => JobRunner::KIND_IMPORT,
            'archive_id' => 'running-archive',
            'state' => 'running',
        ]);
        try {
            JobRunner::startImportJob(['archive_id' => 'other-archive']);
            $this->fail('running job must block');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MUDRAVA_JOB_RUNNING', $e->getMessage());
        }
        $this->assertSame('running', JobRunner::load()['state']);
    }

    /**
     * @return array<string,mixed>
     */
    private function finishJob(?string $password): array
    {
        for ($i = 0; $i < 20; $i++) {
            $job = JobRunner::tick($password);
            if (in_array((string) ($job['state'] ?? ''), ['done', 'failed'], true)) {
                return $job;
            }
        }
        $this->fail('job did not finish within 20 bounded ticks');
    }

    private function removeSet(string $archiveId): void
    {
        $base = (new Paths())->storageDir() . '/' . $archiveId . '.mudrava';
        @unlink($base);
        foreach ((array) glob($base . '.part*') as $part) {
            if (is_string($part)) {
                @unlink($part);
            }
        }
    }
}
