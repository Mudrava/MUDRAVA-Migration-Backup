<?php

/**
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Integration\AdminPanels;
use PHPUnit\Framework\TestCase;

final class AdminPanelsTest extends TestCase
{
    public function testRendererFailureDiscardsPartialMarkupAndAllowsFallback(): void
    {
        AdminPanels::register('notifications', static function (): void {
            echo 'partial broken form';
            throw new \RuntimeException('private path must not leak');
        });
        ob_start();
        $result = AdminPanels::render('notifications');
        $html = ob_get_clean();
        self::assertFalse($result);
        self::assertSame('', $html);
    }

    public function testTrustedRendererCanSupplyTheSharedPanel(): void
    {
        AdminPanels::register('storage', static function (): void { echo '<p>Storage panel</p>'; });
        ob_start();
        $result = AdminPanels::render('storage');
        $html = ob_get_clean();
        self::assertTrue($result);
        self::assertSame('<p>Storage panel</p>', $html);
    }

    public function testRendererCannotReplaceManualMigrationPanels(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AdminPanels::register('restore', static function (): void {});
    }
}
