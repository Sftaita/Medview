<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegistrationControllerTest extends WebTestCase
{
    public function testRegisterWithValidDataReturns201(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/register',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'email' => 'new.user@example.com',
                'plainPassword' => 'correct-horse-battery',
                'firstName' => 'New',
                'lastName' => 'User',
            ]),
        );

        self::assertResponseStatusCodeSame(201);

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('new.user@example.com', $data['email']);
        self::assertSame('New', $data['firstName']);
        self::assertTrue($data['active']);
        self::assertArrayNotHasKey('passwordHash', $data);
        self::assertArrayNotHasKey('password', $data);
    }

    public function testRegisterWithAlreadyUsedEmailReturns409(): void
    {
        $client = static::createClient();
        $payload = json_encode([
            'email' => 'duplicate@example.com',
            'plainPassword' => 'correct-horse-battery',
            'firstName' => 'First',
            'lastName' => 'User',
        ]);

        $client->request('POST', '/api/register', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);
        self::assertResponseStatusCodeSame(201);

        $client->request('POST', '/api/register', server: ['CONTENT_TYPE' => 'application/json'], content: $payload);
        self::assertResponseStatusCodeSame(409);

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('email_already_used', $data['error']);
    }

    public function testRegisterWithWeakPasswordReturns422(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/register',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'email' => 'weak.password@example.com',
                'plainPassword' => '123',
                'firstName' => 'Weak',
                'lastName' => 'Password',
            ]),
        );

        self::assertResponseStatusCodeSame(422);

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('plainPassword', $data['violations']);
    }
}
