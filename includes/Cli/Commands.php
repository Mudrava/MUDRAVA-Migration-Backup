<?php

/**
 * WP-CLI bridge: the headless surface of the plugin.
 *
 * The browser UI and this command group drive the SAME durable job
 * machine (JobRunner) through the SAME honest gates (Preflight,
 * ImportOps, RestorePoint). Nothing here bypasses a single web gate: a
 * scripted restore passes the same set-completeness and restore-point
 * checks a human passes. The difference is direction: the CLI is built
 * for ssh sessions, CI and automation agents, so output is JSON by
 * default, errors are stable MUDRAVA_* codes on stderr, and exit codes
 * are contractual (0 done, 1 refusal or failure, 2 job still running
 * when --max-runtime expired).
 *
 * Registered only when WP_CLI is loaded. Commands require a user who
 * holds the migrator capability: run with --user=<admin-id>.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Cli;

use Mudrava\Migration\Http\RestApi;
use Mudrava\Migration\Migration\ImportOps;
use Mudrava\Migration\Migration\JobRunner;
use Mudrava\Migration\Support\Capabilities;
use Mudrava\Migration\Support\Maintenance;
use Mudrava\Migration\Support\Paths;
use Mudrava\Migration\Support\Preflight;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- binary archive copies and file stats on local disk.
final class Commands
{
    /**
     * Environment readiness: the same checks the Environment tab shows.
     *
     * ## OPTIONS
     * [--format=<format>]
     * : Output shape. json (default) or table.
     *
     * ## EXAMPLES
     *     wp mudrava preflight
     *
     * @param array<int,string> $args
     * @param array<string,string> $assoc
     */
    public function preflight(array $args, array $assoc): void
    {
        unset($args);
        $this->requireCap();
        $this->emit(Preflight::run(), $assoc, ['code', 'ok', 'detail']);
    }

    /**
     * Live inventory: every table and wp-content folder with honest sizes.
     *
     * ## EXAMPLES
     *     wp mudrava inventory
     *
     * @param array<int,string> $args
     * @param array<string,string> $assoc
     */
    public function inventory(array $args, array $assoc): void
    {
        unset($args);
        $this->requireCap();
        $this->emit(\Mudrava\Migration\Support\Inventory::run(), $assoc, []);
    }

    /**
     * One machine-readable document for automation: readiness, sizes,
     * software inventory, maintenance counts, archives. Diff this before
     * and after a migration to prove what moved and what was cleaned.
     *
     * ## EXAMPLES
     *     wp mudrava report > before.json
     *
     * @param array<int,string> $args
     * @param array<string,string> $assoc
     */
    public function report(array $args, array $assoc): void
    {
        unset($args);
        $this->requireCap();
        $this->emit([
            'preflight' => Preflight::run(),
            'software' => \Mudrava\Migration\Support\Software::current(),
            'maintenance' => Maintenance::report(),
            'advisories' => Maintenance::advisories(),
            'archives' => $this->archivesData(),
            'generated_at' => time(),
        ], $assoc, []);
    }

    /**
     * Maintenance dry run: junk counts per item plus what will never be
     * pruned automatically (advisories). Add --apply to delete.
     *
     * ## OPTIONS
     * [--apply]
     * : Actually delete. Without it nothing is touched (dry run).
     * [--items=<list>]
     * : Comma list of items to apply. Default: every cleanable item.
     *
     * ## EXAMPLES
     *     wp mudrava maintenance
     *     wp mudrava maintenance --apply --items=revisions,transients
     *
     * @param array<int,string> $args
     * @param array<string,string> $assoc
     */
    public function maintenance(array $args, array $assoc): void
    {
        unset($args);
        $this->requireCap();
        if (!isset($assoc['apply'])) {
            $this->emit([
                'apply' => false,
                'report' => Maintenance::report(),
                'advisories' => Maintenance::advisories(),
                'cleanable' => Maintenance::CLEANABLE,
            ], $assoc, []);
            return;
        }
        $items = Maintenance::CLEANABLE;
        if (isset($assoc['items'])) {
            $wanted = array_map('trim', explode(',', (string) $assoc['items']));
            $items = array_values(array_intersect($items, $wanted));
            if ($items === []) {
                \WP_CLI::error('MUDRAVA_VALIDATION: no known items in --items. Cleanable: ' . implode(',', Maintenance::CLEANABLE));
            }
        }
        $result = Maintenance::apply($items);
        $this->emit(array_merge(['apply' => true], $result), $assoc, []);
        \WP_CLI::success(sprintf('cleaned %d items', array_sum($result['deleted'])));
    }

    /**
     * Export this site, driving the job in-process until it is done.
     * The same resumable checkpointed job the UI starts: interrupt the
     * command with ctrl-c or a host timeout and any later tick (cron,
     * browser, or this command again) continues exactly where it stopped.
     *
     * ## OPTIONS
     * [--password=<password>]
     * : Encrypt the archive with this password (XChaCha20-Poly1305 + Argon2id).
     * [--hint=<hint>]
     * : Public password hint stored in the archive header. Never put the password in it.
     * [--split-mb=<mb>]
     * : Split into parts of this size in MB. 0 (default) means one file.
     * [--exclude-tables=<list>]
     * : Comma list of table names to leave out of the archive.
     * [--exclude-dirs=<list>]
     * : Comma list of wp-content-relative directories to leave out.
     * [--max-runtime=<seconds>]
     * : Stop after this many seconds, leaving the job resumable. 0 runs to completion.
     *
     * ## EXAMPLES
     *     wp mudrava export --split-mb=512
     *     wp mudrava export --password='correct horse' --hint=vault --max-runtime=300
     *
     * @param array<int,string> $args
     * @param array<string,string> $assoc
     */
    public function export(array $args, array $assoc): void
    {
        unset($args);
        $this->requireCap();
        $password = isset($assoc['password']) ? (string) $assoc['password'] : null;
        $split = isset($assoc['split-mb']) ? max(0, (int) $assoc['split-mb']) * 1048576 : 0;
        $excludes = RestApi::sanitizeExcludes([
            'tables' => isset($assoc['exclude-tables']) ? explode(',', (string) $assoc['exclude-tables']) : [],
            'dirs' => isset($assoc['exclude-dirs']) ? explode(',', (string) $assoc['exclude-dirs']) : [],
        ]);
        $job = JobRunner::startExportJob([
            'site_meta' => Preflight::siteMeta(),
            'split_bytes' => $split > 0 ? $split : null,
            'password_hint' => isset($assoc['hint']) ? (string) $assoc['hint'] : '',
            'encrypted' => $password !== null && $password !== '',
            'excludes' => $excludes,
        ]);
        $this->drain($job, $password, isset($assoc['max-runtime']) ? (int) $assoc['max-runtime'] : 0);
    }

    /**
     * Current job state, same shape the REST /job endpoint returns.
     *
     * ## EXAMPLES
     *     wp mudrava status
     *
     * @param array<int,string> $args
     * @param array<string,string> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        unset($args);
        $this->requireCap();
        $job = JobRunner::load();
        if ($job === null) {
            $this->emit(['state' => 'idle'], $assoc, []);
            return;
        }
        $this->emit(RestApi::publicJob($job), $assoc, []);
    }

    /**
     * Continue the running job (after a --max-runtime pause, a cron gap,
     * or an interrupted terminal session) until it finishes.
     *
     * ## OPTIONS
     * [--password=<password>]
     * : Password for encrypted archives, needed while the job is past its checkpoint.
     * [--max-runtime=<seconds>]
     * : Stop after this many seconds, leaving the job resumable. 0 runs to completion.
     *
     * ## EXAMPLES
     *     wp mudrava resume --max-runtime=300
     *
     * @param array<int,string> $args
     * @param array<string,string> $assoc
     */
    public function resume(array $args, array $assoc): void
    {
        unset($args);
        $this->requireCap();
        $job = JobRunner::load();
        if ($job === null) {
            \WP_CLI::error('MUDRAVA_JOB_NOT_FOUND: no job to resume.');
        }
        $state = (string) ($job['state'] ?? '');
        if ($state === 'done' || $state === 'failed') {
            \WP_CLI::error('job already ' . $state . '; nothing to resume.');
        }
        $password = isset($assoc['password']) ? (string) $assoc['password'] : null;
        $this->drain($job, $password, isset($assoc['max-runtime']) ? (int) $assoc['max-runtime'] : 0);
    }

    /**
     * Cancel the running job cooperatively (at the next frame boundary).
     *
     * ## EXAMPLES
     *     wp mudrava abort
     *
     * @param array<int,string> $args
     * @param array<string,string> $assoc
     */
    public function abort(array $args, array $assoc): void
    {
        unset($args, $assoc);
        $this->requireCap();
        JobRunner::cancelExport();
        \Mudrava\Migration\Http\RestoreToken::clear();
        \WP_CLI::success('job cancelled');
    }

    /**
     * Archives in private storage with honest part and size facts.
     *
     * ## EXAMPLES
     *     wp mudrava archives
     *
     * @param array<int,string> $args
     * @param array<string,string> $assoc
     */
    public function archives(array $args, array $assoc): void
    {
        unset($args);
        $this->requireCap();
        $data = $this->archivesData();
        $this->emit($data, $assoc, ['archive_id', 'parts', 'bytes', 'encrypted', 'source_url']);
    }

    /**
     * Peek inside an archive: source URL, encryption, and the environment
     * diff (what the archive has that this site does not) for restores.
     *
     * ## SYNOPSIS
     * `wp mudrava archiveInfo <archive> [--password=<password>]`
     *
     * ## OPTIONS
     * <archive>
     * : Archive id from private storage.
     * [--password=<password>]
     * : Password for sealed archives (metadata is sealed with the payload).
     *
     * ## EXAMPLES
     *     wp mudrava archiveInfo a1b2c3
     *
     * @param array<int,string> $args
     * @param array<string,string> $assoc
     */
    public function archiveInfo(array $args, array $assoc): void
    {
        $this->requireCap();
        $archiveId = $this->archiveIdArg((string) ($args[0] ?? ''));
        $paths = new Paths();
        $base = $paths->storageDir() . '/' . $archiveId . '.mudrava';
        if (!is_file($base)) {
            \WP_CLI::error('MUDRAVA_ARCHIVE_NOT_FOUND: ' . $archiveId);
        }
        $password = isset($assoc['password']) ? (string) $assoc['password'] : null;
        $info = \Mudrava\Migration\Archive\ArchiveInfo::peek($base, $password);
        if ($info['software'] !== []) {
            $info['environment'] = RestApi::environmentDiff($info['software']);
        }
        $this->emit($info, $assoc, []);
    }

    /**
     * Restore an archive onto this site, headless, same gates as the UI:
     * provable set completeness, a restore point when disk affords it,
     * explicit --proceed-unsafe acknowledgement when it does not.
     *
     * Accepts an archive id from private storage, or a filesystem path to
     * a .mudrava file (every sibling .partNNNN of the same set is staged
     * into private storage alongside it first).
     *
     * ## SYNOPSIS
     * `wp mudrava import <archive> [--password=<password>] [--url-from=<url>] [--url-to=<url>] [--proceed-unsafe] [--max-runtime=<seconds>]`
     *
     * ## OPTIONS
     * <archive>
     * : Archive id from private storage, or a path to a .mudrava file.
     * [--password=<password>]
     * : Password for encrypted archives.
     * [--url-from=<url>] [--url-to=<url>]
     * : URL rewrite pair. Default: derive source -> this site from the archive.
     * [--proceed-unsafe]
     * : Restore without a restore point. Only when disk cannot hold one.
     * [--max-runtime=<seconds>]
     * : Stop ticking after this many seconds; the job stays resumable via `wp mudrava resume`.
     *
     * ## EXAMPLES
     *     wp mudrava import /root/backups/site.mudrava
     *     wp mudrava import a1b2c3 --proceed-unsafe
     *
     * @param array<int,string> $args
     * @param array<string,string> $assoc
     */
    public function import(array $args, array $assoc): void
    {
        $this->requireCap();
        if ($args === []) {
            \WP_CLI::error('usage: wp mudrava import <archive-id | /path/to/file.mudrava>');
        }
        $source = (string) $args[0];
        if (is_file($source)) {
            $archiveId = $this->stageFile($source);
        } else {
            $archiveId = $this->archiveIdArg($source);
        }
        $paths = new Paths();
        $base = $paths->storageDir() . '/' . $archiveId . '.mudrava';
        $expected = ImportOps::countSetParts($base);
        $proceedUnsafe = isset($assoc['proceed-unsafe']);
        $restorePointId = '';
        if (!$proceedUnsafe) {
            $restorePointId = $this->makeRestorePoint();
        }
        $urlFrom = isset($assoc['url-from']) ? esc_url_raw((string) $assoc['url-from']) : '';
        $urlTo = isset($assoc['url-to']) ? esc_url_raw((string) $assoc['url-to']) : '';
        $job = ImportOps::start(
            $archiveId,
            $expected,
            [
                'search' => $urlFrom,
                'replace' => $urlTo,
            ],
            $proceedUnsafe,
            $restorePointId
        );
        \WP_CLI::log(sprintf(
            'restoring %s (%d part%s) url %s -> %s',
            $archiveId,
            $expected,
            $expected > 1 ? 's' : '',
            $urlFrom !== '' ? $urlFrom : '(derived from archive)',
            $urlTo !== '' ? $urlTo : home_url()
        ));
        $this->drain($job, isset($assoc['password']) ? (string) $assoc['password'] : null, isset($assoc['max-runtime']) ? (int) $assoc['max-runtime'] : 0);
        \WP_CLI::warning('flush permalinks: wp rewrite flush && verify pages, menus and media on the front end.');
    }

    // ---------- internals ----------

    private function requireCap(): void
    {
        if (!current_user_can(Capabilities::CAP)) {
            $who = wp_get_current_user()->exists() ? wp_get_current_user()->user_login : '(no user)';
            \WP_CLI::error(sprintf(
                'MUDRAVA_PERMISSION_DENIED: "%s" cannot migrate. Re-run with --user=<administrator-id>.',
                $who
            ));
        }
    }

    private function archiveIdArg(string $id): string
    {
        if ($id === '' || preg_match('/^[a-z0-9\-_]+$/', $id) !== 1) {
            \WP_CLI::error('MUDRAVA_ARCHIVE_NOT_FOUND: archive id must match [a-z0-9_-]+');
        }
        return $id;
    }

    /**
     * Copy a .mudrava file (plus its sibling parts) into private storage.
     * Returns the archive id. Refuses ambiguous names instead of guessing.
     */
    private function stageFile(string $source): string
    {
        $name = basename($source);
        if (preg_match('/^([a-z0-9\-_]+)\.mudrava$/i', $name) !== 1) {
            \WP_CLI::error('pass the base file of the set (NAME.mudrava); parts (NAME.mudrava.partNNNN) are staged with it');
        }
        $archiveId = strtolower(substr($name, 0, -strlen('.mudrava')));
        if (preg_match('/^[a-z0-9\-_]+$/', $archiveId) !== 1) {
            \WP_CLI::error('MUDRAVA_PATH_UNSAFE: archive name must be [A-Za-z0-9_-]+.mudrava');
        }
        $paths = new Paths();
        $dir = $paths->ensureStorage();
        $base = $dir . '/' . $archiveId . '.mudrava';
        if (is_file($base)) {
            \WP_CLI::error('MUDRAVA_ARCHIVE_CHANGED: ' . $archiveId . '.mudrava already exists in private storage; delete or rename it first');
        }
        if (@rename($source, $base) !== true) {
            if (!@copy($source, $base)) {
                \WP_CLI::error('MUDRAVA_PERMISSION_DENIED: could not stage ' . $name . ' into private storage');
            }
        }
        $part = 2;
        $staged = 0;
        // Split parts are numbered from 0002; part 1 is the base file itself.
        while (is_file(sprintf('%s.part%04d', $source, $part))) {
            $from = sprintf('%s.part%04d', $source, $part);
            $to = sprintf('%s.part%04d', $base, $part);
            if (@rename($from, $to) !== true && !@copy($from, $to)) {
                \WP_CLI::error('MUDRAVA_PERMISSION_DENIED: could not stage part ' . $part);
            }
            $staged++;
            $part++;
        }
        $stray = glob(sprintf('%s.part[0-9][0-9][0-9][0-9]', $source));
        if (is_array($stray) && $stray !== []) {
            \WP_CLI::error(sprintf(
                'MUDRAVA_SET_INCOMPLETE: part(s) next to %s stop at 000%d but %d part file(s) exist; the set on disk has a gap',
                $name,
                $part - 1,
                count($stray)
            ));
        }
        \WP_CLI::log(sprintf('staged %s (+%d part%s) into private storage', $name, $staged, $staged === 1 ? '' : 's'));
        return $archiveId;
    }

    /**
     * Headless restore point: the same export job the browser fires,
     * ticked to completion here. Honest refusal when disk cannot afford it.
     */
    private function makeRestorePoint(): string
    {
        $headroom = \Mudrava\Migration\Rollback\RestorePoint::headroom();
        if (!$headroom['safe']) {
            \WP_CLI::error(sprintf(
                'MUDRAVA_RESTORE_POINT_SPACE: safe restore point needs %s bytes, %s free. Free disk or pass --proceed-unsafe.',
                (string) $headroom['needed'],
                (string) $headroom['free']
            ));
        }
        \WP_CLI::log('saving restore point of this site...');
        $job = JobRunner::startExportJob([
            'site_meta' => Preflight::siteMeta(),
            'purpose'   => 'restore_point',
        ]);
        $final = $this->drain($job, null, 0, true);
        return (string) $final['archive_id'];
    }

    /**
     * Tick a started job until terminal, --max-runtime expires, or a
     * fatal gate throws. Quiet mode is used internally for restore points.
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private function drain(array $job, ?string $password, int $maxRuntime, bool $quiet = false): array
    {
        $start = time();
        while (true) {
            $job = JobRunner::tick($password);
            $state = (string) ($job['state'] ?? '');
            if ($state === 'failed') {
                if (!$quiet) {
                    $this->emit(RestApi::publicJob($job), [], []);
                }
                \WP_CLI::error('job failed: ' . (string) ($job['error'] ?? 'unknown'));
            }
            if ($state === 'done') {
                break;
            }
            if (!$quiet) {
                $pct = isset($job['percent']) ? (int) $job['percent'] : 0;
                \WP_CLI::log(sprintf('[%s] %d%%', (string) ($job['phase'] ?? $state), $pct));
            }
            if (($job['note'] ?? '') === 'password_required') {
                if (!$quiet) {
                    $this->emit(RestApi::publicJob($job), [], []);
                }
                \WP_CLI::error('the archive is encrypted; re-run with --password=<password> to continue from the checkpoint.');
            }
            if ($maxRuntime > 0 && time() - $start >= $maxRuntime) {
                if (!$quiet) {
                    $this->emit(array_merge(RestApi::publicJob($job), ['resumable' => true]), [], []);
                    \WP_CLI::warning(sprintf('max-runtime reached after %ds; job is resumable (wp mudrava resume)', $maxRuntime));
                }
                // Exit code 2: "still running", distinct from refusal (1).
                \WP_CLI::halt(2);
            }
        }
        if (!$quiet) {
            $this->emit(RestApi::publicJob($job), [], []);
            \WP_CLI::success(sprintf(
                '%s done: %s',
                (string) ($job['kind'] ?? 'job'),
                (string) ($job['archive_id'] ?? '')
            ));
        }
        return $job;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function archivesData(): array
    {
        $paths = new Paths();
        $dir = $paths->storageDir();
        $out = [];
        $glob = is_dir($dir) ? glob($dir . '/*.mudrava') : [];
        foreach ((array) $glob as $base) {
            if (!is_string($base) || !is_file($base)) {
                continue;
            }
            $inspect = \Mudrava\Migration\Storage\SplitSetSource::inspect($base);
            if ($inspect['found'] === 0) {
                continue;
            }
            $size = 0;
            foreach ($inspect['parts'] as $p) {
                $size += (int) filesize($p);
            }
            $info = \Mudrava\Migration\Archive\ArchiveInfo::peek($base);
            $out[] = [
                'archive_id' => basename($base, '.mudrava'),
                'path'       => $base,
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
        return $out;
    }

    /**
     * JSON by default (the automation contract); --format=table renders
     * a list of row objects for humans, falling back to JSON when the
     * payload is not shaped as a table.
     *
     * @param array<array-key,mixed> $data
     * @param array<string,string> $assoc
     * @param list<string> $columns
     */
    private function emit(array $data, array $assoc, array $columns): void
    {
        $format = $assoc['format'] ?? 'json';
        $first = $data === [] ? null : reset($data);
        if ($format === 'table' && $columns !== [] && is_array($first)) {
            \WP_CLI\Utils\format_items('table', $data, $columns);
            return;
        }
        if ($format === 'table' && $data === []) {
            \WP_CLI::log('(empty)');
            return;
        }
        \WP_CLI::log((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
