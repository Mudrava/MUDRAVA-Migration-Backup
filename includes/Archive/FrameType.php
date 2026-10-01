<?php

/**
 * Frame type constants - part of the frozen format contract (docs/mudrava-format.md).
 * Values must never be reused or renamed.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Archive;

final class FrameType
{
    public const SITE_METADATA    = 0x01;
    public const DB_TABLE_BEGIN   = 0x10;
    public const DB_SCHEMA        = 0x11;
    public const DB_ROWS          = 0x12;
    public const DB_TABLE_END     = 0x13;
    public const FILE_METADATA    = 0x20;
    public const FILE_DATA        = 0x21;
    public const CHECKPOINT       = 0x30;
    public const MANIFEST         = 0x40;
    public const FOOTER           = 0x41;

    /** Frame types whose payload is JSON. */
    public const JSON_TYPES = [
        self::SITE_METADATA,
        self::DB_TABLE_BEGIN,
        self::DB_TABLE_END,
        self::FILE_METADATA,
        self::CHECKPOINT,
        self::MANIFEST,
        self::FOOTER,
    ];

    /** Types required for a structurally complete archive. */
    public const REQUIRED = [
        self::SITE_METADATA,
        self::MANIFEST,
        self::FOOTER,
    ];

    public static function isKnown(int $type): bool
    {
        return in_array($type, [
            self::SITE_METADATA, self::DB_TABLE_BEGIN, self::DB_SCHEMA,
            self::DB_ROWS, self::DB_TABLE_END, self::FILE_METADATA,
            self::FILE_DATA, self::CHECKPOINT, self::MANIFEST, self::FOOTER,
        ], true);
    }

    /** Optional (skippable) types have the high bit set. */
    public static function isOptional(int $type): bool
    {
        return ($type & 0x80) !== 0;
    }
}
