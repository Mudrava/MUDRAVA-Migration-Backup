<?php

/**
 * Import preflight and start, shared by the REST controller and the
 * WP-CLI bridge so a headless restore runs the EXACT same honest gates as
 * the browser: provable set completeness on disk first, honest restore-
 * point headroom second, an explicit acknowledgement when proceeding
 * without a safety copy, and only then a scheduled job.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Migration;

use Mudrava\Migration\Rollback\RestorePoint;
use Mudrava\Migration\Storage\SplitSetSource;
use Mudrava\Migration\Support\Paths;

defined('ABSPATH') || exit;

final class ImportOps
{
    /**
     * Count the parts of a staged split set in private storage.
     *
     * Part 1 is the base NAME.mudrava file; continuation parts are numbered
     * from 0002. Returns the total part count (1 for an unsplit archive).
     * A gap in the numbering is a hard refusal, never a silent stop: a
     * missing middle part must never look like a complete end-of-set.
     *
     * @throws \RuntimeException MUDRAVA_SET_INCOMPLETE on a numbering gap
     */
    public static function countSetParts(string $base): int
    {
        $expected = 1;
        $seen = 0;
        for ($n = 2; is_file(sprintf('%s.part%04d', $base, $n)); $n++) {
            $expected++;
            $seen++;
        }
        $onDisk = glob($base . '.part[0-9][0-9][0-9][0-9]');
        if (is_array($onDisk) && count($onDisk) > $seen) {
            throw new \RuntimeException(sprintf(
                'MUDRAVA_SET_INCOMPLETE: %d part file(s) in storage but a gap stops the run at part %d',
                count($onDisk) + 1,
                $expected + 1
            ));
        }
        return $expected;
    }

    /**
     * Validate the archive set and start the import job.
     *
     * @param array{search:string,replace:string} $urlRewrite empty pair lets the engine derive source -> this site
     * @return array<string,mixed> the started job
     * @throws \RuntimeException MUDRAVA_* coded refusal (set incomplete, restore point required, disk, locks)
     */
    public static function start(
        string $archiveId,
        int $expectedParts,
        array $urlRewrite,
        bool $proceedUnsafe,
        string $restorePointId
    ): array {
        if ($archiveId === '') {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_NOT_FOUND');
        }
        // Pre-flight BEFORE anything destructive is scheduled or any rollback
        // decision is recorded: the set must be provably complete and
        // self-consistent on disk first, so the restore never starts a DROP
        // only to discover a missing part in the middle of the site.
        $paths = new Paths();
        $base = $paths->storageDir() . '/' . $archiveId . '.mudrava';
        try {
            SplitSetSource::assertSetReadable($base, $expectedParts);
        } catch (\Throwable $e) {
            $safe = JobRunner::safeError($e);
            throw new \RuntimeException($safe, 0, $e);
        }
        // A safe restore requires the immediately preceding, completed export
        // of this destination. A client cannot claim one using an arbitrary
        // archive ID. The unsafe path needs an explicit acknowledgement.
        $headroom = RestorePoint::headroom();
        $previousJob = JobRunner::load();
        $restorePointValid = $restorePointId !== ''
            && is_array($previousJob)
            && self::restorePointJobMatches($restorePointId, $archiveId, (array) $previousJob);
        if ($restorePointValid) {
            $pointBase = $paths->storageDir() . '/' . $restorePointId . '.mudrava';
            try {
                SplitSetSource::assertSetReadable($pointBase, 1);
            } catch (\Throwable $e) {
                $restorePointValid = false;
            }
        }
        if (!$restorePointValid && !$proceedUnsafe) {
            throw new \RuntimeException('MUDRAVA_RESTORE_POINT_REQUIRED');
        }
        RestorePoint::record($headroom, $proceedUnsafe, $restorePointValid ? $restorePointId : '');
        return JobRunner::startImportJob([
            'archive_id'  => $archiveId,
            'restore_point_archive_id' => $restorePointValid ? $restorePointId : '',
            'url_rewrite' => $urlRewrite,
        ]);
    }

    /**
     * HTTP status for a thrown code at the REST boundary: client-fixable
     * problems are 400s, state/safety refusals are 409s.
     */
    public static function statusForCode(string $code): int
    {
        if ($code === 'MUDRAVA_ARCHIVE_NOT_FOUND') {
            return 400;
        }
        if ($code === 'MUDRAVA_PART_MISSING' || $code === 'MUDRAVA_PART_MISMATCH') {
            return 400;
        }
        return 409;
    }

    /**
     * The previous job must be a completed restore-point export of THIS
     * destination, fresh enough to still describe the live site, and not
     * the very archive being restored.
     *
     * @param array<string,mixed> $previousJob
     */
    private static function restorePointJobMatches(string $restorePointId, string $archiveId, array $previousJob): bool
    {
        return ($previousJob['kind'] ?? '') === JobRunner::KIND_EXPORT
            && ($previousJob['purpose'] ?? '') === 'restore_point'
            && ($previousJob['state'] ?? '') === 'done'
            && ($previousJob['archive_id'] ?? '') === $restorePointId
            && (int) ($previousJob['created_at'] ?? 0) >= time() - HOUR_IN_SECONDS
            && $restorePointId !== $archiveId;
    }
}
