<?php

/**
 * Stable error codes. Never rename; add new ones only.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Support;

final class ErrorCode
{
    public const RUNTIME_UNSUPPORTED  = 'MUDRAVA_RUNTIME_UNSUPPORTED';
    public const DISK_FULL            = 'MUDRAVA_DISK_FULL';
    public const PERMISSION_DENIED    = 'MUDRAVA_PERMISSION_DENIED';
    public const STORAGE_NOT_WRITABLE = 'MUDRAVA_STORAGE_NOT_WRITABLE';
    public const STORAGE_UNSAFE       = 'MUDRAVA_STORAGE_UNSAFE';
    public const MEMORY_LIMIT         = 'MUDRAVA_MEMORY_LIMIT';
    public const ARCHIVE_CORRUPT      = 'MUDRAVA_ARCHIVE_CORRUPT';
    public const ARCHIVE_CHANGED      = 'MUDRAVA_ARCHIVE_CHANGED';
    public const ARCHIVE_TRUNCATED    = 'MUDRAVA_ARCHIVE_TRUNCATED';
    public const FORMAT_UNSUPPORTED   = 'MUDRAVA_FORMAT_UNSUPPORTED';
    public const WRONG_PASSWORD       = 'MUDRAVA_WRONG_PASSWORD';
    public const PART_MISSING         = 'MUDRAVA_PART_MISSING';
    public const PART_MISMATCH        = 'MUDRAVA_PART_MISMATCH';
    public const MANIFEST_MISMATCH    = 'MUDRAVA_MANIFEST_MISMATCH';
    public const DB_CONNECT           = 'MUDRAVA_DB_CONNECT';
    public const DB_QUERY_FAILED      = 'MUDRAVA_DB_QUERY_FAILED';
    public const DB_ROW_TOO_LARGE     = 'MUDRAVA_DB_ROW_TOO_LARGE';
    public const DB_TABLE_CHANGED     = 'MUDRAVA_DB_TABLE_CHANGED';
    public const REMOTE_TIMEOUT       = 'MUDRAVA_REMOTE_TIMEOUT';
    public const REMOTE_UNREACHABLE   = 'MUDRAVA_REMOTE_UNREACHABLE';
    public const CRYPTO_UNAVAILABLE   = 'MUDRAVA_CRYPTO_UNAVAILABLE';
    public const PATH_UNSAFE          = 'MUDRAVA_PATH_UNSAFE';
    public const JOB_LOCKED           = 'MUDRAVA_JOB_LOCKED';
    public const JOB_NOT_FOUND        = 'MUDRAVA_JOB_NOT_FOUND';
    public const JOB_NOT_CANCELLABLE  = 'MUDRAVA_JOB_NOT_CANCELLABLE';
    public const UPLOAD_CHUNK_INVALID = 'MUDRAVA_UPLOAD_CHUNK_INVALID';
    public const PREFLIGHT_FAILED     = 'MUDRAVA_PREFLIGHT_FAILED';
    public const MULTISITE_UNSUPPORTED = 'MUDRAVA_MULTISITE_UNSUPPORTED';

    /**
     * Human messages shown to users. Developer detail is attached separately.
     *
     * @return array<string,string>
     */
    public static function userMessages(): array
    {
        return [
            self::RUNTIME_UNSUPPORTED  => __('This server cannot safely run large migrations.', 'mudrava-migration-backup'),
            self::DISK_FULL            => __('Migration paused because the disk is full.', 'mudrava-migration-backup'),
            self::PERMISSION_DENIED    => __('MUDRAVA cannot write where it needs to. Check folder permissions.', 'mudrava-migration-backup'),
            self::STORAGE_NOT_WRITABLE => __(
                'The backup storage folder is not writable by the web server. Fix its ownership (it may have been created by a root shell).',
                'mudrava-migration-backup'
            ),
            self::STORAGE_UNSAFE       => __(
                'Private backup storage is unsafe or ambiguous. Set MUDRAVA_MB_STORAGE_DIR to one writable directory outside the web root.',
                'mudrava-migration-backup'
            ),
            self::MEMORY_LIMIT         => __('This migration step needs more PHP memory than this server allows.', 'mudrava-migration-backup'),
            self::ARCHIVE_CORRUPT      => __('The archive is damaged and cannot be read safely.', 'mudrava-migration-backup'),
            self::ARCHIVE_CHANGED      => __(
                'The backup file changed during verification or restore. Upload the original archive again.',
                'mudrava-migration-backup'
            ),
            self::ARCHIVE_TRUNCATED    => __('The archive is incomplete. The transfer may have been interrupted.', 'mudrava-migration-backup'),
            self::FORMAT_UNSUPPORTED   => __('This archive uses a newer format. Please update MUDRAVA first.', 'mudrava-migration-backup'),
            self::WRONG_PASSWORD       => __('The password is incorrect. The archive is still intact.', 'mudrava-migration-backup'),
            self::PART_MISSING         => __('Some parts of this backup are missing.', 'mudrava-migration-backup'),
            self::PART_MISMATCH        => __('One of the parts belongs to a different backup.', 'mudrava-migration-backup'),
            self::MANIFEST_MISMATCH    => __('The archive did not pass integrity verification.', 'mudrava-migration-backup'),
            self::DB_CONNECT           => __('The database connection was lost.', 'mudrava-migration-backup'),
            self::DB_QUERY_FAILED      => __('A database operation failed during the migration.', 'mudrava-migration-backup'),
            self::DB_ROW_TOO_LARGE     => __('A database row is too large for the backup format. Reduce the row size and retry.', 'mudrava-migration-backup'),
            self::DB_TABLE_CHANGED     => __(
                'A table without a primary key changed during the backup, so rows could be missed. Pause writes to it and re-run the backup.',
                'mudrava-migration-backup'
            ),
            self::REMOTE_TIMEOUT       => __('The remote server took too long to respond. You can resume.', 'mudrava-migration-backup'),
            self::REMOTE_UNREACHABLE   => __('The remote server could not be reached.', 'mudrava-migration-backup'),
            self::CRYPTO_UNAVAILABLE   => __('Password encryption is unavailable on this PHP environment.', 'mudrava-migration-backup'),
            self::PATH_UNSAFE          => __('The archive contains a path that cannot be restored safely.', 'mudrava-migration-backup'),
            self::JOB_LOCKED           => __('Another migration job is already running.', 'mudrava-migration-backup'),
            self::JOB_NOT_FOUND        => __('This job no longer exists.', 'mudrava-migration-backup'),
            self::JOB_NOT_CANCELLABLE  => __('Only a running export can be cancelled.', 'mudrava-migration-backup'),
            self::UPLOAD_CHUNK_INVALID => __('An upload chunk was rejected. Please retry.', 'mudrava-migration-backup'),
            self::PREFLIGHT_FAILED     => __('This server is missing requirements for the requested operation.', 'mudrava-migration-backup'),
            self::MULTISITE_UNSUPPORTED => __(
                'Multisite migrations are not supported. MUDRAVA migrates single sites.',
                'mudrava-migration-backup'
            ),
        ];
    }

    public static function userMessage(string $code): string
    {
        $messages = self::userMessages();
        return $messages[$code] ?? __('The migration could not be completed.', 'mudrava-migration-backup');
    }
}
