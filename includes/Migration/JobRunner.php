<?php

/**
 * Durable job runner. One job at a time, backed by a single option so the
 * state survives PHP timeouts, worker restarts and shared-host request
 * limits. Every tick performs ONE bounded step and persists before
 * returning; the process may die at any point and resume exactly.
 *
 * Ticks are driven by the browser (REST) for responsiveness and by WP-Cron
 * as a fallback for exports and recovery after a failed import. Ordinary
 * imports are browser-driven so destructive work stays under operator view.
 *
 * Secrets policy: an encryption password is supplied per tick over the
 * authenticated REST channel, used in memory, and NEVER persisted. The
 * derived key is therefore re-derived per tick for encrypted jobs.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Migration;

use Mudrava\Migration\Integration\ProtectedDirectories;
use Mudrava\Migration\Archive\Checkpoint;
use Mudrava\Migration\Archive\FrameReader;
use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Compression\DeflateCodec;
use Mudrava\Migration\Crypto\CryptoCapability;
use Mudrava\Migration\Crypto\FrameCipher;
use Mudrava\Migration\Crypto\KeyDerivation;
use Mudrava\Migration\Database\WpDatabaseSource;
use Mudrava\Migration\Database\WpDatabaseTarget;
use Mudrava\Migration\Filesystem\WpFileInventory;
use Mudrava\Migration\Filesystem\WpFileTarget;
use Mudrava\Migration\Plugin;
use Mudrava\Migration\Rollback\ImportJournal;
use Mudrava\Migration\Storage\SplitSetSource;
use Mudrava\Migration\Support\Paths;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Protected binary archives require seekable native streams and atomic file operations.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are converted to safe REST codes.

final class JobRunner
{
    public const OPTION = 'mudrava_job';
    public const REWRITE_FLUSH_OPTION = 'mudrava_rewrite_flush_pending';
    /**
     * Ownership evidence for an orphaned recovery journal. A guarded import
     * that reaches a terminal failure keeps its facts here when its job is
     * erased (a restore point or a new job replaces it), so a later retry of
     * the same archive can prove which job owned the leftover journal.
     */
    public const FAILED_IMPORT_OPTION = 'mudrava_last_failed_import';
    public const LOCK_TTL = 120;
    public const STALLED_IMPORT_SECONDS = 1800;

    /** Wall-clock budget for one export tick, seconds. Ticks stop at a frame
     *  boundary when it elapses, so a request never exceeds host limits. */
    public const TICK_BUDGET = 4.0;

    public const KIND_EXPORT = 'export';
    public const KIND_IMPORT = 'import';

    /** @var array<string,mixed>|null */
    private static $job = null;

    /** @var resource|null Atomic cross-request lock for job state and ticks. */
    private static $lockHandle = null;

    /** Heartbeat option: the last time cron actually woke up. */
    public const CRON_BEAT = 'mudrava_cron_beat';

    public static function tickCron(): void
    {
        // Proof of life: cron fired at this moment. The UI tells the
        // operator honestly whether background progress is real here,
        // instead of promising it on hosts where cron never wakes.
        update_option(self::CRON_BEAT, (string) time(), false);
        $job = self::load();
        if ($job === null || ($job['state'] ?? '') === 'done' || ($job['state'] ?? '') === 'failed') {
            return;
        }
        if (
            ($job['kind'] ?? '') === self::KIND_IMPORT
            && ($job['state'] ?? '') === 'running'
            && !empty($job['verified'])
            && !empty($job['restore_point_archive_id'])
            && time() - (int) ($job['updated_at'] ?? $job['created_at'] ?? time()) >= self::STALLED_IMPORT_SECONDS
        ) {
            self::recoverStalledImport();
            return;
        }
        // Ordinary imports wait for the operator. A rollback uses the local,
        // unencrypted restore point, so cron may finish recovery if the
        // browser disappears after an import error.
        if (
            ($job['kind'] ?? '') !== self::KIND_EXPORT
            && ($job['state'] ?? '') !== 'rolling_back'
        ) {
            return;
        }
        self::tick();
    }

    /**
     * Refresh permalink rules on the first request after a verified import.
     * wp_loaded runs after restored plugins (including language plugins) have
     * registered their rewrite rules. The import request itself has stale
     * in-memory plugin state, so flushing there can save the wrong rules.
     */
    public static function flushRewriteRulesAfterImport(): void
    {
        $archiveId = get_option(self::REWRITE_FLUSH_OPTION, false);
        if (!is_string($archiveId) || $archiveId === '') {
            return;
        }
        $job = self::load();
        if (
            !is_array($job)
            || ($job['kind'] ?? '') !== self::KIND_IMPORT
            || ($job['archive_id'] ?? '') !== $archiveId
            || ($job['state'] ?? '') === 'failed'
        ) {
            delete_option(self::REWRITE_FLUSH_OPTION);
            return;
        }
        if (($job['state'] ?? '') !== 'done' || empty($job['verified'])) {
            return;
        }
        flush_rewrite_rules(false);
        delete_option(self::REWRITE_FLUSH_OPTION);
    }

    /** WP-Cron recovers a verified import abandoned after a worker dies. */
    private static function recoverStalledImport(): void
    {
        if (!self::acquireLock()) {
            return;
        }
        try {
            self::$job = null;
            $job = self::load();
            if (
                $job === null || ($job['kind'] ?? '') !== self::KIND_IMPORT
                || ($job['state'] ?? '') !== 'running' || empty($job['verified'])
                || empty($job['restore_point_archive_id'])
                || time() - (int) ($job['updated_at'] ?? $job['created_at'] ?? time()) < self::STALLED_IMPORT_SECONDS
            ) {
                return;
            }
            try {
                self::beginRollback($job, 'MUDRAVA_IMPORT_STALLED');
            } catch (\Throwable $e) {
                $job['state'] = 'failed';
                $job['error'] = 'MUDRAVA_IMPORT_STALLED';
                $job['rollback_state'] = 'failed';
                $job['rollback_error'] = self::safeError($e);
            }
            self::save($job);
        } finally {
            self::releaseFileLock();
        }
        self::tick();
    }

    /**
     * Run one bounded step. Returns the persisted job array.
     *
     * @param string|null $password encryption password (encrypted jobs only)
     * @param string|null $expectedExportId optional add-on ownership guard
     * @return array<string,mixed>
     */
    public static function tick(?string $password = null, ?string $expectedExportId = null): array
    {
        $job = self::load();
        if ($job === null) {
            throw new \RuntimeException('MUDRAVA_JOB_NOT_FOUND');
        }
        self::assertExpectedExport($job, $expectedExportId);
        if (in_array((string) ($job['state'] ?? ''), ['done', 'failed'], true)) {
            return $job;
        }
        if (is_multisite()) {
            throw new \RuntimeException('MUDRAVA_MULTISITE_UNSUPPORTED');
        }
        if (!self::acquireLock()) {
            $job['note'] = 'locked';
            return $job;
        }
        try {
            // Another request may have saved a newer checkpoint while this
            // one waited for the file lock. Never use its stale snapshot.
            self::$job = null;
            $job = self::load();
            if ($job === null) {
                throw new \RuntimeException('MUDRAVA_JOB_NOT_FOUND');
            }
            self::assertExpectedExport($job, $expectedExportId);
            if (in_array((string) ($job['state'] ?? ''), ['done', 'failed'], true)) {
                return $job;
            }
            $job['lock_until'] = time() + self::LOCK_TTL;
            $job['updated_at'] = time();
            self::save($job);
            try {
                unset($job['note']);
                if ($job['kind'] === self::KIND_EXPORT) {
                    self::stepExport($job, $password);
                } else {
                    self::stepImport($job, $password);
                }
            } catch (\Throwable $e) {
                $error = self::safeError($e);
                if ($error === 'MUDRAVA_WRONG_PASSWORD') {
                    // A missing or incorrect credential is retryable. In
                    // particular, it must never start a destructive rollback.
                    $job['note'] = 'password_required';
                } elseif (
                    $job['kind'] === self::KIND_IMPORT
                    && !empty($job['verified'])
                    && empty($job['rollback_state'])
                    && !empty($job['restore_point_archive_id'])
                ) {
                    try {
                        self::beginRollback($job, $error);
                    } catch (\Throwable $rollbackError) {
                        $job['state'] = 'failed';
                        $job['error'] = $error;
                        $job['rollback_state'] = 'failed';
                        $job['rollback_error'] = self::safeError($rollbackError);
                    }
                } else {
                    $job['state'] = 'failed';
                    $job['error'] = (string) ($job['import_error'] ?? $error);
                    if (($job['rollback_state'] ?? '') === 'running') {
                        $job['rollback_state'] = 'failed';
                        $job['rollback_error'] = $error;
                    }
                }
                if ($error !== 'MUDRAVA_WRONG_PASSWORD') {
                    Plugin::instance()->logger()->error('job_failed', [
                        'kind'   => $job['kind'],
                        'error'  => $error,
                        'exception_class' => get_class($e),
                    ]);
                }
            }
            self::save($job);
            $job['lock_until'] = 0;
            self::save($job);
            // Once an import reaches a terminal state, its scoped token is dead.
            if (
                $job['kind'] === self::KIND_IMPORT
                && (($job['state'] ?? '') === 'done' || ($job['state'] ?? '') === 'failed')
            ) {
                \Mudrava\Migration\Http\RestoreToken::clear();
            }
            return $job;
        } finally {
            self::releaseFileLock();
        }
    }

    /**
     * Guard repeated automation ticks against a replaced job, under the lock.
     * @param array<string,mixed> $job
     */
    private static function assertExpectedExport(array $job, ?string $archiveId): void
    {
        if (
            $archiveId !== null
            && (($job['kind'] ?? '') !== self::KIND_EXPORT || ($job['archive_id'] ?? '') !== $archiveId)
        ) {
            throw new \RuntimeException('MUDRAVA_JOB_CHANGED');
        }
    }

    /**
     * @param array<string,mixed> $job
     */
    private static function stepExport(array &$job, ?string $password): void
    {
        $paths = new Paths();
        $dir = $paths->ensureStorage();
        $base = $dir . '/' . $job['archive_id'] . '.mudrava';
        if (($job['phase'] ?? '') === 'verifying') {
            self::verifyExport($job, $base, $password);
            return;
        }
        $split = isset($job['split_bytes']) ? (int) $job['split_bytes'] : null;

        $checkpoint = isset($job['checkpoint']) && is_array($job['checkpoint'])
            ? $job['checkpoint']
            : null;

        $cipherFactory = static function (Header $header) use ($password): ?FrameCipher {
            return self::cipherFromHeader($header, $password);
        };

        $header = $checkpoint === null ? self::freshHeader($job, $password) : null;
        if ($header !== null) {
            $job['encrypted'] = $header->isEncrypted();
        }
        $result = ExportTick::run(
            $base,
            $checkpoint,
            static fn (array $tables = []): WpDatabaseSource => new WpDatabaseSource(null, $tables),
            static fn (array $dirs = []): WpFileInventory => new WpFileInventory(null, $dirs),
            is_array($job['site_meta'] ?? null) ? $job['site_meta'] : [],
            $header,
            $cipherFactory,
            $split,
            microtime(true) + self::TICK_BUDGET,
            is_array($job['excludes'] ?? null) ? $job['excludes'] : []
        );

        $job['checkpoint'] = $result['checkpoint'];
        $job['current_part'] = $result['current_part'];
        $job['percent'] = $result['percent'];
        $job['phase'] = $result['state'] === Exporter::STATE_DONE ? 'verifying' : $result['state'];
        $job['logical_bytes'] = (int) $result['logical_bytes'];
        if ($result['state'] === Exporter::STATE_DONE) {
            $job['percent'] = 99;
        }
    }

    /**
     * Verify the completed export in bounded ticks before reporting success.
     * @param array<string,mixed> $job
     */
    private static function verifyExport(array &$job, string $base, ?string $password): void
    {
        $identity = SplitSetSource::identity($base);
        if (isset($job['archive_identity']) && !hash_equals((string) $job['archive_identity'], $identity)) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED');
        }
        $job['archive_identity'] = $identity;
        $source = new SplitSetSource($base);
        try {
            $reader = new FrameReader($source->stream(), new DeflateCodec(), null, $source->nextPartProvider());
            $header = $reader->readHeader();
            $cipher = self::cipherFromHeader($header, $password);
            if ($cipher !== null) {
                $source->close();
                $source = new SplitSetSource($base);
                $reader = new FrameReader($source->stream(), new DeflateCodec(), $cipher, $source->nextPartProvider());
            }
            $checkpoint = isset($job['verify_checkpoint']) && is_array($job['verify_checkpoint'])
                ? $job['verify_checkpoint'] : null;
            $verifier = new ArchiveVerifier($reader, $checkpoint);
            if ($verifier->step(microtime(true) + self::TICK_BUDGET)) {
                $job['verified'] = true;
                $job['state'] = 'done';
                $job['phase'] = 'finalize';
                $job['percent'] = 100;
                unset($job['verify_checkpoint']);
            } else {
                $job['verify_checkpoint'] = $verifier->checkpoint();
            }
            if (!hash_equals($identity, SplitSetSource::identity($base))) {
                throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED');
            }
        } finally {
            $source->close();
        }
    }

    /**
     * Build the archive header for a fresh export. Encryption parameters are
     * recorded here and verified at restore - never silently downgraded.
     *
     * @param array<string,mixed> $job
     */
    private static function freshHeader(array $job, ?string $password): Header
    {
        if (!empty($job['encrypted']) && ($password === null || $password === '')) {
            throw new \RuntimeException('MUDRAVA_WRONG_PASSWORD: encrypted export requires a password');
        }
        $encrypted = $password !== null && $password !== '';
        $cap = CryptoCapability::detect();
        if ($encrypted && !$cap->available) {
            throw new \RuntimeException('MUDRAVA_CRYPTO_UNAVAILABLE: encryption requested but no crypto stack');
        }
        $kdf = 0;
        $ops = 0;
        $mem = 0;
        $salt = str_repeat("\0", 16);
        if ($encrypted) {
            $params = KeyDerivation::parameters($cap);
            $kdf = (int) $params['kdf'];
            $ops = (int) $params['opslimit'];
            $mem = (int) $params['memlimit'];
            $salt = (string) $params['salt'];
        }
        $flags = 0;
        if ($encrypted) {
            $flags |= Header::FLAG_ENCRYPTED;
        }
        if ((int) ($job['split_bytes'] ?? 0) > 0) {
            // Honest self-description: this header was produced in split
            // mode, so a lone part 1 file can be recognised (client-side
            // and in support logs) as "part 1 of a set", never mistaken
            // for a whole archive.
            $flags |= Header::FLAG_SPLIT_SET;
        }
        return new Header(
            Header::CONTAINER_FORMAT,
            $flags,
            random_bytes(16),
            MUDRAVA_MB_VERSION,
            $kdf,
            $ops,
            $mem,
            $salt,
            random_bytes(8),
            // Plaintext by design (spec §9): the UI warns that anyone holding
            // the archive can read it. Never the password itself.
            (string) ($job['password_hint'] ?? '')
        );
    }

    /**
     * @param array<string,mixed> $job
     */
    private static function stepImport(array &$job, ?string $password): void
    {
        if (($job['phase'] ?? '') === 'rollback_cleaning') {
            $journal = new ImportJournal((string) $job['failed_archive_id']);
            $result = $journal->cleanupStep(
                ABSPATH,
                $GLOBALS['wpdb'],
                (string) ($job['cleanup_phase'] ?? 'validate'),
                (int) ($job['cleanup_offset'] ?? 0),
                microtime(true) + self::TICK_BUDGET
            );
            $job['cleanup_phase'] = $result['phase'];
            $job['cleanup_offset'] = $result['offset'];
            if ($result['done']) {
                $journal->remove();
                $job['state'] = 'failed';
                $job['rollback_state'] = 'done';
                $job['error'] = (string) ($job['import_error'] ?? 'MUDRAVA_INTERNAL');
                $job['phase'] = 'rollback_done';
                $job['percent'] = 100;
                unset($job['cleanup_phase'], $job['cleanup_offset']);
            }
            return;
        }
        $paths = new Paths();
        $base = $paths->storageDir() . '/' . $job['archive_id'] . '.mudrava';
        $codec = new DeflateCodec();

        $identity = SplitSetSource::identity($base);
        if (isset($job['archive_identity']) && !hash_equals((string) $job['archive_identity'], $identity)) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED');
        }
        $job['archive_identity'] = $identity;

        $source = new SplitSetSource($base);
        $stream = $source->stream();
        $reader = new FrameReader($stream, $codec, null, $source->nextPartProvider());
        $header = $reader->readHeader();
        $job['encrypted'] = $header->isEncrypted();
        $cipher = self::cipherFromHeader($header, $password);
        if ($cipher !== null) {
            // Rebuild the reader with the cipher now that we know the stack.
            $source->close();
            $source = new SplitSetSource($base);
            $reader = new FrameReader($source->stream(), $codec, $cipher, $source->nextPartProvider());
        }

        if (empty($job['verified'])) {
            $checkpoint = isset($job['verify_checkpoint']) && is_array($job['verify_checkpoint'])
                ? $job['verify_checkpoint']
                : null;
            $fileTarget = new WpFileTarget();
            $verifier = new ArchiveVerifier(
                $reader,
                $checkpoint,
                static function (string $path, bool $symlink) use ($fileTarget): void {
                    // Importer deliberately skips the currently executing
                    // plugin even for historical archives that contain it.
                    if (ProtectedDirectories::contains($path, $fileTarget->root())) {
                        return;
                    }
                    $fileTarget->assertPathWritable($path, $symlink);
                }
            );
            $verified = $verifier->step(microtime(true) + self::TICK_BUDGET);
            $job['logical_bytes'] = $reader->logicalBytes();
            if ($verified) {
                $job['verified'] = true;
                $job['archive_logical_bytes'] = $reader->logicalBytes();
                $job['percent'] = 50;
                unset($job['verify_checkpoint']);
                $job['phase'] = 'verified';
            } else {
                $job['verify_checkpoint'] = $verifier->checkpoint();
                $job['phase'] = 'verifying';
            }
            if (!hash_equals($identity, SplitSetSource::identity($base))) {
                throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED');
            }
            $source->close();
            return;
        }

        $resume = isset($job['checkpoint']) && is_array($job['checkpoint'])
            ? Checkpoint::fromArray($job['checkpoint'])
            : null;

        $journal = !empty($job['journal_enabled'])
            ? new ImportJournal((string) ($job['failed_archive_id'] ?? $job['archive_id']))
            : null;
        $writingJournal = ($job['rollback_state'] ?? '') !== 'running' ? $journal : null;
        $db = new WpDatabaseTarget(null, $writingJournal);
        $files = new WpFileTarget(null, $writingJournal);
        $rewrite = is_array($job['url_rewrite'] ?? null) ? $job['url_rewrite'] : [];
        // When the operator left the pair empty, the Importer derives the
        // rewrite from the archive's SITE_METADATA: source site -> this site.
        if (empty($rewrite['search']) || empty($rewrite['replace'])) {
            $rewrite['target'] = home_url();
        }
        $rewrite['target_prefix'] = (string) $GLOBALS['wpdb']->prefix;
        $importer = new Importer($reader, $db, $files, $rewrite, $resume);
        $state = $importer->step(microtime(true) + self::TICK_BUDGET);
        if (!hash_equals($identity, SplitSetSource::identity($base))) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED');
        }

        $job['restored_rows']  = $importer->restoredRows();
        $job['restored_files'] = $importer->restoredFiles();
        $job['transform_failures'] = $importer->transformFailures();
        $job['phase'] = $importer->phase();
        $processed = $reader->logicalBytes();

        if ($state === Importer::STATE_DONE) {
            if (($job['rollback_state'] ?? '') === 'running') {
                if ($journal !== null) {
                    $job['phase'] = 'rollback_cleaning';
                    $job['cleanup_phase'] = 'validate';
                    $job['cleanup_offset'] = 0;
                    $source->close();
                    return;
                }
                $job['state'] = 'failed';
                $job['rollback_state'] = 'done';
                $job['error'] = (string) ($job['import_error'] ?? 'MUDRAVA_INTERNAL');
                $job['phase'] = 'rollback_done';
            } else {
                if ($journal !== null) {
                    $journal->remove();
                }
                // The next request loads the restored plugin/language state
                // before rebuilding permalink rules.
                update_option(self::REWRITE_FLUSH_OPTION, (string) $job['archive_id'], false);
                $job['state'] = 'done';
            }
            $job['percent'] = 100;
            $source->close();
        } else {
            // Persist the physical boundary so the next tick seeks (O(1))
            // straight to the unit it must reprocess.
            $job['checkpoint'] = $importer->checkpointData();
            $processed = (int) ($job['checkpoint']['logical_bytes'] ?? $processed);
        }
        $total = (int) ($job['archive_logical_bytes'] ?? 0);
        $job['logical_bytes'] = $total + $processed;
        if ($state !== Importer::STATE_DONE && $total > 0) {
            $job['percent'] = min(99, 50 + (int) floor(49 * min($processed, $total) / $total));
        }
    }

    /**
     * The completed destination export is a recovery archive. Preserve the
     * original error, then verify and import that archive in later browser
     * ticks under the same scoped restore token. A rollback can itself fail;
     * that failure is reported separately and never hides the first error.
     *
     * @param array<string,mixed> $job
     */
    private static function beginRollback(array &$job, string $error): void
    {
        $pointId = (string) $job['restore_point_archive_id'];
        $base = (new Paths())->storageDir() . '/' . $pointId . '.mudrava';
        SplitSetSource::assertSetReadable($base, 1);
        $job['failed_archive_id'] = $job['archive_id'];
        $job['import_error'] = $error;
        $job['archive_id'] = $pointId;
        $job['state'] = 'rolling_back';
        $job['rollback_state'] = 'running';
        $job['phase'] = 'rollback_verifying';
        $job['percent'] = 0;
        $job['url_rewrite'] = [];
        $job['restored_rows'] = 0;
        $job['restored_files'] = 0;
        $job['transform_failures'] = 0;
        unset($job['checkpoint'], $job['verify_checkpoint'], $job['verified'], $job['archive_identity'], $job['archive_logical_bytes']);
        $job['logical_bytes'] = 0;
    }

    // ---- durability helpers -------------------------------------------------

    private static function cipherFromHeader(Header $header, ?string $password): ?FrameCipher
    {
        if (!$header->isEncrypted()) {
            return null;
        }
        if ($password === null || $password === '') {
            throw new \RuntimeException('MUDRAVA_WRONG_PASSWORD: encrypted archive requires a password');
        }
        $stack = CryptoCapability::stackForKdf($header->kdf);
        if (!CryptoCapability::supports($stack)) {
            throw new \RuntimeException('MUDRAVA_CRYPTO_UNAVAILABLE: archive requires ' . $stack);
        }
        $key = KeyDerivation::derive($password, $header->kdf, $header->kdfOpslimit, $header->kdfMemlimit, $header->kdfSalt);
        return new FrameCipher($key, $header->noncePrefix, $stack);
    }

    private static function acquireLock(): bool
    {
        $path = (new Paths())->ensureStorage() . '/job.lock';
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: cannot open job lock');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        self::$lockHandle = $handle;
        return true;
    }

    private static function releaseFileLock(): void
    {
        if (self::$lockHandle !== null) {
            flock(self::$lockHandle, LOCK_UN);
            fclose(self::$lockHandle);
            self::$lockHandle = null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function load(): ?array
    {
        if (self::$job !== null) {
            return self::$job;
        }
        $raw = get_option(self::OPTION, null);
        $mirror = self::loadMirror();
        // A restore can replace wp_options or lose its DB connection after
        // writing the private mirror. Keep even terminal jobs in the mirror:
        // an imported source-site option or a failed final DB write must not
        // erase the observed recovery result.
        if (
            $mirror !== null
            && (
                !is_array($raw)
                || (int) ($mirror['revision'] ?? 0) > (int) ($raw['revision'] ?? 0)
                || ($mirror['kind'] ?? '') !== ($raw['kind'] ?? '')
                || ($mirror['archive_id'] ?? '') !== ($raw['archive_id'] ?? '')
            )
        ) {
            self::$job = $mirror;
            return self::$job;
        }
        self::$job = is_array($raw) ? $raw : null;
        return self::$job;
    }

    /**
     * @param array<string,mixed> $job
     */
    public static function save(array $job): void
    {
        $job['revision'] = max(
            (int) ($job['revision'] ?? 0),
            (int) (self::$job['revision'] ?? 0)
        ) + 1;
        // Commit the private mirror first. Imports can replace wp_options;
        // exports also need their checkpoint if the database write fails.
        $json = self::encodeMirror($job);
        try {
            self::writeMirrorJson($json);
        } catch (\RuntimeException $mirrorError) {
            // A full or unwritable disk must never mask the error the
            // caller is about to record (e.g. MUDRAVA_DISK_FULL on the
            // archive itself). Fall back to the option store; only
            // re-throw when BOTH copies are lost, so a job can always
            // reach a durable terminal state while one store survives.
            $optionSaved = false;
            try {
                $optionSaved = update_option(self::OPTION, $job, false) !== false;
            } catch (\Throwable $optionError) {
                $optionSaved = false;
            }
            if (!$optionSaved) {
                throw $mirrorError;
            }
            self::$job = $job;
            return;
        }
        update_option(self::OPTION, $job, false);
        self::$job = $job;
    }

    /** Absolute path of the import job mirror (inside protected storage). */
    private static function mirrorPath(): string
    {
        return (new Paths())->storageDir() . '/job-mirror.json';
    }

    /**
     * @param array<string,mixed> $job
     */
    private static function encodeMirror(array $job): string
    {
        $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('MUDRAVA_INTERNAL: cannot encode job mirror');
        }
        return $json;
    }

    private static function writeMirrorJson(string $json): void
    {
        $path = self::mirrorPath();
        $temporary = @tempnam(dirname($path), '.job-');
        if ($temporary === false) {
            throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: cannot create job mirror');
        }
        try {
            if (
                @file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)
                || !@chmod($temporary, 0600)
                || !@rename($temporary, $path)
            ) {
                throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: cannot save job mirror');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function loadMirror(): ?array
    {
        $path = self::mirrorPath();
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function clearMirror(): void
    {
        $path = self::mirrorPath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public static function clear(): void
    {
        $owned = self::$lockHandle !== null;
        if (!$owned && !self::acquireLock()) {
            throw new \RuntimeException('MUDRAVA_JOB_RUNNING');
        }
        try {
            // Capture ownership evidence BEFORE erasing anything: a
            // terminal failed guarded import is the only proof that a
            // leftover recovery journal belongs to work that never began.
            $previous = self::load();
            self::$job = null;
            if (
                is_array($previous)
                && ($previous['kind'] ?? '') === self::KIND_IMPORT
                && ($previous['state'] ?? '') === 'failed'
                && !empty($previous['journal_enabled'])
                && (string) ($previous['archive_id'] ?? '') !== ''
            ) {
                update_option(self::FAILED_IMPORT_OPTION, [
                    'archive_id' => (string) $previous['archive_id'],
                    'error' => (string) ($previous['error'] ?? ''),
                    'rollback_state' => (string) ($previous['rollback_state'] ?? ''),
                    'failed_at' => (int) ($previous['updated_at'] ?? $previous['created_at'] ?? time()),
                ], false);
            }
            delete_option(self::OPTION);
            self::clearMirror();
        } finally {
            if (!$owned) {
                self::releaseFileLock();
            }
        }
    }

    /**
     * Cancel only a running export. A REST caller must never discard an
     * import or rollback checkpoint, even if it bypasses the admin UI.
     * The kind check and deletion share the same cross-request file lock.
     */
    public static function cancelExport(): void
    {
        if (!self::acquireLock()) {
            throw new \RuntimeException('MUDRAVA_JOB_RUNNING');
        }
        try {
            self::$job = null;
            $job = self::load();
            if (
                $job === null || ($job['kind'] ?? '') !== self::KIND_EXPORT
                || ($job['state'] ?? '') !== 'running'
            ) {
                throw new \RuntimeException('MUDRAVA_JOB_NOT_CANCELLABLE');
            }
            delete_option(self::OPTION);
            self::clearMirror();
            self::$job = null;
        } finally {
            self::releaseFileLock();
        }
    }

    /**
     * A running job remains resumable between ticks, including when its
     * worker lock has expired. Starting another job must never erase its
     * checkpoint. The operator can cancel it explicitly before starting
     * something else. Terminal jobs may be replaced.
     */
    private static function assertNoRunningJob(): void
    {
        $job = self::load();
        if ($job === null) {
            return;
        }
        if (in_array((string) ($job['state'] ?? ''), ['running', 'rolling_back'], true)) {
            throw new \RuntimeException('MUDRAVA_JOB_RUNNING: finish or cancel the current job first');
        }
        self::clear();
    }

    /**
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    public static function startExportJob(array $spec): array
    {
        $requestId = (string) ($spec['request_id'] ?? '');
        $expectedState = $spec['expected_state'] ?? null;
        if (
            $requestId !== '' && (!preg_match('/^[a-f0-9]{64}$/D', $requestId)
            || !is_string($expectedState) || !preg_match('/^[a-f0-9]{64}$/D', $expectedState))
        ) {
            throw new \InvalidArgumentException('MUDRAVA_INVALID_REQUEST_ID');
        }
        if (is_multisite()) {
            throw new \RuntimeException('MUDRAVA_MULTISITE_UNSUPPORTED');
        }
        if (!self::acquireLock()) {
            throw new \RuntimeException('MUDRAVA_JOB_RUNNING');
        }
        try {
            self::$job = null;
            $previous = self::load();
            if (
                $requestId !== '' && $previous !== null && ($previous['kind'] ?? '') === self::KIND_EXPORT
                && ($previous['request_id'] ?? '') === $requestId
            ) {
                // Recover a start committed before the caller saved its receipt.
                return $previous;
            }
            if (
                $expectedState !== null && (!is_string($expectedState)
                || !hash_equals(self::stateToken($previous), $expectedState))
            ) {
                throw new \RuntimeException('MUDRAVA_JOB_CHANGED');
            }
            self::assertNoRunningJob();
            $job = [
            'request_id'  => $requestId,
            'kind'        => self::KIND_EXPORT,
            'state'       => 'running',
            'archive_id'  => self::exportId((string) ($spec['site_meta']['site_url'] ?? '')),
            'site_meta'   => $spec['site_meta'],
            'purpose'     => (string) ($spec['purpose'] ?? 'backup'),
            'split_bytes' => isset($spec['split_bytes']) ? (int) $spec['split_bytes'] : null,
            'password_hint' => isset($spec['password_hint']) ? (string) $spec['password_hint'] : '',
            'encrypted'   => !empty($spec['encrypted']),
            'excludes'    => [
                'tables' => array_values(array_map('strval', (array) ($spec['excludes']['tables'] ?? []))),
                'dirs'   => array_values(array_map('strval', (array) ($spec['excludes']['dirs'] ?? []))),
            ],
            'current_part' => 1,
            'percent'     => 0,
            'created_at'  => time(),
            'updated_at'  => time(),
            'lock_until'  => 0,
            ];
            self::save($job);
            return self::load() ?? $job;
        } finally {
            self::releaseFileLock();
        }
    }

    /**
     * Opaque identity for comparing the current slot under the start lock.
     * @param array<string,mixed>|null $job
     */
    public static function stateToken(?array $job): string
    {
        return hash('sha256', $job === null ? 'none' : implode('|', [
            (string) ($job['kind'] ?? ''), (string) ($job['archive_id'] ?? ''),
            (string) ($job['revision'] ?? 0), (string) ($job['state'] ?? ''),
        ]));
    }

    /**
     * Archive ID with built-in provenance: backup-<host>-<Ymd-His>-<rand>.
     * The host slug puts the origin site straight into the filename, so a
     * pile of downloaded .mudrava files answers "which site is this from?"
     * without opening a single one. Lowercase [a-z0-9-] only: IDs pass
     * through sanitize_key (which lowercases) and the REST route regex,
     * and leave the server as filenames.
     */
    private static function exportId(string $siteUrl): string
    {
        $host = strtolower((string) wp_parse_url($siteUrl !== '' ? $siteUrl : home_url(), PHP_URL_HOST));
        $host = trim((string) preg_replace('/[^a-z0-9]+/', '-', $host), '-');
        $host = (string) preg_replace('/^www-/', '', $host);
        $host = trim((string) substr($host, 0, 40), '-');
        $slug = $host !== '' ? $host . '-' : '';
        return 'backup-' . $slug . gmdate('Ymd-His') . '-' . strtolower(wp_generate_password(6, false));
    }

    /**
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    public static function startImportJob(array $spec): array
    {
        if (is_multisite()) {
            throw new \RuntimeException('MUDRAVA_MULTISITE_UNSUPPORTED');
        }
        if (!self::acquireLock()) {
            throw new \RuntimeException('MUDRAVA_JOB_RUNNING');
        }
        try {
            self::$job = null;
            // Load the previous job BEFORE anything can erase it: an
            // orphaned recovery journal can only be reclaimed against this
            // evidence (or the ownership receipt left by a prior erase).
            $previous = self::load();
            if (
                is_array($previous)
                && in_array((string) ($previous['state'] ?? ''), ['running', 'rolling_back'], true)
            ) {
                throw new \RuntimeException('MUDRAVA_JOB_RUNNING: finish or cancel the current job first');
            }
            $pointId = (string) ($spec['restore_point_archive_id'] ?? '');
            if ($pointId !== '') {
                if (
                    !is_array($previous)
                    || ($previous['kind'] ?? '') !== self::KIND_EXPORT
                    || ($previous['purpose'] ?? '') !== 'restore_point'
                    || ($previous['state'] ?? '') !== 'done'
                    || ($previous['archive_id'] ?? '') !== $pointId
                ) {
                    throw new \RuntimeException('MUDRAVA_RESTORE_POINT_REQUIRED');
                }
            }
            $journalEnabled = $pointId !== '';
            $archiveId = (string) $spec['archive_id'];
            $journal = new ImportJournal($archiveId);
            $reclaimed = false;
            // An orphaned journal for this archive must never be stacked or
            // silently ignored, even on an unsafe (unguarded) retry: it may
            // still guard recovery. Reclaim only under the proven-safe rule.
            if ($journal->exists()) {
                $reclaimed = self::reclaimOrphanJournal($journal, $previous, $archiveId);
            }
            if ($journalEnabled) {
                $tables = $GLOBALS['wpdb']->get_col('SHOW TABLES');
                if (!is_array($tables)) {
                    throw new \RuntimeException('MUDRAVA_DB_ERROR: cannot list destination tables');
                }
                $journal->create(array_values(array_map('strval', $tables)));
            }
            // Terminal previous job: erase it now that the journal decision
            // is settled. clear() records the ownership receipt when the
            // erased job was a failed guarded import.
            self::clear();
            if ($reclaimed) {
                // Ownership was proven and the orphan is gone; the receipt
                // has no further duty. A new failure records a fresh one.
                delete_option(self::FAILED_IMPORT_OPTION);
            }
            $job = [
            'kind'        => self::KIND_IMPORT,
            'state'       => 'running',
            'archive_id'  => $spec['archive_id'],
            'restore_point_archive_id' => (string) ($spec['restore_point_archive_id'] ?? ''),
            'journal_enabled' => $journalEnabled,
            'url_rewrite' => $spec['url_rewrite'] ?? [],
            'percent'     => 0,
            'created_at'  => time(),
            'updated_at'  => time(),
            'lock_until'  => 0,
            ];
            self::save($job);
            return $job;
        } finally {
            self::releaseFileLock();
        }
    }

    /**
     * Decide whether an orphaned recovery journal may be reclaimed before a
     * fresh guarded import of the same archive. The caller holds the job
     * lock and has verified no job is running or rolling back.
     *
     * Safety: every destructive write is journaled BEFORE it happens (the
     * file target records the temporary inode, the database target records
     * the table before DROP), so a journal containing only its validated
     * baseline proves its owning import never mutated a file or a table.
     * Ownership must still be proven - by the still-stored failed import,
     * or by the receipt clear() left when that job was erased - because a
     * journal with unknown ownership could guard recovery an operator must
     * finish by hand. Every refusal throws before anything is deleted.
     *
     * @param array<string,mixed>|null $previous
     * @return true when the orphan was validated and removed
     */
    private static function reclaimOrphanJournal(ImportJournal $journal, ?array $previous, string $archiveId): bool
    {
        $owner = null;
        if (
            is_array($previous)
            && ($previous['kind'] ?? '') === self::KIND_IMPORT
            && ($previous['state'] ?? '') === 'failed'
            && !empty($previous['journal_enabled'])
            && (string) ($previous['archive_id'] ?? '') === $archiveId
        ) {
            $owner = $previous;
        } else {
            $receipt = get_option(self::FAILED_IMPORT_OPTION, null);
            if (
                is_array($receipt)
                && (string) ($receipt['archive_id'] ?? '') === $archiveId
            ) {
                $owner = $receipt;
            }
        }
        if ($owner === null) {
            throw new \RuntimeException(
                'MUDRAVA_RECOVERY_JOURNAL: a recovery journal for this archive exists but its owner is unknown. '
                . 'Finish any pending recovery, then remove the journal from private storage and retry.'
            );
        }
        if ((string) ($owner['rollback_state'] ?? '') !== '') {
            throw new \RuntimeException(
                'MUDRAVA_RECOVERY_JOURNAL: the previous guarded import began a rollback that did not finish. '
                . 'Complete that recovery before retrying.'
            );
        }
        if (!$journal->removeIfPristine()) {
            throw new \RuntimeException(
                'MUDRAVA_RECOVERY_INCOMPLETE: the previous guarded import recorded mutations in its journal. '
                . 'Finish or roll back that recovery before retrying.'
            );
        }
        // The caller deletes the receipt after clear(): deleting it here
        // would be undone, because clear() re-records a stored failed job.
        Plugin::instance()->logger()->info('journal_reclaimed', ['archive_id' => $archiveId]);
        return true;
    }

    /**
     * Never leak secrets or absolute paths through error strings. Part
     * details are the one kind of detail the operator genuinely needs
     * ("which part went missing?") and contain no paths, so a strict
     * allowlist regex re-quotes them while everything else is dropped.
     */
    public static function safeError(\Throwable $e): string
    {
        $msg = $e->getMessage();
        if (preg_match('/^(MUDRAVA_[A-Z0-9_]{1,80})(?::|$)/', $msg, $codeMatch) === 1) {
            // Keep only a well-formed typed code. Never treat the rest of an
            // exception message as part of the public error identifier.
            $code = $codeMatch[1];
            if (preg_match('/expected part \d+, got \d+|part \d+(?: of [A-Za-z0-9._\-]+)?/', $msg, $m) === 1) {
                return $code . ': ' . $m[0];
            }
            return $code;
        }
        return 'MUDRAVA_INTERNAL';
    }
}
