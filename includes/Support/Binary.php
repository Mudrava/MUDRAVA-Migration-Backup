<?php

/**
 * Binary unpack helpers. unpack() returns array|false; the binary contract
 * guarantees success for fixed-size inputs, so fail loudly rather than
 * passing false around.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Support;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are reduced to safe codes at the REST boundary.

final class Binary
{
    /**
     * @return array<int|string,int|string>
     */
    public static function unpack(string $format, string $data): array
    {
        $un = \unpack($format, $data);
        if ($un === false) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: unpack failed for ' . $format);
        }
        return $un;
    }

    /**
     * Unpack a single scalar field (first element).
     *
     * @return int|string
     */
    public static function scalar(string $format, string $data)
    {
        return self::unpack($format, $data)[1];
    }
}
