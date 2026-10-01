<?php

/**
 * Versioned PHP integration surface for trusted, separately installed add-ons.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Integration;

use Mudrava\Migration\Migration\JobRunner;
use Mudrava\Migration\Storage\SplitSetSource;
use Mudrava\Migration\Support\Paths;
use Mudrava\Migration\Support\Preflight;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Private archive descriptors require native file metadata.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal PHP API exceptions are handled by the caller.

final class CoreApi
{
    public const CONTRACT_VERSION = 1;

    /** @param callable():void $renderer */
    public static function registerAdminPanel(string $slug, callable $renderer, string $label = ''): void
    {
        AdminPanels::register($slug, $renderer, $label);
    }

    /** Protect an installed add-on from export and restore code replacement. */
    public static function protectDirectory(string $directory): void
    {
        ProtectedDirectories::register($directory);
    }

    /** Private host-local data, outside the site and content roots. */
    public static function privateAddonDirectory(string $namespace): string
    {
        if (!preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $namespace)) {
            throw new \InvalidArgumentException('MUDRAVA_INVALID_ADDON_NAMESPACE');
        }
        $directory = (new Paths())->ensureStorage();
        foreach (['.addons', $namespace] as $name) {
            $directory .= '/' . $name;
            if (is_link($directory)) {
                throw new \RuntimeException('MUDRAVA_STORAGE_UNSAFE');
            }
            if (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)) {
                throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED');
            }
            if (!@chmod($directory, 0700)) {
                throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED');
            }
        }
        $real = realpath($directory);
        if ($real === false) {
            throw new \RuntimeException('MUDRAVA_STORAGE_UNSAFE');
        }
        return $real;
    }

    /**
     * Start a local export. Callers own their capability/consent boundary.
     * No credential, license or remote transport is stored in the core job.
     *
     * @param array{tables?:list<string>,dirs?:list<string>} $excludes
     * @return array<string,mixed>
     */
    public static function startExport(
        array $excludes = [],
        bool $encrypted = false,
        ?int $splitBytes = null,
        string $requestId = '',
        ?string $expectedState = null
    ): array {
        if ($splitBytes !== null && $splitBytes !== 0 && $splitBytes < 1048576) {
            throw new \InvalidArgumentException('MUDRAVA_INVALID_SPLIT_SIZE');
        }
        foreach (['tables', 'dirs'] as $field) {
            foreach ($excludes[$field] ?? [] as $value) {
                if (!is_string($value) || strpos($value, "\0") !== false) {
                    throw new \InvalidArgumentException('MUDRAVA_INVALID_EXCLUSION');
                }
            }
        }
        return self::summary(JobRunner::startExportJob([
            'request_id' => $requestId,
            'expected_state' => $expectedState,
            'site_meta'   => Preflight::siteMeta(),
            'encrypted'   => $encrypted,
            'split_bytes' => $splitBytes,
            'excludes'    => $excludes,
        ]));
    }

    /** @return array<string,mixed>|null */
    public static function status(): ?array
    {
        $job = JobRunner::load();
        return $job === null ? null : self::summary($job);
    }

    /** One consistent read for admission and a compare-before-start token.
     * @return array{job:array<string,mixed>|null,token:string}
     */
    public static function captureState(): array
    {
        $job = JobRunner::load();
        return ['job' => $job === null ? null : self::summary($job), 'token' => JobRunner::stateToken($job)];
    }

    /**
     * Advance only this export. A replacement import must never be ticked.
     * The runner checks the identity again after acquiring its atomic lock.
     *
     * @return array<string,mixed>
     */
    public static function tickExport(string $archiveId, ?string $password = null): array
    {
        return self::summary(JobRunner::tick($password, $archiveId));
    }

    /**
     * Descriptor for the current completed, verified export only. Paths are
     * internal PHP data: callers must never expose them through public APIs.
     * Identity is a change detector, not a full-file cryptographic checksum.
     * Recheck before reading; a descriptor does not lease or pin the files.
     *
     * @return array{archive_id:string,identity:string,encrypted:bool,parts:list<array{path:string,name:string,bytes:string}>}
     */
    public static function completedExport(string $archiveId): array
    {
        $job = JobRunner::load();
        if (
            $job === null || ($job['kind'] ?? '') !== JobRunner::KIND_EXPORT
            || ($job['archive_id'] ?? '') !== $archiveId || ($job['state'] ?? '') !== 'done'
            || empty($job['verified']) || !preg_match('/^[a-z0-9-]+$/D', $archiveId)
        ) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_NOT_READY');
        }
        $base = (new Paths())->ensureStorage() . '/' . $archiveId . '.mudrava';
        $set = SplitSetSource::assertSetReadable($base);
        $identity = SplitSetSource::identity($base);
        if (!isset($job['archive_identity']) || !hash_equals((string) $job['archive_identity'], $identity)) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED');
        }
        $parts = [];
        foreach ($set['parts'] as $path) {
            $size = @filesize($path);
            if ($size === false) {
                throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED');
            }
            $parts[] = ['path' => $path, 'name' => basename($path), 'bytes' => (string) $size];
        }
        if (!hash_equals($identity, SplitSetSource::identity($base))) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CHANGED');
        }
        return [
            'archive_id' => $archiveId,
            'identity'   => $identity,
            'encrypted'  => !empty($job['encrypted']),
            'parts'      => $parts,
        ];
    }

    /**
     * Allowlist excludes checkpoints, site metadata, paths and arbitrary state.
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private static function summary(array $job): array
    {
        return array_intersect_key($job, array_fill_keys([
            'kind', 'state', 'archive_id', 'revision', 'purpose', 'encrypted', 'request_id',
            'verified', 'percent', 'phase', 'logical_bytes', 'current_part',
            'created_at', 'updated_at', 'error', 'note', 'rollback_state',
        ], true));
    }
}
