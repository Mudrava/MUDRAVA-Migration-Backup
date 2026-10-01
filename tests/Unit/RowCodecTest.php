<?php

/**
 * Row batch binary encoding: BLOB/NULL/Unicode byte-exactness.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Database\RowCodec;
use PHPUnit\Framework\TestCase;

final class RowCodecTest extends TestCase
{
    public function testRoundTripSimple(): void
    {
        $rows = [
            ['1', 'hello', null],
            ['2', 'world', 'x'],
        ];
        $decoded = RowCodec::decode(RowCodec::encode($rows));
        $this->assertSame($rows, $decoded);
    }

    public function testNullHandling(): void
    {
        $rows = [[null, 'a'], ['b', null]];
        $decoded = RowCodec::decode(RowCodec::encode($rows));
        $this->assertSame($rows, $decoded);
    }

    public function testBinaryBlobByteExact(): void
    {
        $blob = random_bytes(1024);
        $rows = [[$blob]];
        $decoded = RowCodec::decode(RowCodec::encode($rows));
        $this->assertSame($blob, $decoded[0][0]);
        $this->assertSame(1024, strlen($decoded[0][0]));
    }

    public function testUnicodeEmoji(): void
    {
        $rows = [['Привет', '日本語', '🚀', 'українська', 'ქართული']];
        $decoded = RowCodec::decode(RowCodec::encode($rows));
        $this->assertSame($rows, $decoded);
    }

    public function testEmptyStringVsNull(): void
    {
        $rows = [['', null]];
        $decoded = RowCodec::decode(RowCodec::encode($rows));
        $this->assertSame('', $decoded[0][0]);
        $this->assertNull($decoded[0][1]);
    }

    public function testEmptyBatch(): void
    {
        $decoded = RowCodec::decode(RowCodec::encode([]));
        $this->assertSame([], $decoded);
    }

    public function testTruncatedThrows(): void
    {
        $bin = RowCodec::encode([['abc', 'def']]);
        $this->expectException(\RuntimeException::class);
        RowCodec::decode(substr($bin, 0, strlen($bin) - 3));
    }

    public function testRaggedBatchThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RowCodec::encode([['a', 'b'], ['c']]);
    }

    public function testLargeCell(): void
    {
        $big = random_bytes(2 * 1024 * 1024);
        $decoded = RowCodec::decode(RowCodec::encode([[$big]]));
        $this->assertSame($big, $decoded[0][0]);
    }

    /** @dataProvider invalidRows */
    public function testInvalidRowsAreRejected(array $rows, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        RowCodec::encode($rows);
    }

    /** @return array<string,array{array<mixed>,string}> */
    public static function invalidRows(): array
    {
        return [
            'non-array row' => [['not-a-row'], 'Row must be an array'],
            'empty row' => [[[]], 'Invalid column count'],
            'invalid cell' => [[[new \stdClass()]], 'Cell must be string or null'],
        ];
    }

    /** @dataProvider corruptBatches */
    public function testCorruptBatchesAreRejected(string $binary, string $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        RowCodec::decode($binary);
    }

    /** @return array<string,array{string,string}> */
    public static function corruptBatches(): array
    {
        return [
            'short header' => ['abc', 'row batch too short'],
            'zero columns with rows' => [pack('nN', 0, 1), 'zero columns with data'],
            'zero columns trailing' => [pack('nN', 0, 0) . 'x', 'zero columns with data'],
            'missing cell' => [pack('nN', 1, 1), 'row batch truncated'],
            'length truncated' => [pack('nN', 1, 1) . chr(1) . "\0\0", 'cell length truncated'],
            'cell truncated' => [pack('nN', 1, 1) . chr(1) . pack('N', 5) . 'x', 'cell bytes truncated'],
            'unknown kind' => [pack('nN', 1, 1) . chr(9), 'unknown cell kind'],
            'trailing bytes' => [pack('nN', 1, 1) . chr(0) . 'x', 'trailing bytes'],
        ];
    }
}
