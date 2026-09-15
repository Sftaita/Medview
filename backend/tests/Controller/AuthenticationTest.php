<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthenticationTest extends WebTestCase
{
    private function registerUser(KernelBrowser $client, string $email, string $password): void
    {
        $client->request(
            'POST',
            '/api/register',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'email' => $email,
                'plainPassword' => $password,
                'firstName' => 'Test',
                'lastName' => 'User',
            ]),
        );

        self::assertResponseStatusCodeSame(201);
    }

    public function testLoginWithValidCredentialsReturnsToken(): void
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

        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'me.valid@example.com', 'password' => 'correct-horse-battery']),
        );
        $token = json_decode((string) $client->getResponse()->getContent(), true)['token'];

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
}
