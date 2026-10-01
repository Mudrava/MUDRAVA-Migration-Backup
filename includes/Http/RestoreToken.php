<?php

/**
 * Restore-scoped bearer token.
 *
 * A browser-driven restore rewrites the database table by table. As soon as
 * wp_usermeta is restored, the operator's session tokens are replaced by the
 * source site's, so the cookie (and its nonce) stop validating mid-job and
 * every later tick would fail with rest_cookie_invalid_nonce.
 *
 * The fix is an authentication channel that does NOT live in the database:
 * a 256-bit random token issued at restore start, sent as a request header,
 * and verified against an HMAC stored in a 0600 file inside the protected
 * storage directory (which is excluded from every archive). Because it is a
 * header rather than a cookie, it is inherently CSRF-safe; because it is
 * random and short-lived, it is safe to hold in the browser for one job.
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Http;

use Mudrava\Migration\Support\Paths;

defined('ABSPATH') || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Protected binary archives require seekable native streams and atomic file operations.

final class RestoreToken
{
    /** Header carrying the token. Sent only while an import job is running. */
    public const HEADER = 'X-Mudrava-Restore';

    /** Token lifetime, seconds. Comfortably covers a large restore, bounded
     *  so an abandoned job cannot leave a live credential forever. */
    private const TTL = 21600; // 6 hours

    /** Domain separator for the HMAC so this digest cannot be reused elsewhere. */
    private const HMAC_KEY = 'mudrava-restore-token-v1';

    public static function path(): string
    {
        return (new Paths())->storageDir() . '/restore-token.bin';
    }

    /**
     * Issue a fresh token bound to a user id. Returns the hex secret handed
     * to the browser; only its HMAC is written to disk.
     */
    public static function issue(int $userId): string
    {
        // Guarantee the protected storage exists before writing the token.
        (new Paths())->ensureStorage();
        $raw = random_bytes(32);
        $body = $userId . "\n" . time() . "\n" . hash_hmac('sha256', $raw, self::HMAC_KEY);
        $path = self::path();
        if (@file_put_contents($path, $body) === false) {
            throw new \RuntimeException('MUDRAVA_PERMISSION_DENIED: cannot write restore token');
        }
        @chmod($path, 0600);
        return bin2hex($raw);
    }

    /**
     * Verify a presented token. Returns the bound user id, or null when the
     * token is absent, malformed, expired, or does not match.
     */
    public static function verify(?string $rawHex): ?int
    {
        if ($rawHex === null || $rawHex === '' || strlen($rawHex) !== 64) {
            return null;
        }
        $raw = @hex2bin($rawHex);
        if ($raw === false || strlen($raw) !== 32) {
            return null;
        }
        $path = self::path();
        if (!is_file($path)) {
            return null;
        }
        $body = @file_get_contents($path);
        if (!is_string($body)) {
            return null;
        }
        $parts = explode("\n", $body);
        if (count($parts) !== 3) {
            return null;
        }
        [$userId, $createdAt, $stored] = $parts;
        if (!ctype_digit($userId) || !ctype_digit($createdAt)) {
            return null;
        }
        if (time() - (int) $createdAt > self::TTL) {
            return null;
        }
        $candidate = hash_hmac('sha256', $raw, self::HMAC_KEY);
        if (!hash_equals($stored, $candidate)) {
            return null;
        }
        return (int) $userId;
    }

    /** Read the token from the current request header, if present. */
    public static function fromRequest(): ?string
    {
        if (!isset($_SERVER['HTTP_X_MUDRAVA_RESTORE'])) {
            return null;
        }
        // sanitize_text_field is a no-op on a hex token; verify() still
        // enforces the exact 64-hex shape and the HMAC match.
        $value = sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_X_MUDRAVA_RESTORE']));
        return $value !== '' ? $value : null;
    }

    /**
     * determine_current_user filter (priority 25, after the cookie and
     * application-password handlers). When a valid restore token is present
     * and no cookie authenticated the request, resolve the bound user. This
     * keeps the operator logged in across the restore even after wp_usermeta
     * (and therefore their session tokens) has been replaced, which in turn
     * makes rest_cookie_check_errors skip its nonce check instead of failing
     * with rest_cookie_invalid_nonce. Requests without the header are
     * returned untouched, so normal traffic is unaffected.
     *
     * @param int|false $user already-determined user, or false
     * @return int|false
     */
    public static function authenticate($user)
    {
        if ($user) {
            return $user;
        }
        // determine_current_user runs for every WordPress request. A bearer
        // credential for one restore must never authenticate core REST routes
        // (or another MUDRAVA operation) as a site administrator.
        if (!self::isTickRequest()) {
            return $user;
        }
        $uid = self::verify(self::fromRequest());
        return $uid === null ? $user : $uid;
    }

    private static function isTickRequest(): bool
    {
        if (!defined('REST_REQUEST') || !REST_REQUEST) {
            return false;
        }
        $method = isset($_SERVER['REQUEST_METHOD'])
            ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_METHOD']))
            : '';
        if ($method !== 'POST') {
            return false;
        }
        $uri = isset($_SERVER['REQUEST_URI'])
            ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_URI']))
            : '';
        $query = wp_parse_url($uri, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $params);
            if (isset($params['rest_route']) && is_string($params['rest_route'])) {
                return rtrim($params['rest_route'], '/') === '/mudrava/v1/job/tick';
            }
        }
        $path = wp_parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return false;
        }
        $prefix = function_exists('rest_get_url_prefix') ? rest_get_url_prefix() : 'wp-json';
        return (bool) preg_match(
            '#/' . preg_quote(trim($prefix, '/'), '#') . '/mudrava/v1/job/tick/?$#',
            $path
        );
    }

    /** Invalidate any issued token (job finished, failed, or cancelled). */
    public static function clear(): void
    {
        $path = self::path();
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
