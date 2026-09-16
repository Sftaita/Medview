<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * allow_credentials: true (config/packages/nelmio_cors.yaml) was added
 * specifically so the browser attaches/accepts the HttpOnly refresh
 * cookie on cross-origin requests to /api/token/*. It's a security-
 * relevant config with no controller of its own, so it needs its own
 * direct coverage rather than being assumed correct because the refresh
 * flow happens to work in a same-origin test client.
 */
final class CorsTest extends WebTestCase
{
    public function testAllowedOriginGetsCredentialedCorsHeaders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health', server: ['HTTP_ORIGIN' => 'http://localhost:5183']);

        self::assertResponseStatusCodeSame(200);
        $headers = $client->getResponse()->headers;
        self::assertSame('http://localhost:5183', $headers->get('Access-Control-Allow-Origin'));
        self::assertSame('true', $headers->get('Access-Control-Allow-Credentials'));
    }

    public function testDisallowedOriginGetsNoCorsHeaders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health', server: ['HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseStatusCodeSame(200);
        self::assertNull($client->getResponse()->headers->get('Access-Control-Allow-Origin'));
        self::assertNull($client->getResponse()->headers->get('Access-Control-Allow-Credentials'));
    }

    public function testPreflightForTokenRefreshIsCredentialed(): void
    {
        $client = static::createClient();
        $client->request(
            'OPTIONS',
            '/api/token/refresh',
            server: [
                'HTTP_ORIGIN' => 'http://localhost:5183',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            ],
        );

        $headers = $client->getResponse()->headers;
        self::assertSame('http://localhost:5183', $headers->get('Access-Control-Allow-Origin'));
        self::assertSame('true', $headers->get('Access-Control-Allow-Credentials'));
    }
}
