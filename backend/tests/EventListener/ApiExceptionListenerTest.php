<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * ApiExceptionListener is the systemic safety net added after UAT found
 * two separate ways to leak a full HTML debug stack trace from /api/
 * endpoints (see docs/decisions.md). These tests don't re-check those two
 * specific bugs (covered where they were found) — they confirm the
 * general guarantee: nothing under /api/ ever renders HTML, for any
 * exception type, regardless of whether that specific case was ever
 * anticipated.
 */
final class ApiExceptionListenerTest extends WebTestCase
{
    public function testUnknownApiRouteReturnsCleanJsonNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/this-route-does-not-exist');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('application/json', $client->getResponse()->headers->get('Content-Type'));

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('message', $data);
        self::assertStringNotContainsString('<html', (string) $client->getResponse()->getContent());
    }

    public function testWrongHttpMethodReturnsCleanJsonMethodNotAllowed(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/register');

        self::assertResponseStatusCodeSame(405);
        self::assertSame('application/json', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringNotContainsString('<html', (string) $client->getResponse()->getContent());
    }
}
