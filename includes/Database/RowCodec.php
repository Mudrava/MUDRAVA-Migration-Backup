<?php

/**
 * Binary row-batch encoding (spec §7). Raw database bytes, BLOB-safe.
 *
 * uint16 column_count | uint32 row_count | per row, per column:
 *   uint8 kind (0=NULL, 1=bytes) | (kind 1) uint32 len + raw bytes
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Database;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exceptions are reduced to safe codes at the REST boundary.

final class RowCodec
{
    public const MAX_COLUMNS = 65535;

    /**
     * @param list<list<?string>> $rows each cell: string bytes or null
     */
    public static function encode(array $rows): string
    {
        $columnCount = null;
        $out = '';
        $rowCount = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Row must be an array.');
            }
            $n = count($row);
            if ($n === 0 || $n > self::MAX_COLUMNS) {
                throw new \InvalidArgumentException('Invalid column count: ' . $n);
            }
            if ($columnCount === null) {
                $columnCount = $n;
            } elseif ($columnCount !== $n) {
                throw new \InvalidArgumentException('Ragged row batch.');
            }
            foreach ($row as $cell) {
                if ($cell === null) {
                    $out .= chr(0);
                } elseif (is_string($cell)) {
                    $out .= chr(1) . pack('N', strlen($cell)) . $cell;
                } else {
                    throw new \InvalidArgumentException('Cell must be string or null.');
                }
            }
            $rowCount++;
        }
        return pack('n', (int) $columnCount) . pack('N', $rowCount) . $out;
    }

    /**
     * @return list<list<?string>>
     */
    public static function decode(string $binary): array
    {
        if (strlen($binary) < 6) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: row batch too short');
        }
        $un = \Mudrava\Migration\Support\Binary::unpack('ncols/Nrows', substr($binary, 0, 6));
        $cols = (int) $un['cols'];
        $rows = (int) $un['rows'];
        // The field is uint16, so unpack() already bounds it to MAX_COLUMNS.
        if ($cols === 0) {
            if ($rows !== 0 || strlen($binary) !== 6) {
                throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: zero columns with data');
            }
            return [];
        }
        $off = 6;
        $len = strlen($binary);
        $result = [];
        for ($r = 0; $r < $rows; $r++) {
            $row = [];
            for ($c = 0; $c < $cols; $c++) {
                if ($off >= $len) {
                    throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: row batch truncated');
                }
                $kind = ord($binary[$off]);
                $off++;
                if ($kind === 0) {
                    $row[] = null;
                } elseif ($kind === 1) {
                    if ($off + 4 > $len) {
                        throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: cell length truncated');
                    }
                    $clen = (int) \Mudrava\Migration\Support\Binary::scalar('N', substr($binary, $off, 4));
                    $off += 4;
                    if ($off + $clen > $len) {
                        throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: cell bytes truncated');
                    }
                    $row[] = (string) substr($binary, $off, (int) $clen);
                    $off += (int) $clen;
                } else {
                    throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: unknown cell kind ' . $kind);
                }
            }
            $result[] = $row;
        }
        if ($off !== $len) {
            throw new \RuntimeException('MUDRAVA_ARCHIVE_CORRUPT: trailing bytes in row batch');
        }
        return $result;
    }
}
