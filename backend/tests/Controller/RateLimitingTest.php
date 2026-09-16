<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\AuthenticationTestHelpers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RateLimitingTest extends WebTestCase
{
    use AuthenticationTestHelpers;

    /**
     * The rate limiter's cache storage survives across test runs (it's not
     * part of the DB transaction dama/doctrine-test-bundle rolls back), so
     * a previous run against the same fixed IPs below would otherwise make
     * these tests flaky on a second execution. Must run after
     * createClient() boots the kernel, not in setUp().
     */
    private function clearRateLimiterCache(): void
    {
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testLoginIsRateLimitedAfterTooManyFailedAttempts(): void
    {
        $client = static::createClient();
        $this->clearRateLimiterCache();
        $ip = '198.51.100.10';
        $this->registerUser($client, 'ratelimit.login@example.com', 'correct-horse-battery', ip: $ip);

        // login_throttling allows 5 attempts per username+IP within the window.
        for ($i = 0; $i < 5; ++$i) {
            $client->request(
                'POST',
                '/api/login',
                server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip],
                content: json_encode(['email' => 'ratelimit.login@example.com', 'password' => 'wrong-password']),
            );
            self::assertResponseStatusCodeSame(401, "Attempt {$i} should still be a plain auth failure.");
        }

        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip],
            content: json_encode(['email' => 'ratelimit.login@example.com', 'password' => 'wrong-password']),
        );

        self::assertResponseStatusCodeSame(429);
        self::assertNotNull($client->getResponse()->headers->get('Retry-After'));

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('too_many_attempts', $data['error']);
    }

    public function testLoginThrottlingIsScopedPerIp(): void
    {
        $client = static::createClient();
        $this->clearRateLimiterCache();
        $exhaustedIp = '198.51.100.11';
        $otherIp = '198.51.100.12';
        $this->registerUser($client, 'ratelimit.scoped@example.com', 'correct-horse-battery', ip: $exhaustedIp);

        for ($i = 0; $i < 5; ++$i) {
            $client->request(
                'POST',
                '/api/login',
                server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $exhaustedIp],
                content: json_encode(['email' => 'ratelimit.scoped@example.com', 'password' => 'wrong-password']),
            );
        }
        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $exhaustedIp],
            content: json_encode(['email' => 'ratelimit.scoped@example.com', 'password' => 'wrong-password']),
        );
        self::assertResponseStatusCodeSame(429, 'Precondition: the first IP must be throttled.');

        // A different IP attempting the *same* account must not be blocked
        // by the first IP's exhausted local limiter.
        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $otherIp],
            content: json_encode(['email' => 'ratelimit.scoped@example.com', 'password' => 'correct-horse-battery']),
        );
        self::assertResponseStatusCodeSame(200);
    }

    public function testRegisterIsRateLimitedAfterTooManyAttempts(): void
    {
        $client = static::createClient();
        $this->clearRateLimiterCache();
        $ip = '198.51.100.20';

        // The "register" limiter allows 5 attempts per IP per hour.
        for ($i = 0; $i < 5; ++$i) {
            $this->registerUser($client, "ratelimit.register.{$i}@example.com", 'correct-horse-battery', ip: $ip);
        }

        $client->request(
            'POST',
            '/api/register',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip],
            content: json_encode([
                'email' => 'ratelimit.register.overflow@example.com',
                'plainPassword' => 'correct-horse-battery',
                'firstName' => 'Over',
                'lastName' => 'Flow',
            ]),
        );

        self::assertResponseStatusCodeSame(429);
        self::assertNotNull($client->getResponse()->headers->get('Retry-After'));

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('too_many_attempts', $data['error']);
    }
}
