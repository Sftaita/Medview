<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * docs/decisions.md D108 — behind Traefik every request reaches the backend
 * from the proxy's own address. Without a trusted proxy, `getClientIp()` is
 * that address for *every* visitor, so the register limiter and the login
 * throttling (both keyed by client IP) collapse into one global bucket.
 * These tests pin the intended behaviour: with SYMFONY_TRUSTED_PROXIES set
 * to the proxy's exact address, each real client gets its own buckets and
 * its own recorded IP, and anything that is not that exact address cannot
 * spoof X-Forwarded-For.
 */
final class TrustedProxyClientIpTest extends WebTestCase
{
    private const TRAEFIK_IP = '172.18.0.2';
    private const OTHER_CONTAINER_IP = '172.18.0.3';
    private const CLIENT_A = '203.0.113.10';
    private const CLIENT_B = '203.0.113.20';

    protected function tearDown(): void
    {
        unset($_SERVER['SYMFONY_TRUSTED_PROXIES'], $_ENV['SYMFONY_TRUSTED_PROXIES']);
        // Trusted proxies are process-wide static state on Request.
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        parent::tearDown();
    }

    public function testWithoutATrustedProxyEveryVisitorSharesTheProxyBucket(): void
    {
        $client = $this->boot(trustedProxy: null);

        $this->exhaustRegisterLimiter($client, self::CLIENT_A);

        // A different real visitor is throttled by someone else's attempts:
        // the pre-D108 production behaviour, kept as an explicit fail-safe
        // (a wrong/stale proxy address degrades to this, never to spoofing).
        self::assertSame(429, $this->postRegister($client, self::CLIENT_B));
    }

    public function testATrustedProxyGivesEachRealClientItsOwnRegisterBucket(): void
    {
        $client = $this->boot(trustedProxy: self::TRAEFIK_IP);

        $this->exhaustRegisterLimiter($client, self::CLIENT_A);

        self::assertSame(429, $this->postRegister($client, self::CLIENT_A), 'Client A stays throttled.');
        self::assertSame(422, $this->postRegister($client, self::CLIENT_B), 'Client B must not inherit A\'s throttle.');
    }

    public function testATrustedProxyGivesEachRealClientItsOwnLoginThrottling(): void
    {
        $client = $this->boot(trustedProxy: self::TRAEFIK_IP);

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(401, $this->postLogin($client, self::CLIENT_A, 'same.username@example.com'));
        }
        self::assertSame(429, $this->postLogin($client, self::CLIENT_A, 'same.username@example.com'));

        self::assertSame(401, $this->postLogin($client, self::CLIENT_B, 'same.username@example.com'), 'Same username from another real IP is not throttled.');
    }

    public function testAnotherContainerOnTheProxyNetworkCannotSpoofTheClientIp(): void
    {
        $client = $this->boot(trustedProxy: self::TRAEFIK_IP);

        // Same untrusted peer rotating forged X-Forwarded-For values must
        // still land in ONE bucket (its own address), not evade the limiter.
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(422, $this->postRegister($client, '198.51.100.'.($i + 1), peer: self::OTHER_CONTAINER_IP));
        }
        self::assertSame(429, $this->postRegister($client, '198.51.100.99', peer: self::OTHER_CONTAINER_IP));
    }

    public function testRefreshTokensRecordTheRealClientIp(): void
    {
        $client = $this->boot(trustedProxy: self::TRAEFIK_IP);
        $email = 'trusted.proxy.ip@example.com';

        $client->request('POST', '/api/register', server: $this->viaProxy(self::CLIENT_A), content: json_encode([
            'email' => $email, 'plainPassword' => 'correct-horse-battery', 'firstName' => 'Real', 'lastName' => 'Client',
        ]));
        self::assertResponseStatusCodeSame(201);

        $client->request('POST', '/api/login', server: $this->viaProxy(self::CLIENT_A), content: json_encode([
            'email' => $email, 'password' => 'correct-horse-battery',
        ]));
        self::assertResponseStatusCodeSame(200);

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $ips = $connection->fetchFirstColumn(
            'SELECT rt.created_by_ip FROM refresh_tokens rt JOIN users u ON u.id = rt.user_id WHERE u.email = ?',
            [$email],
        );

        self::assertSame([self::CLIENT_A], array_values(array_unique($ips)), 'Must be the real client, never the proxy address.');
    }

    private function boot(?string $trustedProxy): KernelBrowser
    {
        if (null !== $trustedProxy) {
            $_SERVER['SYMFONY_TRUSTED_PROXIES'] = $trustedProxy;
        }

        $client = static::createClient();
        // Rate limiter storage is not rolled back with the DB transaction.
        static::getContainer()->get('cache.rate_limiter')->clear();

        return $client;
    }

    /** @return array<string, string> */
    private function viaProxy(string $clientIp, string $peer = self::TRAEFIK_IP): array
    {
        return [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $peer,
            'HTTP_X_FORWARDED_FOR' => $clientIp,
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ];
    }

    private function postRegister(KernelBrowser $client, string $clientIp, string $peer = self::TRAEFIK_IP): int
    {
        // An invalid body: the limiter consumes before validation, so no user is created.
        $client->request('POST', '/api/register', server: $this->viaProxy($clientIp, $peer), content: '{}');

        return $client->getResponse()->getStatusCode();
    }

    private function exhaustRegisterLimiter(KernelBrowser $client, string $clientIp): void
    {
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(422, $this->postRegister($client, $clientIp));
        }
    }

    private function postLogin(KernelBrowser $client, string $clientIp, string $email): int
    {
        $client->request('POST', '/api/login', server: $this->viaProxy($clientIp), content: json_encode([
            'email' => $email, 'password' => 'wrong-password',
        ]));

        return $client->getResponse()->getStatusCode();
    }
}
