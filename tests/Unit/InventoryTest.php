<?php
/**
 * Inventory rules that decide what the export pickers lock, group, and
 * accept. Pure logic only - no database, no filesystem walk: the DB and
 * walk measurements are exercised in the lab E2E.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Http\RestApi;
use Mudrava\Migration\Filesystem\WpFileInventory;
use Mudrava\Migration\Support\Inventory;
use PHPUnit\Framework\TestCase;

final class InventoryTest extends TestCase
{
    public function testCoreTablesAreLockedForDefaultPrefix(): void
    {
        $core = ['posts', 'options', 'postmeta', 'users', 'usermeta', 'comments', 'terms'];
        foreach ($core as $suffix) {
            $this->assertTrue(
                Inventory::isCoreTable('wp_' . $suffix, 'wp_'),
                'wp_' . $suffix . ' must be locked as core'
            );
        }
    }

    public function testNonCoreTablesAreNotLocked(): void
    {
        $this->assertFalse(Inventory::isCoreTable('wp_yoast_indexable', 'wp_'));
        $this->assertFalse(Inventory::isCoreTable('wp_statistics', 'wp_'));
        $this->assertFalse(Inventory::isCoreTable('mudrava_temp', 'wp_'));
        // A different prefix: wp_posts is not a core table of prefix mu_.
        $this->assertFalse(Inventory::isCoreTable('wp_posts', 'mu_'));
        $this->assertTrue(Inventory::isCoreTable('mu_posts', 'mu_'));
    }

    public function testBucketForGroupsByWpContentChild(): void
    {
        $this->assertSame('wp-content/uploads', Inventory::bucketFor('wp-content/uploads/2026/01/a.jpg'));
        $this->assertSame('wp-content/plugins', Inventory::bucketFor('wp-content/plugins/x/x.php'));
        // A file sitting in the wp-content root is its own group.
        $this->assertSame('wp-content', Inventory::bucketFor('wp-content/index.php'));
        // Everything outside wp-content always travels.
        $this->assertSame('core', Inventory::bucketFor('wp-includes/load.php'));
        $this->assertSame('core', Inventory::bucketFor('wp-login.php'));
        $this->assertSame('core', Inventory::bucketFor('wp-content'));
    }

    public function testSanitizeExcludesKeepsOnlyPlainIdentifiersAndPaths(): void
    {
        $clean = RestApi::sanitizeExcludes([
            'tables' => ['wp_statistics', 'wp_postmeta_bak', ' wp_old ', ''],
            'dirs'   => ['uploads/2023', 'cache', 'uploads/backup-2024'],
        ]);
        $this->assertSame(['wp_statistics', 'wp_postmeta_bak', 'wp_old'], $clean['tables']);
        $this->assertSame(['uploads/2023', 'cache', 'uploads/backup-2024'], $clean['dirs']);
    }

    public function testSanitizeExcludesDropsTraversalAndWildcards(): void
    {
        $clean = RestApi::sanitizeExcludes([
            'tables' => ['wp_posts; DROP TABLE x', 'wp%meta', 'wp post'],
            'dirs'   => ['../../wp-config.php', 'uploads/*', 'uploads dir', '$(rm -rf)'],
        ]);
        $this->assertSame([], $clean['tables']);
        $this->assertSame([], $clean['dirs']);
    }

    public function testSanitizeExcludesAcceptsGarbageWithoutFailing(): void
    {
        $this->assertSame(['tables' => [], 'dirs' => []], RestApi::sanitizeExcludes('nope'));
        $this->assertSame(['tables' => [], 'dirs' => []], RestApi::sanitizeExcludes(null));
        $this->assertSame(['tables' => [], 'dirs' => []], RestApi::sanitizeExcludes([]));
    }

    public function testEmptyFilesystemExclusionEntryIsIgnored(): void
    {
        $this->assertFalse(WpFileInventory::pathExcluded('wp-content/keep.txt', ['']));
    }
}
