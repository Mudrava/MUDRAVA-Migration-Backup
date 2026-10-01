<?php

/**
 * Cheap metadata peek for an uploaded archive: the first frame
 * (SITE_METADATA) carries the source site URL, so the import screen can
 * pre-fill the URL rewrite instead of making the operator type it. Reads
 * only the header + first frame; never streams the whole archive.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Archive;

use Mudrava\Migration\Compression\DeflateCodec;
use Mudrava\Migration\Crypto\CryptoCapability;
use Mudrava\Migration\Crypto\FrameCipher;
use Mudrava\Migration\Crypto\KeyDerivation;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Protected binary archives require seekable native streams and atomic file operations.

final class ArchiveInfo
{
    /**
     * @return array{source_url:string,home_url:string,encrypted:bool,hint:string,unreadable:bool}
     */
    public static function peek(string $basePath, ?string $password = null): array
    {
        $out = [
            'source_url' => '',
            'home_url'   => '',
            'encrypted'  => false,
            'hint'       => '',
            'unreadable' => true,
        ];
        $fh = @fopen($basePath, 'rb');
        if ($fh === false) {
            return $out;
        }
        try {
            $codec = new DeflateCodec();
            $reader = new FrameReader($fh, $codec);
            $header = $reader->readHeader();
            $out['encrypted'] = $header->isEncrypted();
            $out['hint'] = $header->passwordHint;
            if ($header->isEncrypted()) {
                if ($password === null || $password === '') {
                    // Metadata is sealed with the payload; nothing to show
                    // until the operator supplies the password.
                    $out['unreadable'] = false;
                    return $out;
                }
                $stack = CryptoCapability::stackForKdf($header->kdf);
                if (!CryptoCapability::supports($stack)) {
                    throw new \RuntimeException('MUDRAVA_CRYPTO_UNAVAILABLE: archive requires ' . $stack);
                }
                $key = KeyDerivation::derive(
                    $password,
                    $header->kdf,
                    $header->kdfOpslimit,
                    $header->kdfMemlimit,
                    $header->kdfSalt
                );
                // Reopen from byte 0: the first reader consumed the header
                // without a cipher and cannot continue sealed.
                fclose($fh);
                $fh = @fopen($basePath, 'rb');
                if ($fh === false) {
                    return $out;
                }
                $reader = new FrameReader($fh, $codec, new FrameCipher($key, $header->noncePrefix, $stack));
            }
            $frame = $reader->next();
            if ($frame !== null && $frame->type === FrameType::SITE_METADATA) {
                $meta = json_decode($frame->payload, true);
                if (is_array($meta)) {
                    $out['source_url'] = (string) ($meta['site_url'] ?? '');
                    $out['home_url'] = (string) ($meta['home_url'] ?? '');
                    $out['unreadable'] = false;
                }
            }
            return $out;
        } catch (\Throwable $e) {
            // Unreadable header/frame: the card still lists the archive and
            // the operator fills the rewrite manually.
            return $out;
        } finally {
            if (is_resource($fh)) {
                fclose($fh);
            }
        }
    }
}
