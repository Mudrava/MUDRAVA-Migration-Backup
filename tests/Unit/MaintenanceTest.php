<?php

/**
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Support\Maintenance;
use PHPUnit\Framework\TestCase;

/**
 * In-memory wpdb double: recognizes the exact SQL shapes Maintenance emits
 * and mutates arrays the way the real engine mutates tables, so the
 * batch/delete loop is tested end to end, not mocked into vacuity.
 */
final class FakeMaintenanceDb extends \wpdb
{
    /** @var array<int,array{ID:int,type:string,status:string}> */
    public $posts = [];
    /** @var array<int,array{comment_ID:int,approved:string}> */
    public $comments = [];
    /** @var array<int,array{post_id:int}> */
    public $postmeta = [];
    /** @var array<int,array{comment_id:int}> */
    public $commentmeta = [];
    /** @var array<int,array{option_name:string,option_value:string,autoload:string}> */
    public $options = [];
    /** @var list<string> */
    public $deletes = [];

    public function get_var($sql)
    {
        $sql = (string) $sql;
        if (strpos($sql, "post_type = 'revision'") !== false) {
            return (string) $this->countPosts(fn (array $p): bool => $p['type'] === 'revision');
        }
        if (strpos($sql, "post_status = 'auto-draft'") !== false) {
            return (string) $this->countPosts(fn (array $p): bool => $p['status'] === 'auto-draft');
        }        if (strpos($sql, "comment_approved = 'spam'") !== false) {
            return (string) count(array_filter($this->comments, fn (array $c): bool => $c['approved'] === 'spam'));
        }
        if (strpos($sql, "comment_approved = 'trash'") !== false) {
            return (string) count(array_filter($this->comments, fn (array $c): bool => $c['approved'] === 'trash'));
        }
        if (strpos($sql, "NOT LIKE '\\_transient\\_timeout") !== false) {
            return (string) count($this->expiredTransientNames());
        }
        if (strpos($sql, "LIKE '\\_transient\\_timeout") !== false) {
            return (string) count($this->expiredTimeoutNames());
        }
        if (strpos($sql, 'post_type = \'attachment\'') !== false) {
            return (string) $this->countPosts(fn (array $p): bool => $p['type'] === 'attachment');
        }
        if (strpos($sql, 'NOT EXISTS (SELECT 1 FROM wp_posts p WHERE p.ID = pm.post_id)') !== false) {
            return (string) count($this->orphanMetaIds());
        }
        return '0';
    }

    public function get_row($sql, $format = null)
    {
        $rows = 0;
        $bytes = 0;
        foreach ($this->options as $o) {
            if ($o['autoload'] === 'yes' || $o['autoload'] === 'on') {
                $rows++;
                $bytes += strlen($o['option_value']);
            }
        }
        return ['n_rows' => (string) $rows, 'n_bytes' => (string) $bytes];
    }

    public function get_col($sql)
    {
        $sql = (string) $sql;
        $limit = preg_match('/LIMIT (\d+)$/', $sql, $m) === 1 ? (int) $m[1] : 500;
        if (strpos($sql, 'SELECT ID FROM') !== false) {
            $ids = [];
            foreach ($this->posts as $p) {
                $wanted = strpos($sql, "post_type = 'revision'") !== false
                    ? $p['type'] === 'revision'
                    : $p['status'] === 'auto-draft';
                if ($wanted) {
                    $ids[] = (string) $p['ID'];
                }
                if (count($ids) >= $limit) {
                    break;
                }
            }
            return $ids;
        }
        if (strpos($sql, 'SELECT comment_ID FROM') !== false) {
            $ids = [];
            foreach ($this->comments as $c) {
                $wanted = strpos($sql, "'spam'") !== false ? $c['approved'] === 'spam' : $c['approved'] === 'trash';
                if ($wanted) {
                    $ids[] = (string) $c['comment_ID'];
                }
                if (count($ids) >= $limit) {
                    break;
                }
            }
            return $ids;
        }
        if (strpos($sql, 'SELECT meta_id FROM') !== false) {
            return array_slice(array_map('strval', $this->orphanMetaIds()), 0, $limit);
        }
        if (strpos($sql, 'SELECT o.option_name FROM') !== false) {
            $names = strpos($sql, "NOT LIKE '\\_transient\\_timeout") !== false
                ? $this->expiredTransientNames()
                : $this->expiredTimeoutNames();
            return array_slice($names, 0, $limit);
        }
        return [];
    }

