<?php

/**
 * Per-frame DEFLATE codec. Falls back to raw storage when compression
 * does not help (incompressible payloads).
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Compression;

final class DeflateCodec
{
    public const LEVEL = 6;

    public function compress(string $data): string
    {
        if ($data === '') {
            return '';
        }
        $out = @gzdeflate($data, self::LEVEL);
        if ($out === false || strlen($out) >= strlen($data)) {
            // Incompressible or failure: store raw.
            return $data;
        }
        return $out;
    }

    public function decompress(string $data, bool $compressed, int $maxBytes): string
    {
        if ($maxBytes < 0 || (!$compressed && strlen($data) > $maxBytes)) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: logical length exceeds frame limit');
        }
        if (!$compressed || $data === '') {
            return $data;
        }
        // PHP treats a zero max_length as unlimited. A compressed empty
        // frame is invalid; never pass zero to the inflater.
        if ($maxBytes === 0) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: compressed frame has zero logical length');
        }
        $out = @gzinflate($data, $maxBytes);
        if ($out === false) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: inflate failed');
        }
        return $out;
    }
}
