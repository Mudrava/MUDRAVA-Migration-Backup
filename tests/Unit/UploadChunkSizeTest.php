<?php

/**
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */


declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Plugin;
use PHPUnit\Framework\TestCase;

final class UploadChunkSizeTest extends TestCase
{
    public function testMultipartChunkFitsWithinOneMegabytePostLimit(): void
    {
        $method = new \ReflectionMethod(Plugin::class, 'chunkBytesForLimit');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $bytes = $method->invoke(null, 1048576);
        self::assertIsInt($bytes);
        self::assertGreaterThan(65536, $bytes);
        self::assertLessThan(1048576 - 65536, $bytes);
    }

    public function testLargeHostStillUsesBoundedChunks(): void
    {
        $method = new \ReflectionMethod(Plugin::class, 'chunkBytesForLimit');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        self::assertSame(16777216, $method->invoke(null, 1073741824));
    }

    /** @dataProvider iniByteValues */
    public function testIniByteParserHandlesHostFormats(string $value, int $expected): void
    {
        $method = new \ReflectionMethod(Plugin::class, 'iniBytes');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $this->assertSame($expected, $method->invoke(null, $value));
    }

    /** @return array<string,array{string,int}> */
    public static function iniByteValues(): array
    {
        return [
            'empty' => ['', 0],
            'zero' => ['0', 0],
            'gigabytes' => ['1G', 1073741824],
            'megabytes' => ['2M', 2097152],
            'kilobytes' => ['3K', 3072],
            'plain bytes' => ['4097', 4097],
        ];
    }

    public function testNonPositiveLimitUsesConservativeDefault(): void
    {
        $method = new \ReflectionMethod(Plugin::class, 'chunkBytesForLimit');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $this->assertSame(8 * 1048576, $method->invoke(null, 0));
    }
}
