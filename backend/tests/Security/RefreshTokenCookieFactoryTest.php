<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\RefreshTokenCookieFactory;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests, no kernel needed: this class owns every security
 * attribute of the refresh cookie, so its contract is worth locking in
 * independently of whichever controller happens to call it.
 */
final class RefreshTokenCookieFactoryTest extends TestCase
{
    public function testCreateSetsAllSecurityAttributes(): void
    {
        $factory = new RefreshTokenCookieFactory(secure: true, ttlSeconds: 3600);
        $cookie = $factory->create('raw-token-value');

        self::assertSame('medvue_refresh_token', $cookie->getName());
        self::assertSame('raw-token-value', $cookie->getValue());
        self::assertSame('/api/token', $cookie->getPath());
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame('lax', strtolower((string) $cookie->getSameSite()));
        self::assertGreaterThan(time(), (int) $cookie->getExpiresTime());
    }

    public function testCreateRespectsSecureFalseForDev(): void
    {
        $factory = new RefreshTokenCookieFactory(secure: false, ttlSeconds: 3600);
        $cookie = $factory->create('raw-token-value');

        self::assertFalse($cookie->isSecure(), 'Secure must stay opt-in per environment, not hardcoded true.');
    }

    public function testCreateExpiryMatchesTheConfiguredTtl(): void
    {
        $factory = new RefreshTokenCookieFactory(secure: true, ttlSeconds: 120);
        $before = time();
        $cookie = $factory->create('raw-token-value');
        $after = time();

        self::assertGreaterThanOrEqual($before + 120, (int) $cookie->getExpiresTime());
        self::assertLessThanOrEqual($after + 120, (int) $cookie->getExpiresTime());
    }

    public function testClearProducesAnAlreadyExpiredCookieWithNoValue(): void
    {
        $factory = new RefreshTokenCookieFactory(secure: true, ttlSeconds: 3600);
        $cookie = $factory->clear();

        self::assertSame('medvue_refresh_token', $cookie->getName());
        self::assertNull($cookie->getValue());
        self::assertSame('/api/token', $cookie->getPath());
        self::assertLessThan(time(), (int) $cookie->getExpiresTime());
        // The clearing cookie must carry the same security attributes as
        // the real one, or the browser could end up storing two entries.
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', strtolower((string) $cookie->getSameSite()));
    }
}
