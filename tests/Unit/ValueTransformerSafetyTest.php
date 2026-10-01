<?php

/**
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Database\ValueTransformer;
use PHPUnit\Framework\TestCase;

final class ValueTransformerSafetyTest extends TestCase
{
    public function testElementorJsonUrlsAreRewrittenWithoutBreakingLayout(): void
    {
        $old = 'http://localhost:8083';
        $new = 'https://destination.example';
        $layout = [[
            'elType' => 'container',
            'elements' => [
                ['widgetType' => 'button', 'settings' => ['link' => ['url' => $old . '/ka/page/']]],
                ['widgetType' => 'image', 'settings' => ['image' => ['url' => $old . '/uploads/image.png']]],
            ],
        ]];
        $json = (string) json_encode($layout);
        self::assertStringContainsString('http:\\/\\/localhost:8083', $json);

        $transformer = new ValueTransformer($old, $new);
        $actual = $transformer->transform($json);
        $decoded = json_decode($actual, true);

        self::assertSame($new . '/ka/page/', $decoded[0]['elements'][0]['settings']['link']['url']);
        self::assertSame($new . '/uploads/image.png', $decoded[0]['elements'][1]['settings']['image']['url']);
        self::assertSame(1, $transformer->changed());
        self::assertSame(0, $transformer->parseFailures());

        $serialized = 'a:1:{s:6:"layout";s:' . strlen($json) . ':"' . $json . '";}';
        $rewritten = $transformer->transform($serialized);
        self::assertSame('a:1:{s:6:"layout";s:' . strlen($actual) . ':"' . $actual . '";}', $rewritten);
        self::assertSame(2, $transformer->changed());
    }

    public function testEmptySearchIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ValueTransformer('', 'replacement');
    }

    public function testStandardSerializedObjectIsRewrittenWithoutInstantiation(): void
    {
        $source = 'https://old.example';
        $target = 'https://new.example';
        $serialized = 'O:8:"stdClass":1:{s:3:"url";s:' . strlen($source) . ':"' . $source . '";}';
        $transformer = new ValueTransformer($source, $target);
        $actual = $transformer->transform($serialized);
        self::assertSame('O:8:"stdClass":1:{s:3:"url";s:' . strlen($target) . ':"' . $target . '";}', $actual);
        self::assertSame(0, $transformer->parseFailures());
        self::assertSame(1, $transformer->changed());
    }

    public function testBinaryAndCustomSerializedValuesStayByteIdentical(): void
    {
        $transformer = new ValueTransformer('https://old.example', 'https://new.example');
        $binary = "\x00\xFEhttps://old.example\x01";
        self::assertSame($binary, $transformer->transform($binary));
        $custom = 'C:4:"Test":19:{https://old.example}';
        self::assertSame($custom, $transformer->transform($custom));
        self::assertSame(1, $transformer->parseFailures());
    }

    public function testArraysScalarsReferencesKeysAndNestedPayloadsAreReemitted(): void
    {
        $old = 'https://old.example';
        $new = 'https://new.test';
        $inner = 'a:1:{s:3:"url";s:' . strlen($old) . ':"' . $old . '";}';
        $serialized = 'a:8:{'
            . 's:' . strlen($old) . ':"' . $old . '";i:-7;'
            . 'i:1;d:1.25;'
            . 'i:2;b:1;'
            . 'i:3;N;'
            . 'i:4;r:1;'
            . 'i:5;R:2;'
            . 'i:6;s:' . strlen($inner) . ':"' . $inner . '";'
            . 'i:7;s:4:"safe";'
            . '}';
        $transformer = new ValueTransformer($old, $new);

        $out = $transformer->transform($serialized);

        $this->assertStringNotContainsString($old, $out);
        $this->assertStringContainsString('s:' . strlen($new) . ':"' . $new . '";', $out);
        $this->assertStringContainsString('i:-7;', $out);
        $this->assertStringContainsString('d:1.25;', $out);
        $this->assertStringContainsString('b:1;', $out);
        $this->assertStringContainsString('N;', $out);
        $this->assertStringContainsString('r:1;', $out);
        $this->assertStringContainsString('R:2;', $out);
        $this->assertSame(1, $transformer->changed());
        $this->assertSame(0, $transformer->parseFailures());
    }

    /** @dataProvider malformedSerializedValues */
    public function testMalformedSerializedShapesFailClosed(string $value): void
    {
        $transformer = new ValueTransformer('old.example', 'new.example');

        $this->assertSame($value, $transformer->transform($value));
        $this->assertSame(1, $transformer->parseFailures());
        $this->assertSame(0, $transformer->changed());
    }

    /** @return array<string,array{string}> */
    public static function malformedSerializedValues(): array
    {
        return [
            'array ends before value' => ['a:1:{s:11:"old.example";'],
            'array missing key' => ['a:1:{xold.example'],
            'array missing value' => ['a:1:{i:0;xold.example'],
            'array missing brace' => ['a:1:{i:0;s:11:"old.example";'],
            'object custom payload' => ['C:4:"Test":11:{old.example}'],
            'object zero class length' => ['O:0:"":1:{s:3:"url";s:11:"old.example";}'],
            'object bad class delimiter' => ['O:4:"Testx1:{s:3:"url";s:11:"old.example";}'],
            'object missing property count' => ['O:4:"Test":x:{old.example'],
            'object missing key' => ['O:4:"Test":1:{xold.example'],
            'object missing value' => ['O:4:"Test":1:{s:3:"url";xold.example'],
            'object missing brace' => ['O:4:"Test":1:{s:3:"url";s:11:"old.example";'],
            'string truncated' => ['s:99:"old.example";'],
            'string bad quote' => ['s:11:"old.examplex;'],
            'string missing semicolon' => ['s:11:"old.example"x'],
            'trailing bytes' => ['s:11:"old.example";junk'],
        ];
    }

    public function testExcessiveSerializedDepthFailsClosed(): void
    {
        $value = '';
        for ($i = 0; $i < 130; $i++) {
            $value .= 'a:1:{i:0;';
        }
        $value .= 's:11:"old.example";' . str_repeat('}', 130);
        $transformer = new ValueTransformer('old.example', 'new.example');

        $this->assertSame($value, $transformer->transform($value));
        $this->assertSame(1, $transformer->parseFailures());
    }

    public function testMalformedNestedSerializationIsRewrittenAsAnOpaqueString(): void
    {
        $inner = 'a:1:{xold.example';
        $outer = 's:' . strlen($inner) . ':"' . $inner . '";';
        $transformer = new ValueTransformer('old.example', 'new.example');

        $this->assertSame(
            's:' . strlen(str_replace('old.example', 'new.example', $inner)) . ':"a:1:{xnew.example";',
            $transformer->transform($outer)
        );
        $this->assertSame(1, $transformer->changed());
        $this->assertSame(0, $transformer->parseFailures());
    }
}
