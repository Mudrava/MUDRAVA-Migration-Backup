<?php
/**
 * Root-hash chain determinism.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Archive\RootHash;
use PHPUnit\Framework\TestCase;

final class RootHashTest extends TestCase
{
    public function testDeterministicForSameInput(): void
    {
        $uuid = random_bytes(16);
        $a = new RootHash($uuid);
        $b = new RootHash($uuid);
        for ($i = 1; $i <= 10; $i++) {
            $a->update(0x21, $i, crc32('x' . $i));
            $b->update(0x21, $i, crc32('x' . $i));
        }
        $this->assertSame($a->hex(), $b->hex());
    }

    public function testDifferentUuidDifferentHash(): void
    {
        $a = new RootHash(random_bytes(16));
        $b = new RootHash(random_bytes(16));
        $a->update(1, 1, 123);
        $b->update(1, 1, 123);
        $this->assertNotSame($a->hex(), $b->hex());
    }

    public function testOrderMatters(): void
    {
        $uuid = random_bytes(16);
        $a = new RootHash($uuid);
        $b = new RootHash($uuid);
        $a->update(1, 1, 111);
        $a->update(2, 2, 222);
        $b->update(2, 2, 222);
        $b->update(1, 1, 111);
        $this->assertNotSame($a->hex(), $b->hex());
    }

    public function testFromStateRoundTrip(): void
    {
        $uuid = random_bytes(16);
        $a = new RootHash($uuid);
        $a->update(0x12, 5, 999);
        $b = RootHash::fromState($a->state());
        $b->update(0x12, 6, 1000);
        $a->update(0x12, 6, 1000);
        $this->assertSame($a->hex(), $b->hex());
    }

    public function testBadStateLengthThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RootHash::fromState(str_repeat("\0", 31));
    }
}
