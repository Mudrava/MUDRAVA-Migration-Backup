<?php

/**
 * REST surface under mudrava/v1. Every mutating route requires the
 * mudrava_migrate capability plus a valid X-WP-Nonce. Passwords are
 * accepted per-request over TLS and never stored.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Http;

use Mudrava\Migration\Migration\JobRunner;
use Mudrava\Migration\Storage\SplitSetSource;
use Mudrava\Migration\Support\Capabilities;
use Mudrava\Migration\Support\Inventory;
use Mudrava\Migration\Support\Paths;
use Mudrava\Migration\Support\Preflight;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Protected binary archives require seekable native streams and atomic file operations.

final class RestApi
{
    public const NS = 'mudrava/v1';

    public static function register(): void
    {
        register_rest_route(self::NS, '/preflight', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'preflight'],
            'permission_callback' => [self::class, 'can'],
        ]);
        register_rest_route(self::NS, '/maintenance', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'maintenance'],
            'permission_callback' => [self::class, 'can'],
        ]);
        register_rest_route(self::NS, '/maintenance', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'maintenanceApply'],
            'permission_callback' => [self::class, 'can'],
        ]);
        register_rest_route(self::NS, '/inventory', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'inventory'],
            'permission_callback' => [self::class, 'can'],
        ]);
        register_rest_route(self::NS, '/job/export', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'startExport'],
            'permission_callback' => [self::class, 'can'],
            'args'                => [
                'split_bytes'   => ['sanitize_callback' => 'absint'],
                'password_hint' => ['sanitize_callback' => 'sanitize_text_field'],
                'excludes'      => [
                    'sanitize_callback' => [self::class, 'sanitizeExcludes'],
                ],
            ],
        ]);
        register_rest_route(self::NS, '/restore-point', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'restorePoint'],
            'permission_callback' => [self::class, 'can'],
        ]);
        register_rest_route(self::NS, '/job/restore-point', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'startRestorePoint'],
            'permission_callback' => [self::class, 'can'],
        ]);
        register_rest_route(self::NS, '/job/import', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'startImport'],
            'permission_callback' => [self::class, 'can'],
        ]);
        register_rest_route(self::NS, '/job/tick', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'tick'],
            'permission_callback' => [self::class, 'can'],
        ]);
        register_rest_route(self::NS, '/job', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'status'],
            'permission_callback' => [self::class, 'can'],
        ]);
        register_rest_route(self::NS, '/job', [
            'methods'             => 'DELETE',
            'callback'            => [self::class, 'cancel'],
            'permission_callback' => [self::class, 'can'],
        ]);
        register_rest_route(self::NS, '/archives', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'archives'],
            'permission_callback' => [self::class, 'can'],
        ]);
        register_rest_route(self::NS, '/archive-info', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'archiveInfo'],
            'permission_callback' => [self::class, 'can'],
            'args'                => [
                'archive_id' => ['sanitize_callback' => 'sanitize_key'],
                // WP passes (value, request, param) to sanitize callbacks,
                // so a bare strval would explode - take the value only.
                'password'   => ['sanitize_callback' => static fn ($v) => (string) $v],
            ],
        ]);
        register_rest_route(self::NS, '/archives/(?P<archive_id>[A-Za-z0-9\-]+)/download', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'downloadArchive'],
            'permission_callback' => [self::class, 'can'],
            'args'                => [
                'archive_id' => ['sanitize_callback' => 'sanitize_key'],
                'part'       => ['default' => 1, 'sanitize_callback' => 'absint'],
            ],
        ]);
        register_rest_route(self::NS, '/upload/(?P<upload_id>[A-Za-z0-9\-]+)', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'uploadChunk'],
            'permission_callback' => [self::class, 'can'],
            'args'                => [
                'upload_id' => ['sanitize_callback' => 'sanitize_key'],
                'part'      => ['default' => 1, 'sanitize_callback' => 'absint'],
                'parts'     => ['default' => 1, 'sanitize_callback' => 'absint'],
            ],
        ]);
        register_rest_route(self::NS, '/upload/(?P<upload_id>[A-Za-z0-9\-]+)/finalize', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'finalizeUpload'],
            'permission_callback' => [self::class, 'can'],
            'args'                => [
                'upload_id' => ['sanitize_callback' => 'sanitize_key'],
            ],
        ]);
        register_rest_route(self::NS, '/upload/(?P<upload_id>[A-Za-z0-9\-]+)/status', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'uploadStatus'],
            'permission_callback' => [self::class, 'can'],
            'args'                => [
                'upload_id' => ['sanitize_callback' => 'sanitize_key'],
            ],
        ]);
    }

    public static function can(): bool
    {
        return Capabilities::currentCan();
    }

    public static function preflight(): \WP_REST_Response
    {
        return rest_ensure_response(Preflight::run());
    }

    /**
     * Maintenance dry run: counts only, nothing deleted. The mutating half
     * lives in maintenanceApply and needs the same capability plus an
     * explicit item list, so a stray GET can never sweep a site.
     */
    public static function maintenance(): \WP_REST_Response
    {
        return rest_ensure_response([
            'report' => \Mudrava\Migration\Support\Maintenance::report(),
            'advisories' => \Mudrava\Migration\Support\Maintenance::advisories(),
        ]);
    }

    public static function maintenanceApply(\WP_REST_Request $req): \WP_REST_Response
    {
        $requested = array_values(array_filter(
            (array) $req->get_param('items'),
            static fn ($v) => is_string($v)
        ));
        $items = array_values(array_intersect(
            \Mudrava\Migration\Support\Maintenance::CLEANABLE,
            $requested
        ));
        if ($items === []) {
            return new \WP_REST_Response(['code' => 'MUDRAVA_VALIDATION'], 400);
        }
        try {
            $result = \Mudrava\Migration\Support\Maintenance::apply($items);
        } catch (\Throwable $e) {
            return new \WP_REST_Response(['code' => JobRunner::safeError($e)], 500);
        }
        return rest_ensure_response($result);
    }

    /**
     * Live content inventory for the export pickers: every real table and
     * every real wp-content folder with honest size measurements. Read-
     * only, so a plain authenticated GET is enough.
     */
    public static function inventory(): \WP_REST_Response
    {
        return rest_ensure_response(Inventory::run());
    }

    /**
     * Sanitize the picker payload {tables:[...],dirs:[...]}: members must
     * be plain identifiers/paths (letters, digits, _ - . /). Anything
     * else - traversal, wildcards, spaces - is dropped, never trusted
     * downstream. Idempotent, so route args and callers may both apply it.
     *
     * @param mixed $value
     * @return array{tables:list<string>,dirs:list<string>}
     */
    public static function sanitizeExcludes($value): array
    {
        $out = ['tables' => [], 'dirs' => []];
        if (!is_array($value)) {
            return $out;
        }
        foreach (['tables', 'dirs'] as $key) {
            foreach ((array) ($value[$key] ?? []) as $item) {
                $item = trim((string) $item);
                if ($item === '' || preg_match('/^[A-Za-z0-9_\/\-.]+$/', $item) !== 1) {
                    continue;
                }
                if (strpos($item, '..') !== false) {
                    continue;
                }
                $out[$key][] = $item;
            }
        }
        return $out;
    }

    /**
     * Rollback headroom for the restore screen: can a safe restore point be
     * created here, honestly measured (spec: never promise rollback the disk
     * cannot afford).
     */
    public static function restorePoint(): \WP_REST_Response
    {
        // Same boundary as startRestorePoint(): storage probing must never
        // escape as an uncaught 500 on an unwritable or unsafe directory.
        try {
            $hr = \Mudrava\Migration\Rollback\RestorePoint::headroom();
        } catch (\RuntimeException $e) {
            return new \WP_REST_Response(['code' => JobRunner::safeError($e)], 409);
        }
        return rest_ensure_response([
            'safe'   => $hr['safe'],
            'free'   => (string) $hr['free'],
            'needed' => (string) $hr['needed'],
        ]);
    }

    public static function startExport(\WP_REST_Request $req): \WP_REST_Response
    {
        $split = $req->get_param('split_bytes');
        $excludes = self::sanitizeExcludes($req->get_param('excludes'));
        try {
            $job = JobRunner::startExportJob([
                'site_meta'  => Preflight::siteMeta(),
                'split_bytes' => $split !== null ? (int) $split : null,
                'password_hint' => (string) $req->get_param('password_hint'),
                'encrypted' => $req->get_param('encrypted') === true,
                'excludes'   => $excludes,
            ]);
        } catch (\RuntimeException $e) {
            return new \WP_REST_Response(['code' => JobRunner::safeError($e)], 409);
        }
        return rest_ensure_response(self::publicJob($job));
    }

    public static function startRestorePoint(): \WP_REST_Response
    {
        // headroom() touches private storage (ensureStorage). An unwritable
        // or unsafe directory must surface as the same safe 409 as every
        // other pre-flight failure, never as an uncaught 500.
        try {
            $headroom = \Mudrava\Migration\Rollback\RestorePoint::headroom();
        } catch (\RuntimeException $e) {
            return new \WP_REST_Response(['code' => JobRunner::safeError($e)], 409);
        }
        if (!$headroom['safe']) {
            return new \WP_REST_Response(['code' => 'MUDRAVA_RESTORE_POINT_SPACE'], 409);
        }
        try {
            $job = JobRunner::startExportJob([
                'site_meta' => Preflight::siteMeta(),
                'purpose'   => 'restore_point',
            ]);
        } catch (\RuntimeException $e) {
            return new \WP_REST_Response(['code' => JobRunner::safeError($e)], 409);
        }
        return rest_ensure_response(self::publicJob($job));
    }

    public static function startImport(\WP_REST_Request $req): \WP_REST_Response
    {
        $archiveId = sanitize_key((string) $req->get_param('archive_id'));
        $expected = (int) $req->get_param('expected_parts');
        $rewrite = (array) $req->get_param('url_rewrite');
        $proceedUnsafe = $req->get_param('proceed_unsafe') === true;
        $restorePointId = sanitize_key((string) $req->get_param('restore_point_archive_id'));
        // Every honest gate (set completeness, restore-point truth, unsafe
        // acknowledgement) lives in ImportOps, shared verbatim with the
        // WP-CLI bridge so a headless restore cannot skip a browser gate.
        try {
            $job = \Mudrava\Migration\Migration\ImportOps::start(
                $archiveId,
                $expected,
                [
                    'search'  => esc_url_raw((string) ($rewrite['search'] ?? '')),
                    'replace' => esc_url_raw((string) ($rewrite['replace'] ?? '')),
                ],
                $proceedUnsafe,
                $restorePointId
            );
        } catch (\Throwable $e) {
            $safe = JobRunner::safeError($e);
            $code = (string) (strpos($safe, 'MUDRAVA_') === 0 ? strtok($safe . ':', ':') : 'MUDRAVA_INTERNAL');
            return new \WP_REST_Response(
                ['code' => $code, 'message' => $safe],
                \Mudrava\Migration\Migration\ImportOps::statusForCode($code)
            );
        }
        $response = rest_ensure_response(self::publicJob($job));
        // Issue a restore-scoped token so ticks keep authenticating after the
        // restore replaces wp_usermeta (and thus the operator's session).
        $token = RestoreToken::issue(get_current_user_id());
        $response->data['restore_token'] = $token;
        return $response;
    }

    public static function tick(\WP_REST_Request $req): \WP_REST_Response
    {
        $password = $req->get_param('password');
        $password = is_string($password) && $password !== '' ? $password : null;
        try {
            $job = JobRunner::tick($password);
        } catch (\RuntimeException $e) {
            return new \WP_REST_Response(['code' => JobRunner::safeError($e)], 500);
        }
        return rest_ensure_response(self::publicJob($job));
    }

    public static function status(): \WP_REST_Response
    {
        $job = JobRunner::load();
        if ($job === null) {
            return rest_ensure_response(['state' => 'idle']);
        }
        return rest_ensure_response(self::publicJob($job));
    }

    public static function cancel(): \WP_REST_Response
    {
        try {
            JobRunner::cancelExport();
        } catch (\RuntimeException $e) {
            return new \WP_REST_Response(['code' => JobRunner::safeError($e)], 409);
        }
        RestoreToken::clear();
        return rest_ensure_response(['state' => 'idle']);
    }

    public static function archives(): \WP_REST_Response
    {
        $paths = new Paths();
        $dir = $paths->storageDir();
        $out = [];
        $glob = is_dir($dir) ? glob($dir . '/*.mudrava') : [];
        foreach ((array) $glob as $base) {
            if (!is_string($base) || !is_file($base)) {
                continue;
            }
            $inspect = SplitSetSource::inspect($base);
            if ($inspect['found'] === 0) {
                continue;
            }
            $size = 0;
            foreach ($inspect['parts'] as $p) {
                $size += (int) filesize($p);
            }
            // Honest card metadata from the real header (and, when the
            // archive is plaintext, its SITE_METADATA source URL). Cheap:
            // a couple of small reads per part 1.
            $info = \Mudrava\Migration\Archive\ArchiveInfo::peek($base);
            $out[] = [
                'archive_id' => basename($base, '.mudrava'),
                'parts'      => $inspect['found'],
                'missing'    => $inspect['missing'],
                'bytes'      => $size,
                'encrypted'  => $info['encrypted'],
                'hint'       => $info['hint'],
                'source_url' => $info['source_url'],
                'mtime'      => (int) filemtime($base),
            ];
        }
        usort($out, static fn (array $a, array $b) => $b['mtime'] <=> $a['mtime']);
        return rest_ensure_response(['archives' => $out]);
    }

    /**
     * Peek inside one archive for the import form: the source site URL it
     * was exported from (auto-detected, so the operator never retypes it)
     * plus encryption state. Password is optional; without it an encrypted
     * archive simply reports no URL yet.
     */
    public static function archiveInfo(\WP_REST_Request $req): \WP_REST_Response
    {
        $archiveId = (string) $req->get_param('archive_id');
        if (preg_match('/^[a-z0-9\-_]+$/', $archiveId) !== 1) {
            return new \WP_REST_Response(['code' => 'MUDRAVA_ARCHIVE_NOT_FOUND'], 404);
        }
        $password = (string) $req->get_param('password');
        $paths = new Paths();
        $base = $paths->storageDir() . '/' . $archiveId . '.mudrava';
        if (!is_file($base)) {
            return new \WP_REST_Response(['code' => 'MUDRAVA_ARCHIVE_NOT_FOUND'], 404);
        }
        $info = \Mudrava\Migration\Archive\ArchiveInfo::peek($base, $password !== '' ? $password : null);
        // Environment diff for the restore screen: every plugin the SOURCE
        // ran that this site does not have installed is a page-builder-sized
        // hole waiting to appear as blank pages after a successful restore.
        // Saying it BEFORE the first destructive write is the whole point.
        if (is_array($info['software'] ?? null) && $info['software'] !== []) {
            $info['environment'] = self::environmentDiff((array) $info['software']);
        }
        return rest_ensure_response($info);
    }

    /**
     * Compare an archive's SITE_METADATA software block against this
     * installation. Reports missing plugins (not installed here at all)
     * and version drift for the ones that are. The theme is shown as a
     * single row: a restore without its theme renders the archive's data
     * in the wrong skin, which the operator should hear about upfront.
     *
     * @param array<string,mixed> $software
     * @return array{missing:list<array<string,string>>,older:list<array<string,string>>,theme:array<string,string>}
     */
    public static function environmentDiff(array $software): array
    {
        if (!function_exists('get_plugins')) {
            $file = ABSPATH . 'wp-admin/includes/plugin.php';
            if (is_readable($file)) {
                require_once $file;
            }
        }
        $installed = function_exists('get_plugins') ? get_plugins() : [];
        $missing = [];
        $older = [];
        $sourcePlugins = (array) ($software['plugins'] ?? []);
        foreach ($sourcePlugins as $path => $meta) {
            $path = (string) $path;
            $meta = is_array($meta) ? $meta : [];
            $name = (string) ($meta['name'] ?? $path);
            $version = (string) ($meta['version'] ?? '');
            if (!isset($installed[$path])) {
                $missing[] = ['path' => $path, 'name' => $name, 'version' => $version];
                continue;
            }
            $local = (string) ($installed[$path]['Version'] ?? '');
            if ($version !== '' && $local !== '' && version_compare($local, $version, '<')) {
                $older[] = ['path' => $path, 'name' => $name, 'version' => $version, 'installed' => $local];
            }
        }
        $theme = (array) ($software['theme'] ?? []);
        $localTheme = function_exists('wp_get_theme') ? wp_get_theme() : null;
        $themeOut = [
            'source' => trim(((string) ($theme['name'] ?? '')) . ' ' . ((string) ($theme['version'] ?? ''))),
            'installed' => $localTheme !== null ? trim(((string) $localTheme->get('Name')) . ' ' . ((string) $localTheme->get('Version'))) : '',
        ];
        return ['missing' => $missing, 'older' => $older, 'theme' => $themeOut];
    }

    /**
     * Stream one part of an archive to the browser. archive_id is
     * sanitize_key'd (no traversal); part numbers are resolved through the
     * split-set layout so only real part files can be read.
     */
    public static function downloadArchive(\WP_REST_Request $req): \WP_REST_Response
    {
        $archiveId = (string) $req->get_param('archive_id');
        if (preg_match('/^[a-z0-9\-_]+$/', $archiveId) !== 1) {
            return new \WP_REST_Response(['code' => 'MUDRAVA_ARCHIVE_NOT_FOUND'], 404);
        }
        $part = max(1, (int) $req->get_param('part'));
        $paths = new Paths();
        $base = $paths->storageDir() . '/' . $archiveId . '.mudrava';
        $path = $part === 1 ? $base : sprintf('%s.part%04d', $base, $part);
        if (!is_file($path)) {
            return new \WP_REST_Response(['code' => 'MUDRAVA_ARCHIVE_NOT_FOUND'], 404);
        }
        try {
            self::streamArchivePart($path, $base, $part);
        } catch (\RuntimeException $error) {
            return new \WP_REST_Response(['code' => 'MUDRAVA_ARCHIVE_UNREADABLE'], 500);
        }
        exit;
    }

    private static function streamArchivePart(string $path, string $base, int $part): void
    {
        $h = @fopen($path, 'rb');
        if ($h === false) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_UNREADABLE');
        }
        nocache_headers();
        header('Content-Type: application/octet-stream');
        header(sprintf(
            'Content-Disposition: attachment; filename="%s"',
            $part === 1 ? basename($path) : sprintf('%s.part%04d', basename($base), $part)
        ));
        header('Content-Length: ' . (string) filesize($path));
        while (!feof($h)) {
            $chunk = fread($h, 1048576);
            if ($chunk === false) {
                break;
            }
            echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput -- binary stream, not HTML.
            flush();
        }
        fclose($h);
    }

    public static function uploadChunk(\WP_REST_Request $req): \WP_REST_Response
    {
        $uploadId = (string) $req->get_param('upload_id');
        $part = max(1, (int) $req->get_param('part'));
        $parts = max(1, (int) $req->get_param('parts'));
        $index = (int) $req->get_param('index');
        $total = (int) $req->get_param('total');
        $file = $req->get_file_params()['file'] ?? null;
        if (!is_array($file) || !isset($file['tmp_name']) || !is_string($file['tmp_name'])) {
            return new \WP_REST_Response(['code' => 'MUDRAVA_UPLOAD_CHUNK_MISSING'], 400);
        }
        $result = ChunkUpload::put(
            $uploadId,
            $part,
            $parts,
            $index,
            $total,
            $file['tmp_name'],
            (int) ($file['error'] ?? UPLOAD_ERR_OK)
        );
        if (is_wp_error($result)) {
            return new \WP_REST_Response(['code' => $result->get_error_code()], 400);
        }
        return rest_ensure_response($result);
    }

    public static function finalizeUpload(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = ChunkUpload::finalize((string) $req->get_param('upload_id'));
        if (is_wp_error($result)) {
            return new \WP_REST_Response(['code' => $result->get_error_code()], 400);
        }
        return rest_ensure_response($result);
    }

    public static function uploadStatus(\WP_REST_Request $req): \WP_REST_Response
    {
        $result = ChunkUpload::status((string) $req->get_param('upload_id'));
        if (is_wp_error($result)) {
            return new \WP_REST_Response(['code' => $result->get_error_code()], 400);
        }
        return rest_ensure_response($result);
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function publicJob(array $job): array
    {
        return [
            'kind'            => $job['kind'] ?? '',
            'revision'        => (int) ($job['revision'] ?? 0),
            'state'           => $job['state'] ?? '',
            'encrypted'       => isset($job['encrypted']) ? (bool) $job['encrypted'] : null,
            'archive_id'      => $job['archive_id'] ?? '',
            'restore_point_archive_id' => $job['restore_point_archive_id'] ?? '',
            'percent'         => $job['percent'] ?? 0,
            'phase'           => $job['phase'] ?? null,
            'logical_bytes'   => isset($job['logical_bytes']) ? (int) $job['logical_bytes'] : null,
            'current_part'    => $job['current_part'] ?? 1,
            'restored_rows'   => $job['restored_rows'] ?? null,
            'restored_files'  => $job['restored_files'] ?? null,
            'transform_failures' => $job['transform_failures'] ?? null,
            'error'           => $job['error'] ?? null,
            'rollback_state'  => $job['rollback_state'] ?? null,
            'rollback_error'  => $job['rollback_error'] ?? null,
            'note'            => $job['note'] ?? null,
            // Honest background-progress signal: cron demonstrably woke up
            // within the last 5 minutes on THIS host. False means the job
            // is resumable but may wait for a visitor until cron returns.
            'cron_alive'      => (time() - (int) get_option(JobRunner::CRON_BEAT, '0')) < 300,
        ];
    }
}
