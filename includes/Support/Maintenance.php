<?php

/**
 * Maintenance: measure the junk a site accumulates, then remove exactly
 * what the operator asked for, in bounded batches.
 *
 * Born from a production transfer where revisions, auto-drafts, spam and
 * expired transients were purged by hand before the export, because the
 * plugin could measure a site but not tidy it. The same job belongs inside
 * the plugin: dry-run counts first, batched deletes second, and a hard
 * refusal to touch anything whose deletion could eat live content
 * (unattached media and autoload pressure are reported, never pruned).
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Support;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Live maintenance counts must never come from cache.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from the runtime prefix; every value is prepared or int-cast.
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- LIMIT counts are validated integers, not placeholders.
final class Maintenance
{
    /** Rows deleted per statement; keeps locks short on live tables. */
    public const BATCH = 500;

    /** Safety valve: per item, max batch passes in one apply call. */
    private const MAX_PASSES = 20000;

    /** @var list<string> items the operator can select for deletion */
    public const CLEANABLE = ['revisions', 'auto_drafts', 'spam', 'trash', 'transients', 'orphan_meta'];

    /**
     * Honest dry run: one count per item, plus the info-only rows that
     * explain what apply will NOT touch.
     *
     * @param \wpdb|null $db injectable for tests; defaults to the global
     * @return array{items:list<array<string,mixed>>,cleanable_total:int,generated_at:int}
     */
    public static function report(?\wpdb $db = null): array
    {
        $db = $db ?? self::defaultDb();
        $items = [
            self::item('revisions', self::countRows($db, "SELECT COUNT(*) FROM {$db->prefix}posts WHERE post_type = 'revision'")),
            self::item('auto_drafts', self::countRows($db, "SELECT COUNT(*) FROM {$db->prefix}posts WHERE post_status = 'auto-draft'")),
            self::item('spam', self::countRows($db, "SELECT COUNT(*) FROM {$db->prefix}comments WHERE comment_approved = 'spam'")),
            self::item('trash', self::countRows($db, "SELECT COUNT(*) FROM {$db->prefix}comments WHERE comment_approved = 'trash'")),
            self::item('transients', self::countExpiredTransients($db)),
            self::item('orphan_meta', self::countRows(
                $db,
                "SELECT COUNT(*) FROM {$db->prefix}postmeta pm"
                . " WHERE NOT EXISTS (SELECT 1 FROM {$db->prefix}posts p WHERE p.ID = pm.post_id)"
            )),
        ];

        $total = 0;
        foreach ($items as $item) {
            if ($item['kind'] === 'clean') {
                $total += $item['count'];
            }
        }

        return [
            'items' => $items,
            'cleanable_total' => $total,
            'generated_at' => time(),
        ];
    }

    /**
     * Delete exactly the requested items. Anything not in CLEANABLE is
     * ignored, not guessed. Returns per-item deleted row counts plus a
     * fresh report so the caller can render the truth after the sweep.
     *
     * @param list<string> $items
     * @param \wpdb|null $db
     * @return array{deleted:array<string,int>,report:array<string,mixed>}
     */
    public static function apply(array $items, ?\wpdb $db = null): array
    {
        $db = $db ?? self::defaultDb();
        $deleted = [];
        foreach (self::CLEANABLE as $key) {
            if (!in_array($key, $items, true)) {
                continue;
            }
            $deleted[$key] = self::purge($db, $key);
        }
        return [
            'deleted' => $deleted,
            'report' => self::report($db),
        ];
    }

    private static function defaultDb(): \wpdb
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb;
    }

    /**
     * @return array<string,mixed>
     */
    private static function item(string $key, int $count): array
    {
        $labels = [
            'revisions' => ['clean', 'Post revisions'],
            'auto_drafts' => ['clean', 'Auto-drafts'],
            'spam' => ['clean', 'Spam comments'],
            'trash' => ['clean', 'Trashed comments'],
            'transients' => ['clean', 'Expired transients'],
            'orphan_meta' => ['clean', 'Postmeta of deleted posts'],
        ];
        [$kind, $label] = $labels[$key] ?? ['info', $key];
        return ['key' => $key, 'kind' => $kind, 'label' => $label, 'count' => $count];
    }

    private static function countRows(\wpdb $db, string $sql): int
    {
        return (int) $db->get_var($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /**
     * Quoted, escaped IN() list for names that came from the database
     * itself. addslashes+single quotes is the same escaping wpdb's own
     * prepare applies to %s on a standard UTF-8 connection.
     *
     * @param list<string> $values
     */
    private static function inList(array $values): string
    {
        return implode(',', array_map(
            static fn (string $v): string => "'" . addslashes($v) . "'",
            $values
        ));
    }

    /**
     * Expired transients visible in SQL: transient rows whose timeout is
     * gone or in the past, plus orphan or expired timeout rows themselves.
     * External object caches (memcached etc.) hold transients invisible to
     * SQL; the admin layer words that honestly.
     */
    private static function countExpiredTransients(\wpdb $db): int
    {
        $expired = (int) $db->get_var(
            "SELECT COUNT(*) FROM {$db->prefix}options o"
            . " WHERE o.option_name LIKE '\\_transient\\_%'"
            . " AND o.option_name NOT LIKE '\\_transient\\_timeout\\_%'"
            . " AND NOT EXISTS (SELECT 1 FROM {$db->prefix}options t"
            . " WHERE t.option_name = CONCAT('_transient_timeout_', SUBSTRING(o.option_name, 12))"
            . " AND CAST(t.option_value AS UNSIGNED) > UNIX_TIMESTAMP())"
        ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $timeouts = (int) $db->get_var(
            "SELECT COUNT(*) FROM {$db->prefix}options o"
            . " WHERE o.option_name LIKE '\\_transient\\_timeout\\_%'"
            . " AND (CAST(o.option_value AS UNSIGNED) <= UNIX_TIMESTAMP()"
            . " OR NOT EXISTS (SELECT 1 FROM {$db->prefix}options t"
            . " WHERE t.option_name = CONCAT('_transient_', SUBSTRING(o.option_name, 20))))"
        ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $expired + $timeouts;
    }

    private static function purge(\wpdb $db, string $key): int
    {
        switch ($key) {
            case 'revisions':
                return self::purgePosts($db, "post_type = 'revision'");
            case 'auto_drafts':
                return self::purgePosts($db, "post_status = 'auto-draft'");
            case 'spam':
                return self::purgeComments($db, "comment_approved = 'spam'");
            case 'trash':
                return self::purgeComments($db, "comment_approved = 'trash'");
            case 'transients':
                return self::purgeTransients($db);
            case 'orphan_meta':
                return self::purgeOrphanMeta($db);
            default:
                return 0;
        }
    }

    /**
     * Delete posts in batches and their postmeta with them, so the sweep
     * never leaves meta rows behind (which would just re-appear in the
     * orphan_meta row of the next report).
     */
    private static function purgePosts(\wpdb $db, string $clause): int
    {
        $total = 0;
        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $ids = $db->get_col(
                "SELECT ID FROM {$db->prefix}posts WHERE {$clause} LIMIT " . (int) self::BATCH
            ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $ids = array_map('intval', (array) $ids);
            if ($ids === []) {
                break;
            }
            $in = implode(',', $ids);
            $db->query("DELETE FROM {$db->prefix}posts WHERE ID IN ({$in})");
            $db->query("DELETE FROM {$db->prefix}postmeta WHERE post_id IN ({$in})");
            $total += count($ids);
            if (count($ids) < self::BATCH) {
                break;
            }
        }
        return $total;
    }

    private static function purgeComments(\wpdb $db, string $clause): int
    {
        $total = 0;
        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $ids = $db->get_col(
                "SELECT comment_ID FROM {$db->prefix}comments WHERE {$clause} LIMIT " . (int) self::BATCH
            ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $ids = array_map('intval', (array) $ids);
            if ($ids === []) {
                break;
            }
            $in = implode(',', $ids);
            $db->query("DELETE FROM {$db->prefix}comments WHERE comment_ID IN ({$in})");
            $db->query("DELETE FROM {$db->prefix}commentmeta WHERE comment_id IN ({$in})");
            $total += count($ids);
            if (count($ids) < self::BATCH) {
                break;
            }
        }
        return $total;
    }

    /**
     * Expired transients first, then the timeout rows that outlived them.
     * Option names are quoted through prepare, never interpolated raw.
     */
    private static function purgeTransients(\wpdb $db): int
    {
        $total = 0;
        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $names = $db->get_col(
                "SELECT o.option_name FROM {$db->prefix}options o"
                . " WHERE o.option_name LIKE '\\_transient\\_%'"
                . " AND o.option_name NOT LIKE '\\_transient\\_timeout\\_%'"
                . " AND NOT EXISTS (SELECT 1 FROM {$db->prefix}options t"
                . " WHERE t.option_name = CONCAT('_transient_timeout_', SUBSTRING(o.option_name, 12))"
                . " AND CAST(t.option_value AS UNSIGNED) > UNIX_TIMESTAMP())"
                . ' LIMIT ' . (int) self::BATCH
            ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $names = array_values(array_filter(array_map('strval', (array) $names), static fn (string $n): bool => $n !== ''));
            if ($names === []) {
                break;
            }
            $timeoutNames = array_map(static fn (string $n): string => '_transient_timeout_' . substr($n, 11), $names);
            $all = array_merge($names, $timeoutNames);
            $db->query(
                "DELETE FROM {$db->prefix}options WHERE option_name IN (" . self::inList($all) . ')'
            ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- names escaped through inList()
            $total += count($names);
            if (count($names) < self::BATCH) {
                break;
            }
        }
        // Orphan or expired timeout rows: name derived from an existing
        // transient is already gone above; what remains references nothing.
        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $names = $db->get_col(
                "SELECT o.option_name FROM {$db->prefix}options o"
                . " WHERE o.option_name LIKE '\\_transient\\_timeout\\_%'"
                . " AND (CAST(o.option_value AS UNSIGNED) <= UNIX_TIMESTAMP()"
                . " OR NOT EXISTS (SELECT 1 FROM {$db->prefix}options t"
                . " WHERE t.option_name = CONCAT('_transient_', SUBSTRING(o.option_name, 20))))"
                . ' LIMIT ' . (int) self::BATCH
            ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $names = array_values(array_filter(array_map('strval', (array) $names), static fn (string $n): bool => $n !== ''));
            if ($names === []) {
                break;
            }
            $db->query(
                "DELETE FROM {$db->prefix}options WHERE option_name IN (" . self::inList($names) . ')'
            ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- names escaped through inList()
            $total += count($names);
            if (count($names) < self::BATCH) {
                break;
            }
        }
        return $total;
    }

    private static function purgeOrphanMeta(\wpdb $db): int
    {
        $total = 0;
        for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
            $ids = $db->get_col(
                "SELECT pm.meta_id FROM {$db->prefix}postmeta pm"
                . " WHERE NOT EXISTS (SELECT 1 FROM {$db->prefix}posts p WHERE p.ID = pm.post_id)"
                . ' LIMIT ' . (int) self::BATCH
            ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $ids = array_map('intval', (array) $ids);
            if ($ids === []) {
                break;
            }
            $in = implode(',', $ids);
            $db->query("DELETE FROM {$db->prefix}postmeta WHERE meta_id IN ({$in})");
            $total += count($ids);
            if (count($ids) < self::BATCH) {
                break;
            }
        }
        return $total;
    }

    /**
     * Info rows the report shows but apply refuses to prune: honest limits
     * of a maintenance sweep (media files need their own audit trail,
     * autoload is a tuning question, not a trash question).
     *
     * @param \wpdb|null $db
     * @return array{unattached_media:int,autoload_rows:int,autoload_bytes:int}
     */
    public static function advisories(?\wpdb $db = null): array
    {
        $db = $db ?? self::defaultDb();
        $unattached = self::countRows($db, "SELECT COUNT(*) FROM {$db->prefix}posts WHERE post_type = 'attachment' AND post_parent = 0");
        $autoload = $db->get_row(
            // ROWS is a reserved word on MariaDB, so the aliases are neutral.
            "SELECT COUNT(*) AS n_rows, COALESCE(SUM(LENGTH(option_value)), 0) AS n_bytes"
            . " FROM {$db->prefix}options WHERE autoload IN ('yes', 'on')",
            ARRAY_A
        );
        return [
            'unattached_media' => $unattached,
            'autoload_rows' => (int) ($autoload['n_rows'] ?? 0),
            'autoload_bytes' => (int) ($autoload['n_bytes'] ?? 0),
        ];
    }
}
