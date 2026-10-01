<?php
/**
 * Restore-scoped token contract: issue/verify round-trip, tamper rejection,
 * expiry, and the determine_current_user passthrough behavior. This is the
 * authentication channel that keeps a browser-driven restore alive after the
 * restore replaces wp_usermeta (and thus the operator's session cookie).
 *
 * This file is part of MUDRAVA Migration & Backup.
 * Copyright (C) 2026 MUDRAVA.
 * Licensed under GPL-2.0-or-later. See LICENSE in the plugin root.
 */

declare(strict_types=1);

namespace Mudrava\Migration\Tests\Unit;

use Mudrava\Migration\Http\RestoreToken;
use PHPUnit\Framework\TestCase;

final class RestoreTokenTest extends TestCase
{
    protected function setUp(): void
    {
        RestoreToken::clear();
    }

    protected function tearDown(): void
    {
        RestoreToken::clear();
    }

    public function testIssueVerifyRoundTrip(): void
    {
        $token = RestoreToken::issue(7);
        $this->assertSame(64, strlen($token), 'token is 64 hex chars');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame(7, RestoreToken::verify($token), 'valid token resolves its user');
    }

    public function testWrongTokenRejected(): void
    {
        RestoreToken::issue(7);
        $forged = bin2hex(random_bytes(32));
        $this->assertNull(RestoreToken::verify($forged), 'a random token must not verify');
    }

    public function testTamperedStoredDigestRejected(): void
    {
        $token = RestoreToken::issue(7);
        // Flip one hex char of the token: HMAC must no longer match.
        $flipped = $token[0] === 'a' ? 'b' . substr($token, 1) : 'a' . substr($token, 1);
        $this->assertNull(RestoreToken::verify($flipped));
    }

    public function testMalformedInputRejected(): void
    {
        RestoreToken::issue(7);
        $this->assertNull(RestoreToken::verify(null));
        $this->assertNull(RestoreToken::verify(''));
        $this->assertNull(RestoreToken::verify('zz' . str_repeat('0', 62)), 'non-hex rejected');
        $this->assertNull(RestoreToken::verify(str_repeat('0', 63)), 'wrong length rejected');
    }

    public function testClearInvalidates(): void
    {
        $token = RestoreToken::issue(7);
        $this->assertSame(7, RestoreToken::verify($token));
        RestoreToken::clear();
        $this->assertNull(RestoreToken::verify($token), 'cleared token must not verify');
    }

    public function testAuthenticatePassesThroughExistingUser(): void
    {
        // A request that already authenticated by cookie keeps its user.
        $this->assertSame(3, RestoreToken::authenticate(3));
        $this->assertFalse(RestoreToken::authenticate(false), 'no token, no user: unchanged');
    }

    public function testAuthenticateResolvesBoundUser(): void
    {
        if (!defined('REST_REQUEST')) {
            define('REST_REQUEST', true);
        }
        $token = RestoreToken::issue(42);
        $_SERVER['HTTP_X_MUDRAVA_RESTORE'] = $token;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/wp-json/mudrava/v1/job/tick';
        try {
            $this->assertSame(42, RestoreToken::authenticate(false));
            $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/settings';
            $this->assertFalse(RestoreToken::authenticate(false), 'core REST routes must not accept the token');
            $_SERVER['REQUEST_URI'] = '/wp-json/mudrava/v1/archives';
            $this->assertFalse(RestoreToken::authenticate(false), 'other plugin routes must not accept the token');
            $_SERVER['REQUEST_URI'] = '/wp-json/mudrava/v1/job/tick';
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $this->assertFalse(RestoreToken::authenticate(false), 'only POST ticks accept the token');
        } finally {
            unset($_SERVER['HTTP_X_MUDRAVA_RESTORE'], $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
        }
    }

    public function testAuthenticateIgnoresBadToken(): void
    {
        RestoreToken::issue(42);
        $_SERVER['HTTP_X_MUDRAVA_RESTORE'] = bin2hex(random_bytes(32));
        try {
            $this->assertFalse(RestoreToken::authenticate(false), 'bad token leaves user unset');
        } finally {
            unset($_SERVER['HTTP_X_MUDRAVA_RESTORE']);
        }
    }

    /**
     * @dataProvider malformedTokenFileProvider
     */
    public function testMalformedStoredTokenIsRejected(string $body): void
    {
        (new \Mudrava\Migration\Support\Paths())->ensureStorage();
        file_put_contents(RestoreToken::path(), $body);
        $this->assertNull(RestoreToken::verify(str_repeat('a', 64)));
    }

    /** @return array<string,array{string}> */
    public function malformedTokenFileProvider(): array
    {
        return [
            'wrong field count' => ['broken'],
            'non numeric user' => ["user\n" . time() . "\n" . str_repeat('a', 64)],
            'non numeric time' => ["7\nnever\n" . str_repeat('a', 64)],
            'expired' => ["7\n" . (time() - 21601) . "\n" . str_repeat('a', 64)],
        ];
    }

    public function testRequestHeaderIsSanitizedAndEmptyHeaderIsIgnored(): void
    {
        $this->assertNull(RestoreToken::fromRequest());
        $_SERVER['HTTP_X_MUDRAVA_RESTORE'] = '  abc  ';
        $this->assertSame('abc', RestoreToken::fromRequest());
        $_SERVER['HTTP_X_MUDRAVA_RESTORE'] = '';
        $this->assertNull(RestoreToken::fromRequest());
        unset($_SERVER['HTTP_X_MUDRAVA_RESTORE']);
    }

    public function testQueryStyleRestRouteAcceptsOnlyExactPostTick(): void
    {
        if (!defined('REST_REQUEST')) {
            define('REST_REQUEST', true);
        }
        $token = RestoreToken::issue(31);
        $_SERVER['HTTP_X_MUDRAVA_RESTORE'] = $token;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=%2Fmudrava%2Fv1%2Fjob%2Ftick%2F';
        try {
            $this->assertSame(31, RestoreToken::authenticate(false));
            $_SERVER['REQUEST_URI'] = '/index.php?rest_route=%2Fwp%2Fv2%2Fusers';
            $this->assertFalse(RestoreToken::authenticate(false));
        } finally {
            unset($_SERVER['HTTP_X_MUDRAVA_RESTORE'], $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
        }
    }
}
