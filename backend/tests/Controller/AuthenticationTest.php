<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\RefreshTokenRepository;
use App\Repository\UserRepository;
use App\Security\RefreshTokenCookieFactory;
use App\Tests\AuthenticationTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthenticationTest extends WebTestCase
{
    use AuthenticationTestHelpers;

    public function testLoginWithValidCredentialsReturnsTokenAndRefreshCookie(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'login.valid@example.com', 'correct-horse-battery');

        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'login.valid@example.com', 'password' => 'correct-horse-battery']),
        );

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('token', $data);
        self::assertNotEmpty($data['token']);
        self::assertArrayNotHasKey('refreshToken', $data, 'The refresh token must never appear in the JSON body.');

        $cookie = $client->getResponse()->headers->getCookies()[0] ?? null;
        self::assertNotNull($cookie, 'Login must set a refresh cookie.');
        self::assertSame(RefreshTokenCookieFactory::COOKIE_NAME, $cookie->getName());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', strtolower((string) $cookie->getSameSite()));
        self::assertSame('/api/token', $cookie->getPath());
    }

    public function testLoginIssuesAPersistedRefreshToken(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'refresh.created@example.com', 'correct-horse-battery');
        $this->loginUser($client, 'refresh.created@example.com', 'correct-horse-battery');

        $rawToken = $this->getRefreshCookieValue($client);
        self::assertNotNull($rawToken);

        $tokenHash = hash('sha256', $rawToken);
        $stored = static::getContainer()->get(RefreshTokenRepository::class)->findOneByTokenHash($tokenHash);

        self::assertNotNull($stored, 'The refresh token must be persisted, looked up by its hash.');
        self::assertSame('refresh.created@example.com', $stored->getUser()->getEmail());
        self::assertFalse($stored->isRevoked());
    }

    public function testLoginWithWrongPasswordReturns401(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'login.wrong@example.com', 'correct-horse-battery');

        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'login.wrong@example.com', 'password' => 'not-the-right-password']),
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testLoginWithDisabledUserReturns401(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'login.disabled@example.com', 'correct-horse-battery');

        $container = static::getContainer();
        $user = $container->get(UserRepository::class)->findOneByEmail('login.disabled@example.com');
        self::assertNotNull($user);
        $user->setActive(false);
        $container->get(EntityManagerInterface::class)->flush();

        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'login.disabled@example.com', 'password' => 'correct-horse-battery']),
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testMeWithValidTokenReturnsCurrentUser(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'me.valid@example.com', 'correct-horse-battery');
        $token = $this->loginUser($client, 'me.valid@example.com', 'correct-horse-battery');

        $client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('me.valid@example.com', $data['email']);
        self::assertArrayNotHasKey('passwordHash', $data);
    }

    public function testMeWithoutTokenReturns401(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/me');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Regression test for a UAT finding: json_login only intercepts
     * POST /api/login when Content-Type is application/json. Anything
     * else (missing header, form-encoded, ...) makes it decline, falling
     * through to SecurityController — which used to assume that could
     * "never happen" and threw an uncaught LogicException, leaking a full
     * debug stack trace on this public, unauthenticated endpoint from a
     * single malformed request (see docs/decisions.md). It must now
     * respond with a clean 400 instead.
     */
    public function testLoginWithNonJsonContentTypeReturns400WithoutLeakingAStackTrace(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            content: 'email=uat@example.com&password=whatever',
        );

        self::assertResponseStatusCodeSame(400);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('invalid_content_type', $data['error']);
        self::assertStringNotContainsString('Stack trace', (string) $client->getResponse()->getContent());
    }

    public function testLoginWithoutContentTypeHeaderReturns400(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/login', content: 'email=uat@example.com&password=whatever');

        self::assertResponseStatusCodeSame(400);
    }
}
