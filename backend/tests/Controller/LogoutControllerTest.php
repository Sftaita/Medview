<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\RefreshTokenRepository;
use App\Security\RefreshTokenCookieFactory;
use App\Tests\AuthenticationTestHelpers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LogoutControllerTest extends WebTestCase
{
    use AuthenticationTestHelpers;

    public function testLogoutRevokesTheRefreshTokenAndClearsTheCookie(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'logout.valid@example.com', 'correct-horse-battery');
        $this->loginUser($client, 'logout.valid@example.com', 'correct-horse-battery');
        $rawToken = $this->getRefreshCookieValue($client);

        $client->request('POST', '/api/token/logout');

        self::assertResponseStatusCodeSame(200);

        $cookie = $client->getResponse()->headers->getCookies()[0] ?? null;
        self::assertNotNull($cookie);
        self::assertSame(RefreshTokenCookieFactory::COOKIE_NAME, $cookie->getName());
        self::assertLessThan(time(), $cookie->getExpiresTime(), 'The logout response must clear the cookie (expiry in the past).');

        $stored = static::getContainer()->get(RefreshTokenRepository::class)->findOneByTokenHash(hash('sha256', $rawToken));
        self::assertNotNull($stored);
        self::assertTrue($stored->isRevoked());
    }

    public function testRefreshAfterLogoutIsRejected(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'logout.then.refresh@example.com', 'correct-horse-battery');
        $this->loginUser($client, 'logout.then.refresh@example.com', 'correct-horse-battery');

        $client->request('POST', '/api/token/logout');
        self::assertResponseStatusCodeSame(200);

        $client->request('POST', '/api/token/refresh');
        self::assertResponseStatusCodeSame(401);
    }

    public function testLogoutWithoutAnySessionIsIdempotent(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/token/logout');

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['success']);
    }
}