    public function query($sql)
    {
        $sql = (string) $sql;
        $this->deletes[] = $sql;
        if (preg_match('/DELETE FROM wp_posts WHERE ID IN \(([^)]*)\)/', $sql, $m) === 1) {
            $ids = array_map('intval', explode(',', $m[1]));
            $this->posts = array_values(array_filter($this->posts, static fn (array $p): bool => !in_array($p['ID'], $ids, true)));
            return count($ids);
        }
        if (preg_match('/DELETE FROM wp_postmeta WHERE post_id IN \(([^)]*)\)/', $sql, $m) === 1) {
            $ids = array_map('intval', explode(',', $m[1]));
            $this->postmeta = array_values(array_filter($this->postmeta, static fn (array $r): bool => !in_array($r['post_id'], $ids, true)));
            return count($ids);
        }
        if (preg_match('/DELETE FROM wp_postmeta WHERE meta_id IN \(([^)]*)\)/', $sql, $m) === 1) {
            $ids = array_map('intval', explode(',', $m[1]));
            $this->postmeta = array_values(array_filter(
                $this->postmeta,
                static function (array $r) use ($ids): bool {
                    foreach ($ids as $id) {
                        if ((int) $r['meta_id'] === $id) {
                            return false;
                        }
                    }
                    return true;
                }
            ));
            return count($ids);
        }
        if (preg_match('/DELETE FROM wp_comments WHERE comment_ID IN \(([^)]*)\)/', $sql, $m) === 1) {
            $ids = array_map('intval', explode(',', $m[1]));
            $this->comments = array_values(array_filter($this->comments, static fn (array $c): bool => !in_array($c['comment_ID'], $ids, true)));
            return count($ids);
        }
        if (preg_match('/DELETE FROM wp_commentmeta WHERE comment_id IN \(([^)]*)\)/', $sql, $m) === 1) {
            $ids = array_map('intval', explode(',', $m[1]));
            $this->commentmeta = array_values(array_filter($this->commentmeta, static fn (array $r): bool => !in_array($r['comment_id'], $ids, true)));
            return count($ids);
        }
        if (preg_match('/DELETE FROM wp_options WHERE option_name IN \(([^)]*)\)/', $sql, $m) === 1) {
            $names = array_map([FakeMaintenanceDb::class, 'unquote'], array_map('trim', explode(',', $m[1])));
            $before = count($this->options);
            $this->options = array_values(array_filter(
                $this->options,
                static fn (array $o): bool => !in_array($o['option_name'], $names, true)
            ));
            return $before - count($this->options);
        }
        return 0;
    }

    /**
     * Prepare in the real wpdb quotes %s values; mimic just enough for the
     * IN() parser above.
     */
    public function prepare(string $sql, ...$args): string
    {
        foreach ($args as $arg) {
            $sql = (string) preg_replace_callback('/%[sd]/', static function (array $match) use ($arg): string {
                return $match[0] === '%d' ? (string) (int) $arg : "'" . addslashes((string) $arg) . "'";
            }, $sql, 1);
        }
        return $sql;
    }

    public static function unquote(string $value): string
    {
        return trim($value, "' ");
    }

    public function addPost(int $id, string $type, string $status): void
    {
        $this->posts[] = ['ID' => $id, 'type' => $type, 'status' => $status];
    }

