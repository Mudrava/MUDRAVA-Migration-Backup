<?php

/**
 * Live inventory contract for database metadata and the bounded WordPress
 * filesystem walk used by the export picker.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Support\Inventory;
use PHPUnit\Framework\TestCase;

final class InventoryRuntimeTest extends TestCase
{
    /** @var object */
    private $oldWpdb;

    /** @var string */
    private $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldWpdb = $GLOBALS['wpdb'];
        $this->fixtureRoot = rtrim(ABSPATH, '/') . '/wp-content/inventory-' . bin2hex(random_bytes(4));
        mkdir($this->fixtureRoot, 0777, true);
        file_put_contents($this->fixtureRoot . '/custom.bin', str_repeat('c', 23));
        $uploads = rtrim(ABSPATH, '/') . '/wp-content/uploads';
        if (!is_dir($uploads)) {
            mkdir($uploads, 0777, true);
        }
        file_put_contents($this->fixtureRoot . '/nested.bin', str_repeat('n', 7));
        file_put_contents(rtrim(ABSPATH, '/') . '/inventory-core.bin', str_repeat('r', 11));
    }

    protected function tearDown(): void
    {
        @unlink($this->fixtureRoot . '/custom.bin');
        @unlink($this->fixtureRoot . '/nested.bin');
        @rmdir($this->fixtureRoot);
        @unlink(rtrim(ABSPATH, '/') . '/inventory-core.bin');
        $GLOBALS['wpdb'] = $this->oldWpdb;
        parent::tearDown();
    }

    public function testWalkBucketsContentAndCoreFilesWithMeasuredBytes(): void
    {
        $walk = Inventory::walk();
        $relative = 'wp-content/' . basename($this->fixtureRoot);

        $this->assertArrayHasKey($relative, $walk['buckets']);
        $this->assertSame(30, $walk['buckets'][$relative]['bytes']);
        $this->assertSame(2, $walk['buckets'][$relative]['files']);
        $this->assertGreaterThanOrEqual(11, $walk['core_bytes']);
        $this->assertGreaterThanOrEqual(1, $walk['core_files']);
        $this->assertFalse($walk['approx']);
    }

    public function testRunCombinesSortedTablesAndFriendlyFileGroups(): void
    {
        $GLOBALS['wpdb'] = new class {
            /** @var string */
            public $prefix = 'wp_';

            /** @return list<array{TABLE_NAME:string,TABLE_ROWS:string,BYTES:string}> */
            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress database API name.
            public function get_results(string $sql, string $format): array
            {
                return [
                    ['TABLE_NAME' => 'wp_plugin_data', 'TABLE_ROWS' => '9', 'BYTES' => '4096'],
                    ['TABLE_NAME' => 'wp_posts', 'TABLE_ROWS' => '4', 'BYTES' => '2048'],
                ];
            }

            /** @return list<string> */
            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WordPress database API name.
            public function get_col(string $sql): array
            {
                return ['wp_plugin_data', 'wp_posts'];
            }
        };

        $inventory = Inventory::run();

        $this->assertSame(['wp_plugin_data', 'wp_posts'], array_column($inventory['tables'], 'name'));
        $this->assertSame(9, $inventory['tables'][0]['rows']);
        $this->assertSame(4096, $inventory['tables'][0]['bytes']);
        $this->assertFalse($inventory['tables'][0]['locked']);
        $this->assertTrue($inventory['tables'][1]['locked']);

        $path = 'wp-content/' . basename($this->fixtureRoot);
        $groups = array_column($inventory['files'], null, 'path');
        $this->assertArrayHasKey($path, $groups);
        $this->assertSame(basename($this->fixtureRoot), $groups[$path]['label']);
        $this->assertSame(30, $groups[$path]['bytes']);
        $this->assertSame(2, $groups[$path]['files']);
        $this->assertFalse($inventory['approx']);
    }

    public function testFileGroupsOrderKnownCustomAndLooseContentFiles(): void
    {
        $call = \Closure::bind(static function (array $buckets): array {
            return Inventory::fileGroups($buckets);
        }, null, Inventory::class);
        $this->assertNotNull($call);

        $groups = $call([
            'wp-content/zeta' => ['bytes' => 7, 'files' => 1],
            'wp-content' => ['bytes' => 3, 'files' => 2],
            'wp-content/uploads' => ['bytes' => 11, 'files' => 4],
            'wp-content/alpha' => ['bytes' => 5, 'files' => 1],
            'wp-content/themes' => ['bytes' => 13, 'files' => 2],
        ]);

        $this->assertSame([
            'wp-content/uploads',
            'wp-content/themes',
            'wp-content/alpha',
            'wp-content/zeta',
            'wp-content',
        ], array_column($groups, 'path'));
        $this->assertSame('Media (uploads)', $groups[0]['label']);
        $this->assertSame('Other files (wp-content root)', $groups[4]['label']);
        $this->assertSame(3, $groups[4]['bytes']);
        $this->assertSame(2, $groups[4]['files']);
    }
}
