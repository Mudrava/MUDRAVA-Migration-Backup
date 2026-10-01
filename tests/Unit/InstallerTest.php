<?php

/**
 * Activation lifecycle contract for storage bootstrap and cron registration.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Support\Installer;
use Mudrava\Migration\Support\Paths;
use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['MUDRAVA_STUB_SCHEDULED'] = [];
        $GLOBALS['MUDRAVA_STUB_CLEARED_HOOKS'] = [];
        $GLOBALS['MUDRAVA_STUB_OPTIONS'] = [];
        $GLOBALS['MUDRAVA_STUB_MULTISITE'] = false;
    }

    public function testActivationCreatesPrivateStorageAndSchedulesBothWorkers(): void
    {
        Installer::activate();

        $this->assertDirectoryExists((new Paths())->storageDir());
        $this->assertSame(MUDRAVA_MB_VERSION, $GLOBALS['MUDRAVA_STUB_OPTIONS'][Installer::OPTION_VERSION]);
        $this->assertSame('mudrava_minute', $GLOBALS['MUDRAVA_STUB_SCHEDULED']['mudrava_tick']->schedule);
        $this->assertSame(
            'mudrava_minute',
            $GLOBALS['MUDRAVA_STUB_SCHEDULED']['mudrava_cleanup_uploads']->schedule
        );
    }

    public function testActivationKeepsExistingSchedulesAndDeactivationClearsThem(): void
    {
        $GLOBALS['MUDRAVA_STUB_SCHEDULED']['mudrava_tick'] = (object) ['schedule' => 'mudrava_minute'];
        $GLOBALS['MUDRAVA_STUB_SCHEDULED']['mudrava_cleanup_uploads'] = (object) ['schedule' => 'mudrava_minute'];

        Installer::activate();
        $this->assertCount(2, $GLOBALS['MUDRAVA_STUB_SCHEDULED']);
        Installer::deactivate();
        $this->assertSame(['mudrava_tick', 'mudrava_cleanup_uploads'], $GLOBALS['MUDRAVA_STUB_CLEARED_HOOKS']);
    }
}