    /** @return int */
    private function countPosts(callable $fn): int
    {
        $n = 0;
        foreach ($this->posts as $p) {
            if ($fn($p)) {
                $n++;
            }
        }
        return $n;
    }

    /** @return list<int> */
    private function orphanMetaIds(): array
    {
        $live = array_map(static fn (array $p): int => $p['ID'], $this->posts);
        $ids = [];
        foreach ($this->postmeta as $row) {
            if (!in_array($row['post_id'], $live, true)) {
                $ids[] = (int) $row['meta_id'];
            }
        }
        return $ids;
    }

    /** @return list<string> */
    private function expiredTransientNames(): array
    {
        $names = [];
        foreach ($this->options as $o) {
            if (strpos($o['option_name'], '_transient_') !== 0
                || strpos($o['option_name'], '_transient_timeout_') === 0) {
                continue;
            }
            $timeout = null;
            foreach ($this->options as $t) {
                if ($t['option_name'] === '_transient_timeout_' . substr($o['option_name'], 11)) {
                    $timeout = (int) $t['option_value'];
                    break;
                }
            }
            if ($timeout === null || $timeout <= time()) {
                $names[] = $o['option_name'];
            }
        }
        return $names;
    }

    /** @return list<string> */
    private function expiredTimeoutNames(): array
    {
        $names = [];
        foreach ($this->options as $o) {
            if (strpos($o['option_name'], '_transient_timeout_') !== 0) {
                continue;
            }
            $hasTransient = false;
            foreach ($this->options as $t) {
                if ($t['option_name'] === '_transient_' . substr($o['option_name'], 19)) {
                    $hasTransient = true;
                    break;
                }
            }
            if (!$hasTransient || (int) $o['option_value'] <= time()) {
                $names[] = $o['option_name'];
            }
        }
        return $names;
    }
}

final class MaintenanceTest extends TestCase
{
    private function seed(): FakeMaintenanceDb
    {
        $db = new FakeMaintenanceDb();
        $db->addPost(1, 'post', 'publish');
        $db->addPost(2, 'revision', 'inherit');
        $db->addPost(3, 'revision', 'inherit');
        $db->addPost(4, 'post', 'auto-draft');
        $db->addPost(5, 'attachment', 'inherit');
        $db->comments[] = ['comment_ID' => 10, 'approved' => 'spam'];
        $db->comments[] = ['comment_ID' => 11, 'approved' => 'trash'];
        $db->comments[] = ['comment_ID' => 12, 'approved' => '1'];
        $db->postmeta[] = ['post_id' => 1, 'meta_id' => 1];
        $db->postmeta[] = ['post_id' => 999, 'meta_id' => 2];
        $db->commentmeta[] = ['comment_id' => 10];
        $db->options[] = ['option_name' => '_transient_stale', 'option_value' => 'x', 'autoload' => 'no'];
        $db->options[] = ['option_name' => '_transient_timeout_stale', 'option_value' => (string) (time() - 10), 'autoload' => 'no'];
        $db->options[] = ['option_name' => '_transient_fresh', 'option_value' => 'y', 'autoload' => 'no'];
        $db->options[] = ['option_name' => '_transient_timeout_fresh', 'option_value' => (string) (time() + 3600), 'autoload' => 'no'];
        $db->options[] = ['option_name' => 'blogname', 'option_value' => 'Site', 'autoload' => 'yes'];
        return $db;
    }

    public function testReportCountsEveryItemHonestly(): void
    {
        $report = Maintenance::report($this->seed());
        $byKey = [];
        foreach ($report['items'] as $item) {
            $byKey[$item['key']] = $item;
        }
        self::assertSame(2, $byKey['revisions']['count']);
        self::assertSame(1, $byKey['auto_drafts']['count']);
        self::assertSame(1, $byKey['spam']['count']);
        self::assertSame(1, $byKey['trash']['count']);
        // stale + its timeout are counted, fresh pair must not appear.
        self::assertSame(2, $byKey['transients']['count']);
        self::assertSame(1, $byKey['orphan_meta']['count']);
        self::assertSame(2 + 1 + 1 + 1 + 2 + 1, $report['cleanable_total']);
        self::assertSame('clean', $byKey['revisions']['kind']);
    }

