<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * Single place that knows the refresh cookie's attributes, so
 * LoginSuccessHandler, RefreshTokenController and LogoutController can never
 * disagree on name/path/flags. See docs/authentication.md for the rationale
 * behind each attribute.
 */
final class RefreshTokenCookieFactory
{
    // Not a typed const (`const string X = ...`): that syntax needs PHP 8.3,
    // and composer.json only requires >=8.2.
    public const COOKIE_NAME = 'medvue_refresh_token';

    /**
     * Scoped to the two endpoints that actually read this cookie, so it is
     * never sent to /api/me, /api/register, etc.
     */
    private const COOKIE_PATH = '/api/token';

    public function __construct(
        private readonly bool $secure,
        private readonly int $ttlSeconds,
    ) {
    }

    public function create(string $rawToken): Cookie
    {
        return Cookie::create(self::COOKIE_NAME)
            ->withValue($rawToken)
            ->withExpires(new \DateTimeImmutable("+{$this->ttlSeconds} seconds"))
            ->withPath(self::COOKIE_PATH)
            ->withSecure($this->secure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }

    public function clear(): Cookie
    {
        return Cookie::create(self::COOKIE_NAME)
            ->withValue(null)
            ->withExpires(new \DateTimeImmutable('-1 year'))
            ->withPath(self::COOKIE_PATH)
            ->withSecure($this->secure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }
}
