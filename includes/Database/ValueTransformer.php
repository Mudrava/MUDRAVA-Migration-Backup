<?php

/**
 * WordPress-aware value transformer for URL/domain/path migration.
 *
 * Never blind-replaces inside serialized PHP data (that corrupts s:N:
 * lengths). Instead a strict recursive-descent parser walks the serialized
 * structure byte-accurately, rewrites string leaves (including nested
 * serialized payloads and keys), and re-emits with corrected lengths.
 * Objects are handled structurally - never instantiated.
 *
 * JSON values: rewrite both literal and JSON-escaped URL bytes (no length prefixes).
 * Plain strings: plain replacement.
 * Serialized-but-unparseable: left untouched + warning (never corrupted).
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Database;

final class ValueTransformer
{
    /** @var string */
    private $search;

    /** @var string */
    private $replace;

    /** @var string */
    private $jsonSearch;

    /** @var string */
    private $jsonReplace;

    /** @var int count of values changed */
    private $changed = 0;

    /** @var int count of serialized values that failed to parse */
    private $parseFailures = 0;

    public function __construct(string $search, string $replace)
    {
        if ($search === '') {
            throw new \InvalidArgumentException('Search string must not be empty.');
        }
        $this->search = $search;
        $this->replace = $replace;
        // Elementor stores URLs inside JSON with escaped slashes (http:\/\/).
        // Encoding both sides preserves JSON syntax when URL lengths differ.
        $encodedSearch = json_encode($search);
        $encodedReplace = json_encode($replace);
        $this->jsonSearch = $encodedSearch === false ? $search : substr($encodedSearch, 1, -1);
        $this->jsonReplace = $encodedReplace === false ? $replace : substr($encodedReplace, 1, -1);
    }

    public function changed(): int
    {
        return $this->changed;
    }

    public function parseFailures(): int
    {
        return $this->parseFailures;
    }

    /**
     * Transform one database text value. Binary values that do not contain
     * the search bytes are returned untouched.
     */
    public function transform(string $value): string
    {
        if (strpos($value, "\0") !== false || !$this->containsSearch($value)) {
            return $value;
        }
        if ($this->looksSerialized($value)) {
            $pos = 0;
            $parsed = $this->parseNode($value, $pos);
            if ($parsed !== null && $pos === strlen($value)) {
                // The changed flag is decided during parsing (string leaves);
                // emit only re-serializes with corrected lengths.
                $out = $this->emit($parsed['node']);
                if ($parsed['changed']) {
                    $this->changed++;
                }
                return $out;
            }
            // Serialized-looking but unparseable: do NOT blind-replace.
            $this->parseFailures++;
            return $value;
        }
        $out = $this->replaceVariants($value);
        if ($out !== $value) {
            $this->changed++;
        }
        return $out;
    }

    private function looksSerialized(string $value): bool
    {
        return preg_match('/^(a:\d+:\{|O:\d+:"|C:\d+:"|s:\d+:")/', $value) === 1;
    }

    private function containsSearch(string $value): bool
    {
        return strpos($value, $this->search) !== false
            || ($this->jsonSearch !== $this->search && strpos($value, $this->jsonSearch) !== false);
    }

    private function replaceVariants(string $value): string
    {
        $out = str_replace($this->search, $this->replace, $value);
        if ($this->jsonSearch !== $this->search) {
            $out = str_replace($this->jsonSearch, $this->jsonReplace, $out);
        }
        return $out;
    }

    /**
     * @return array{node:array<string,mixed>,changed:bool}|null
     */
    private function parseNode(string $s, int &$pos, int $depth = 0): ?array
    {
        if ($depth > 128) {
            return null;
        }
        $len = strlen($s);
        if ($pos >= $len) {
            return null;
        }
        $rest = substr($s, $pos);

        // array
        if (preg_match('/\Aa:(\d+):\{/', $rest, $m)) {
            $pos += strlen($m[0]);
            $count = (int) $m[1];
            $items = [];
            $changed = false;
            for ($i = 0; $i < $count; $i++) {
                $key = $this->parseNode($s, $pos, $depth + 1);
                if ($key === null) {
                    return null;
                }
                $val = $this->parseNode($s, $pos, $depth + 1);
                if ($val === null) {
                    return null;
                }
                $changed = $changed || $key['changed'] || $val['changed'];
                $items[] = [$key['node'], $val['node']];
            }
            if ($pos >= $len || $s[$pos] !== '}') {
                return null;
            }
            $pos++;
            return ['node' => ['type' => 'array', 'items' => $items], 'changed' => $changed];
        }

        // Standard object properties can be parsed without instantiating the
        // class. Custom Serializable payloads are opaque and stay untouched.
        if (preg_match('/\A(O|C):(\d+):"/', $rest, $m)) {
            if ($m[1] === 'C') {
                return null;
            }
            $pos += strlen($m[0]);
            $clsStart = $pos;
            $classBytes = (int) $m[2];
            $q = $clsStart + $classBytes;
            if ($classBytes < 1 || $q + 2 > $len || substr($s, $q, 2) !== '":') {
                return null;
            }
            $class = substr($s, $clsStart, $classBytes);
            $pos = $q + 2;
            if (preg_match('/\A(\d+):\{/', substr($s, $pos), $countMatch) !== 1) {
                return null;
            }
            $pos += strlen($countMatch[0]);
            $propertyCount = (int) $countMatch[1];
            $props = [];
            $changed = false;
            for ($i = 0; $i < $propertyCount; $i++) {
                $key = $this->parseNode($s, $pos, $depth + 1);
                if ($key === null) {
                    return null;
                }
                $val = $this->parseNode($s, $pos, $depth + 1);
                if ($val === null) {
                    return null;
                }
                $changed = $changed || $key['changed'] || $val['changed'];
                $props[] = [$key['node'], $val['node']];
            }
            if ($pos >= $len || $s[$pos] !== '}') {
                return null;
            }
            $pos++; // }
            return [
                'node' => ['type' => 'O', 'class' => $class, 'props' => $props],
                'changed' => $changed,
            ];
        }

        // string (byte-accurate)
        if (preg_match('/\As:(\d+):"/', $rest, $m)) {
            $n = (int) $m[1];
            $start = $pos + strlen($m[0]);
            if ($start + $n + 1 > $len) {
                return null;
            }
            $bytes = substr($s, $start, $n);
            if ($s[$start + $n] !== '"') {
                return null;
            }
            // Skip closing quote AND the trailing semicolon.
            if ($start + $n + 1 >= $len || $s[$start + $n + 1] !== ';') {
                return null;
            }
            $pos = $start + $n + 2;
            $changed = false;

            // Nested serialized payload?
            if ($this->looksSerialized($bytes)) {
                $innerPos = 0;
                $inner = $this->parseNode($bytes, $innerPos, $depth + 1);
                if ($inner !== null && $innerPos === strlen($bytes)) {
                    $innerChanged = false;
                    // Fully parsed: every string leaf was already rewritten,
                    // lengths re-emitted. No further replacement needed.
                    return [
                        'node' => ['type' => 's', 'bytes' => $this->emitNode($inner['node'], $innerChanged)],
                        'changed' => $inner['changed'],
                    ];
                }
            }
            if ($this->containsSearch($bytes)) {
                return [
                    'node' => ['type' => 's', 'bytes' => $this->replaceVariants($bytes)],
                    'changed' => true,
                ];
            }
            return ['node' => ['type' => 's', 'bytes' => $bytes], 'changed' => false];
        }

        // scalars
        if (preg_match('/\Ai:(-?\d+);/', $rest, $m)) {
            $pos += strlen($m[0]);
            return ['node' => ['type' => 'i', 'value' => $m[1]], 'changed' => false];
        }
        if (preg_match('/\Ad:([0-9.eE+-]+|NAN|INF|-INF);/', $rest, $m)) {
            $pos += strlen($m[0]);
            return ['node' => ['type' => 'd', 'value' => $m[1]], 'changed' => false];
        }
        if (preg_match('/\Ab:([01]);/', $rest, $m)) {
            $pos += strlen($m[0]);
            return ['node' => ['type' => 'b', 'value' => $m[1]], 'changed' => false];
        }
        if (strncmp($rest, 'N;', 2) === 0) {
            $pos += 2;
            return ['node' => ['type' => 'N'], 'changed' => false];
        }
        if (preg_match('/\A([rR]):(\d+);/', $rest, $m)) {
            $pos += strlen($m[0]);
            return ['node' => ['type' => $m[1], 'value' => $m[2]], 'changed' => false];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $node
     */
    private function emit(array $node): string
    {
        $changed = false;
        return $this->emitNode($node, $changed);
    }

    /**
     * @param array<string,mixed> $node
     */
    private function emitNode(array $node, bool &$changed): string
    {
        $changed = false;
        switch ($node['type']) {
            case 'array':
                $out = 'a:' . count($node['items']) . ':{';
                foreach ($node['items'] as [$k, $v]) {
                    $c1 = false;
                    $c2 = false;
                    $out .= $this->emitNode($k, $c1) . $this->emitNode($v, $c2);
                    $changed = $changed || $c1 || $c2;
                }
                return $out . '}';
            case 'O':
                $out = 'O:' . strlen($node['class']) . ':"' . $node['class'] . '":'
                    . count($node['props']) . ':{';
                foreach ($node['props'] as [$k, $v]) {
                    $c1 = false;
                    $c2 = false;
                    $out .= $this->emitNode($k, $c1) . $this->emitNode($v, $c2);
                    $changed = $changed || $c1 || $c2;
                }
                return $out . '}';
            case 's':
                /** @var string $bytes Already rewritten during parse if needed. */
                $bytes = $node['bytes'];
                return 's:' . strlen($bytes) . ':"' . $bytes . '";';
            case 'i':
                return 'i:' . $node['value'] . ';';
            case 'd':
                return 'd:' . $node['value'] . ';';
            case 'b':
                return 'b:' . $node['value'] . ';';
            case 'N':
                return 'N;';
            case 'r':
                return 'r:' . $node['value'] . ';';
            case 'R':
                return 'R:' . $node['value'] . ';';
        }
        throw new \LogicException('Unknown node type');
    }
}
