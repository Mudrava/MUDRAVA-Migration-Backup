<?php

/**
 * Restore points per ADR-0005: created only when disk headroom honestly
 * permits preserving the previous state. Never advertised as guaranteed.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Rollback;

use Mudrava\Migration\Support\Paths;
use Mudrava\Migration\Support\Preflight;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Protected binary archives require seekable native streams and atomic file operations.

final class RestorePoint
{
    public const OPTION = 'mudrava_restore_point';

    /**
     * Decide whether a safe restore point is physically possible. The actual
     * backup is a normal export job the UI runs BEFORE the destructive
     * restore; this method measures the available headroom.
     *
     * @return array{safe:bool,free:int,needed:int}
     */
    public static function headroom(): array
    {
        $paths = new Paths();
        $dir = $paths->ensureStorage();
        $free = (int) (@disk_free_space($dir) ?: 0);
        $siteBytes = Preflight::siteSizeBytes();
        // Safe mode must hold the previous state AND the incoming archive.
        $needed = $siteBytes * 2;
        return [
            'safe'   => $free >= $needed,
            'free'   => $free,
            'needed' => $needed,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function current(): ?array
    {
        $path = (new Paths())->storageDir() . '/restore-point.json';
        if (is_file($path)) {
            $json = @file_get_contents($path);
            $data = is_string($json) ? json_decode($json, true) : null;
            if (is_array($data)) {
                return $data;
            }
        }
        $raw = get_option(self::OPTION, null);
        return is_array($raw) ? $raw : null;
    }

    /**
     * Persist the decision and ID of the completed destination export.
     * The import job also carries the ID in its protected file mirror, since
     * the incoming database can replace this option during restore.
     *
     * @param array{safe:bool,free:int,needed:int} $headroom
     */
    public static function record(array $headroom, bool $proceedUnsafe, string $archiveId): void
    {
        $record = [
            'safe'          => (bool) $headroom['safe'],
            'free'          => (int) $headroom['free'],
            'needed'        => (int) $headroom['needed'],
            'proceed_unsafe' => $proceedUnsafe,
            'archive_id'    => $archiveId,
            'recorded_at'   => time(),
        ];
        update_option(self::OPTION, $record, false);
        $path = (new Paths())->ensureStorage() . '/restore-point.json';
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $json = json_encode($record);
        if ($json === false || @file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new \RuntimeException('MUDRAVA_RESTORE_POINT_RECORD');
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('MUDRAVA_RESTORE_POINT_RECORD');
        }
    }
}
