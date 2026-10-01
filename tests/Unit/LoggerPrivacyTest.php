<?php

/**
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */


declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Support\Logger;
use PHPUnit\Framework\TestCase;

final class LoggerPrivacyTest extends TestCase
{
    public function testFreeFormContextAndEventCannotExposeCredentials(): void
    {
        $lines = [];
        $logger = new Logger(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $logger->error('password=event-secret', [
            'kind' => 'import',
            'error' => 'MUDRAVA_PATH_UNSAFE',
            'exception_class' => \RuntimeException::class,
            'detail' => 'password=context-secret',
            'nested' => ['trace' => 'Bearer nested-secret'],
            'token' => 'token-secret',
        ]);

        $this->assertCount(1, $lines);
        $this->assertStringNotContainsString('event-secret', $lines[0]);
        $this->assertStringNotContainsString('context-secret', $lines[0]);
        $this->assertStringNotContainsString('nested-secret', $lines[0]);
        $this->assertStringNotContainsString('token-secret', $lines[0]);
        $this->assertStringContainsString('event_redacted', $lines[0]);
        $this->assertStringContainsString('MUDRAVA_PATH_UNSAFE', $lines[0]);
        $this->assertStringContainsString('RuntimeException', $lines[0]);
    }

    public function testMalformedErrorCodeIsRedacted(): void
    {
        $captured = '';
        $logger = new Logger(static function (string $line) use (&$captured): void {
            $captured = $line;
        });

        $logger->warn('job_failed', ['error' => 'MUDRAVA_PATH_UNSAFE: /private/password=secret']);

        $this->assertStringNotContainsString('/private/', $captured);
        $this->assertStringContainsString('"error":"[REDACTED]"', $captured);
    }

    public function testLevelThresholdConvenienceMethodsAndObjectRedaction(): void
    {
        $lines = [];
        $logger = new Logger(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }, Logger::LEVEL_INFO);

        $logger->debug('debug_event');
        $logger->info('info_event', ['object' => new \RuntimeException('secret')]);
        $logger->warn('warn_event');
        $logger->log(99, 'unknown_level');

        $this->assertCount(3, $lines);
        $this->assertStringContainsString('INFO info_event', $lines[0]);
        $this->assertStringContainsString('RuntimeException', $lines[0]);
        $this->assertStringContainsString('WARN warn_event', $lines[1]);
        $this->assertStringContainsString('INFO unknown_level', $lines[2]);
    }
}