    public function testApplyDeletesOnlySelectedItems(): void
    {
        $db = $this->seed();
        // transients: the expired transient plus its timeout is ONE logical
        // item (the pair), matching the honest "items" wording in the UI.
        $result = Maintenance::apply(['revisions', 'transients'], $db);
        self::assertSame(['revisions' => 2, 'transients' => 1], $result['deleted']);
        // Untouched items stay: spam comment, trash comment, auto-draft and
        // the live post with its meta.
        $leftoverPosts = array_map(static fn (array $p): string => $p['type'] . ':' . $p['ID'], $db->posts);
        self::assertContains('post:1', $leftoverPosts);
        self::assertContains('post:4', $leftoverPosts);
        self::assertContains('attachment:5', $leftoverPosts);
        // spam/trash were not selected: all three seeded comments survive.
        self::assertCount(3, $db->comments);
        self::assertContains('blogname', array_map(static fn (array $o): string => $o['option_name'], $db->options));
        self::assertContains('_transient_fresh', array_map(static fn (array $o): string => $o['option_name'], $db->options));
        // Deleted revisions take their postmeta with them via post_id
        // of deleted posts; the orphan row (object 999) is untouched here
        // because orphan_meta was not selected.
        self::assertCount(2, $db->postmeta);
        // Fresh report reflects the new truth.
        $report = Maintenance::report($db);
        $byKey = [];
        foreach ($report['items'] as $item) {
            $byKey[$item['key']] = $item;
        }
        self::assertSame(0, $byKey['revisions']['count']);
        self::assertSame(0, $byKey['transients']['count']);
        self::assertSame(1, $byKey['orphan_meta']['count']);
    }

    public function testPostSweepTakesMetaWithIt(): void
    {
        $db = $this->seed();
        // A revision with a live meta row of its own, so the sweep must
        // reach meta + post, not one of the two.
        $db->addPost(6, 'revision', 'inherit');
        $db->postmeta[] = ['post_id' => 6, 'meta_id' => 3];
        $result = Maintenance::apply(['revisions'], $db);
        self::assertSame(3, $result['deleted']['revisions']);
        $left = array_map(static fn (array $r): int => $r['post_id'], $db->postmeta);
        // meta 1 (live post) and meta 999 (orphan, item not selected) stay;
        // meta 2/3/6 went with their revisions.
        self::assertSame([1, 999], $left);
    }

    public function testUnknownItemsAreNeverApplied(): void
    {
        $db = $this->seed();
        $result = Maintenance::apply(['unattached_media', 'autoload', 'everything'], $db);
        self::assertSame([], $result['deleted']);
        self::assertSame([], $db->deletes);
        self::assertNotEmpty($db->posts);
    }

    public function testAdvisoriesNeverDelete(): void
    {
        $db = $this->seed();
        $adv = Maintenance::advisories($db);
        self::assertSame(1, $adv['unattached_media']);
        self::assertSame(1, $adv['autoload_rows']);
        self::assertSame(4, $adv['autoload_bytes']);
        self::assertSame([], $db->deletes);
    }

    public function testBatchingDeletesMoreThanOneBatch(): void
    {
        $db = $this->seed();
        for ($i = 100; $i < 100 + Maintenance::BATCH + 3; $i++) {
            $db->addPost($i, 'revision', 'inherit');
        }
        $result = Maintenance::apply(['revisions'], $db);
        self::assertSame(Maintenance::BATCH + 5, $result['deleted']['revisions']);
        $report = Maintenance::report($db);
        $byKey = [];
        foreach ($report['items'] as $item) {
            $byKey[$item['key']] = $item;
        }
        self::assertSame(0, $byKey['revisions']['count']);
    }
}
