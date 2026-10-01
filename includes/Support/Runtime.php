<?php

/**
 * Runtime capability checks shared by preflight and engine guards.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Support;

final class Runtime
{
    /** 1 TiB - minimum addressable offset we refuse to run below. */
    public const MIN_OFFSET_SPACE = 1099511627776;

    /**
     * @return list<array{code:string,ok:bool,detail:string}>
     */
    public static function inspect(): array
    {
        $checks = [];

        $checks[] = [
            'code'   => 'php_version',
            'ok'     => PHP_VERSION_ID >= 70400,
            'detail' => 'PHP ' . PHP_VERSION,
        ];

        // 64-bit integer width: without it, large offsets are unsafe.
        $checks[] = [
            'code'   => 'int64',
            'ok'     => PHP_INT_SIZE >= 8 && PHP_INT_MAX >= self::MIN_OFFSET_SPACE,
            'detail' => 'PHP_INT_MAX=' . PHP_INT_MAX,
        ];

        $checks[] = [
            'code'   => 'zlib',
            'ok'     => function_exists('gzdeflate') && function_exists('gzinflate'),
            'detail' => function_exists('gzdeflate') ? 'available' : 'gzdeflate missing',
        ];

        $crypto = \Mudrava\Migration\Crypto\CryptoCapability::detect();
        $checks[] = [
            'code'   => 'crypto',
            'ok'     => $crypto->available,
            'detail' => $crypto->describe(),
        ];

        $checks[] = [
            'code'   => 'json',
            'ok'     => function_exists('json_encode'),
            'detail' => function_exists('json_encode') ? 'available' : 'missing',
        ];

        return $checks;
    }

    public static function isSupported(): bool
    {
        foreach (self::inspect() as $check) {
            if (!$check['ok'] && in_array($check['code'], ['php_version', 'int64', 'zlib', 'json'], true)) {
                return false;
            }
        }
        return true;
    }
}
