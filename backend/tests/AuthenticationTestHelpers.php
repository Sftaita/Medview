<?php

declare(strict_types=1);

namespace App\Tests;

use App\Security\RefreshTokenCookieFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie as BrowserKitCookie;

trait AuthenticationTestHelpers
{
    /**
     * Registration and login are both IP-rate-limited (see
     * config/packages/rate_limiter.yaml and the login firewall's
     * login_throttling). The rate limiter's cache storage is NOT reset by
     * dama/doctrine-test-bundle's per-test transaction rollback, so without
     * this every test in the suite sharing one fake client IP would
     * eventually rate-limit *each other*. Each call gets its own random IP
     * by default; pass $ip explicitly only when a test deliberately wants
     * several calls to share one IP (i.e. the rate-limiting tests themselves).
     */
    private function randomTestIp(): string
    {
        return \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
    }

    /**
     * Body of a valid POST /api/register — every required field present.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function registrationPayload(string $email, string $password = 'correct-horse-battery', array $overrides = []): array
    {
        return array_merge([
            'email' => $email,
            'plainPassword' => $password,
            'firstName' => 'Test',
            'lastName' => 'User',
            'phone' => '+32 470 12 34 56',
        ], $overrides);
    }

    private function registerUser(KernelBrowser $client, string $email, string $password, ?string $ip = null): void
    {
        $client->request(
            'POST',
            '/api/register',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip ?? $this->randomTestIp()],
            content: json_encode($this->registrationPayload($email, $password)),
        );

        self::assertResponseStatusCodeSame(201);
    }

    /**
     * Logs in and returns the access token. The refresh cookie lands in the
     * client's own cookie jar (BrowserKit simulates a real browser), so
     * subsequent requests on the same $client carry it automatically.
     */
    private function loginUser(KernelBrowser $client, string $email, string $password, ?string $ip = null): string
    {
        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip ?? $this->randomTestIp()],
            content: json_encode(['email' => $email, 'password' => $password]),
        );

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        return $data['token'];
    }

    private function getRefreshCookieValue(KernelBrowser $client): ?string
    {
        $cookie = $client->getCookieJar()->get(RefreshTokenCookieFactory::COOKIE_NAME, '/api/token');

        return $cookie?->getValue();
    }

    /**
     * Overwrites the refresh cookie in the client's jar with an arbitrary
     * raw value (used to simulate presenting an old/expired/tampered
     * token). CookieJar::set() keys entries by [domain][path][name], so
     * this must reuse whatever domain the *real* server-set cookie landed
     * under — otherwise both entries coexist and the jar's own
     * "most specific wins" tie-break keeps sending the real one instead of
     * the value this test is trying to inject.
     */
    private function setRefreshCookieValue(KernelBrowser $client, string $rawValue): void
    {
        $existing = $client->getCookieJar()->get(RefreshTokenCookieFactory::COOKIE_NAME, '/api/token');

        $client->getCookieJar()->set(new BrowserKitCookie(
            RefreshTokenCookieFactory::COOKIE_NAME,
            $rawValue,
            null,
            '/api/token',
            $existing?->getDomain() ?? '',
        ));
    }
}
