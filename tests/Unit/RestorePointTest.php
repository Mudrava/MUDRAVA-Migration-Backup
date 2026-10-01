<?php

/**
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */


declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Rollback\RestorePoint;
use Mudrava\Migration\Support\Paths;
use PHPUnit\Framework\TestCase;

final class RestorePointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        @unlink((new Paths())->storageDir() . '/restore-point.json');
        unset($GLOBALS['MUDRAVA_STUB_OPTIONS'][RestorePoint::OPTION]);
    }

    public function testRecordSurvivesReplacementOfOptionsTable(): void
    {
        $path = (new Paths())->ensureStorage() . '/restore-point.json';
        @unlink($path);
        RestorePoint::record(['safe' => true, 'free' => 100, 'needed' => 50], false, 'backup-test');
        $GLOBALS['MUDRAVA_STUB_OPTIONS'] = [];
        $record = RestorePoint::current();
        $this->assertIsArray($record);
        $this->assertSame('backup-test', $record['archive_id']);
        $this->assertSame(false, $record['proceed_unsafe']);
        $this->assertSame(0600, fileperms($path) & 0777);
        @unlink($path);
    }

    public function testCurrentFallsBackToOptionWhenFileIsMissingOrInvalid(): void
    {
        $fallback = ['archive_id' => 'option-backup'];
        $GLOBALS['MUDRAVA_STUB_OPTIONS'][RestorePoint::OPTION] = $fallback;
        $this->assertSame($fallback, RestorePoint::current());

        file_put_contents((new Paths())->storageDir() . '/restore-point.json', '{broken');
        $this->assertSame($fallback, RestorePoint::current());

        unset($GLOBALS['MUDRAVA_STUB_OPTIONS'][RestorePoint::OPTION]);
        $this->assertNull(RestorePoint::current());
    }

    public function testHeadroomReportsMeasuredCapacity(): void
    {
        $headroom = RestorePoint::headroom();
        $this->assertIsBool($headroom['safe']);
        $this->assertGreaterThanOrEqual(0, $headroom['free']);
        $this->assertGreaterThanOrEqual(0, $headroom['needed']);
        $this->assertSame($headroom['free'] >= $headroom['needed'], $headroom['safe']);
    }
}
