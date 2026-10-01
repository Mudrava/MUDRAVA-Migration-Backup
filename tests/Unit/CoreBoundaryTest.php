<?php

/**
 * Small binary-format boundary contracts that must fail closed.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Archive\Frame;
use Mudrava\Migration\Archive\Header;
use Mudrava\Migration\Archive\RootHash;
use Mudrava\Migration\Compression\DeflateCodec;
use PHPUnit\Framework\TestCase;

final class CoreBoundaryTest extends TestCase
{
    public function testFrameRejectsNonObjectJsonPayload(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');
        (new Frame(1, 7, 'null'))->json();
    }

    public function testRootHashRequiresSixteenByteArchiveUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RootHash('short');
    }

    /** @dataProvider invalidDeflateLengths */
    public function testDeflateRejectsImpossibleLogicalLengths(string $data, bool $compressed, int $limit, string $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        (new DeflateCodec())->decompress($data, $compressed, $limit);
    }

    /** @return array<string,array{string,bool,int,string}> */
    public static function invalidDeflateLengths(): array
    {
        return [
            'raw exceeds declared length' => ['abc', false, 2, 'logical length exceeds'],
            'compressed cannot declare zero' => [(string) gzdeflate('x'), true, 0, 'zero logical length'],
        ];
    }

    public function testHeaderRejectsOversizedProducerAndPasswordHint(): void
    {
        try {
            (new Header(1, 0, random_bytes(16), str_repeat('p', 256), 0, 0, 0,
                str_repeat("\0", 16), str_repeat("\0", 8)))->encode();
            $this->fail('oversized producer must fail');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('producer_version', $error->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('password_hint');
        (new Header(1, 0, random_bytes(16), 'test', 0, 0, 0, str_repeat("\0", 16),
            str_repeat("\0", 8), str_repeat('h', 65536)))->encode();
    }

    public function testHeaderRejectsProducerLengthThatOverrunsFixedTail(): void
    {
        $valid = (new Header(1, 0, random_bytes(16), 'x', 0, 0, 0,
            str_repeat("\0", 16), str_repeat("\0", 8)))->encode();
        $valid[32] = chr(250);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bad header_size');
        Header::decode($valid);
    }

    public function testHeaderUuidHexIsStable(): void
    {
        $this->assertSame('0001ff', Header::uuidHex("\0\1\xff"));
    }

}
