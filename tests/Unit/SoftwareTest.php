<?php

/**
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Support\Software;
use PHPUnit\Framework\TestCase;

final class SoftwareTest extends TestCase
{
    public function testSnapshotCarriesActivePluginsWithVersions(): void
    {
        $snap = Software::snapshot(
            ['elementor/elementor.php', 'acf/acf.php'],
            [
                'elementor/elementor.php' => ['Name' => 'Elementor', 'Version' => '3.25.0'],
                'acf/acf.php' => ['Name' => 'Advanced Custom Fields', 'Version' => '6.3.4'],
                // Installed but inactive: must not be carried. The
                // destination diff is about what RAN on the source.
                'polylang/polylang.php' => ['Name' => 'Polylang', 'Version' => '3.6'],
            ],
            ['name' => 'Mudrava', 'version' => '1.0', 'parent' => ''],
            ['loader.php']
        );
        self::assertSame([
            'elementor/elementor.php' => ['name' => 'Elementor', 'version' => '3.25.0'],
            'acf/acf.php' => ['name' => 'Advanced Custom Fields', 'version' => '6.3.4'],
        ], $snap['plugins']);
        self::assertSame('Mudrava', $snap['theme']['name']);
        self::assertSame(['loader.php'], $snap['mu_plugins']);
        self::assertFalse($snap['truncated']);
    }

    public function testUnknownActivePathsStillTravelByPathName(): void
    {
        // A plugin file missing from get_plugins() data (dropped-in must-use
        // or a deleted folder still listed active) must not vanish from the
        // inventory silently: the path itself is the signal.
        $snap = Software::snapshot(['ghost/ghost.php'], [], [], []);
        self::assertSame(
            ['ghost/ghost.php' => ['name' => 'ghost/ghost.php', 'version' => '']],
            $snap['plugins']
        );
    }

    public function testTruncationIsHonestNotSilent(): void
    {
        $paths = [];
        $data = [];
        for ($i = 0; $i <= Software::MAX_PLUGINS; $i++) {
            $p = "p{$i}/p{$i}.php";
            $paths[] = $p;
            $data[$p] = ['Name' => "P{$i}", 'Version' => '1.0'];
        }
        $snap = Software::snapshot($paths, $data, [], []);
        self::assertTrue($snap['truncated']);
        self::assertCount(Software::MAX_PLUGINS, $snap['plugins']);
    }

    public function testEmptyThemeFieldsNormalizeToStrings(): void
    {
        $snap = Software::snapshot([], [], [], []);
        self::assertSame(['name' => '', 'version' => '', 'parent' => ''], $snap['theme']);
    }
}
