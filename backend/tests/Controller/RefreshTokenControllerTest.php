<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use App\Repository\UserRepository;
use App\Tests\AuthenticationTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class RefreshTokenControllerTest extends WebTestCase
{
    use AuthenticationTestHelpers;

    public function testRefreshWithValidCookieReturnsNewAccessToken(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'refresh.valid@example.com', 'correct-horse-battery');
        $this->loginUser($client, 'refresh.valid@example.com', 'correct-horse-battery');

        $client->request('POST', '/api/token/refresh');

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('token', $data);
        self::assertNotEmpty($data['token']);
    }

    public function testRefreshRotatesTheRefreshToken(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'refresh.rotate@example.com', 'correct-horse-battery');
        $this->loginUser($client, 'refresh.rotate@example.com', 'correct-horse-battery');
        $oldRawToken = $this->getRefreshCookieValue($client);

        $client->request('POST', '/api/token/refresh');
        self::assertResponseStatusCodeSame(200);
        $newRawToken = $this->getRefreshCookieValue($client);

        self::assertNotNull($oldRawToken);
        self::assertNotNull($newRawToken);
        self::assertNotSame($oldRawToken, $newRawToken, 'Rotation must issue a different raw refresh token.');

        $repository = static::getContainer()->get(RefreshTokenRepository::class);
        $oldEntity = $repository->findOneByTokenHash(hash('sha256', $oldRawToken));
        $newEntity = $repository->findOneByTokenHash(hash('sha256', $newRawToken));

        self::assertNotNull($oldEntity);
        self::assertNotNull($newEntity);
        self::assertTrue($oldEntity->isRevoked(), 'The rotated-out token must be marked revoked.');
        self::assertSame($newEntity->getId(), $oldEntity->getReplacedByTokenId());
        self::assertSame($oldEntity->getFamilyId(), $newEntity->getFamilyId(), 'Rotation keeps the same session family.');
        self::assertFalse($newEntity->isRevoked());
    }

    public function testReusingARotatedOutTokenIsRejected(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'refresh.reused@example.com', 'correct-horse-battery');
        $this->loginUser($client, 'refresh.reused@example.com', 'correct-horse-battery');
        $oldRawToken = $this->getRefreshCookieValue($client);

        // Rotate once: $oldRawToken is now consumed.
        $client->request('POST', '/api/token/refresh');
        self::assertResponseStatusCodeSame(200);

        // Present the already-consumed token again.
        $this->setRefreshCookieValue($client, $oldRawToken);
        $client->request('POST', '/api/token/refresh');

        self::assertResponseStatusCodeSame(401);
    }

    public function testReuseDetectionRevokesTheWholeFamily(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'refresh.family@example.com', 'correct-horse-battery');
        $this->loginUser($client, 'refresh.family@example.com', 'correct-horse-battery');
        $oldRawToken = $this->getRefreshCookieValue($client);

        // Rotate: old -> current.
        $client->request('POST', '/api/token/refresh');
        self::assertResponseStatusCodeSame(200);
        $currentRawToken = $this->getRefreshCookieValue($client);

        // Reuse the old, already-consumed token: triggers family revocation.
        $this->setRefreshCookieValue($client, $oldRawToken);
        $client->request('POST', '/api/token/refresh');
        self::assertResponseStatusCodeSame(401);

        // The token that WAS still valid a moment ago must now also be rejected.
        $this->setRefreshCookieValue($client, $currentRawToken);
        $client->request('POST', '/api/token/refresh');
        self::assertResponseStatusCodeSame(401, 'Reuse of an old token must revoke the entire family, including the current one.');
    }

    public function testExpiredRefreshTokenIsRejected(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $user = $this->persistUser($client, $container, 'refresh.expired@example.com');

        $rawToken = 'expired-raw-token';
        $this->persistRefreshToken($container, $user, $rawToken, expiresAt: new \DateTimeImmutable('-1 day'));

        $this->setRefreshCookieValue($client, $rawToken);
        $client->request('POST', '/api/token/refresh');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRevokedRefreshTokenIsRejected(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $user = $this->persistUser($client, $container, 'refresh.revoked@example.com');

        $rawToken = 'revoked-raw-token';
        $token = $this->persistRefreshToken($container, $user, $rawToken, expiresAt: new \DateTimeImmutable('+30 days'));
        $token->revoke();
        $container->get(EntityManagerInterface::class)->flush();

        $this->setRefreshCookieValue($client, $rawToken);
        $client->request('POST', '/api/token/refresh');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRefreshForDisabledUserIsRejected(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'refresh.disabled@example.com', 'correct-horse-battery');
        $this->loginUser($client, 'refresh.disabled@example.com', 'correct-horse-battery');
        $rawToken = $this->getRefreshCookieValue($client);

        $container = static::getContainer();
        $user = $container->get(UserRepository::class)->findOneByEmail('refresh.disabled@example.com');
        self::assertNotNull($user);
        $user->setActive(false);
        $container->get(EntityManagerInterface::class)->flush();

        $client->request('POST', '/api/token/refresh');

        self::assertResponseStatusCodeSame(401);

        $stored = $container->get(RefreshTokenRepository::class)->findOneByTokenHash(hash('sha256', $rawToken));
        self::assertNotNull($stored);
        self::assertTrue($stored->isRevoked(), 'A refresh attempt for a disabled account must revoke the token.');
    }

    public function testRefreshWithoutCookieReturns401(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/token/refresh');

        self::assertResponseStatusCodeSame(401);
    }

    private function persistUser(KernelBrowser $client, ContainerInterface $container, string $email): User
    {
        $this->registerUser($client, $email, 'correct-horse-battery');

        $user = $container->get(UserRepository::class)->findOneByEmail($email);
        self::assertNotNull($user);

        return $user;
    }

    private function persistRefreshToken(
        ContainerInterface $container,
        User $user,
        string $rawToken,
        \DateTimeImmutable $expiresAt,
    ): RefreshToken {
        $token = new RefreshToken(
            user: $user,
            tokenHash: hash('sha256', $rawToken),
            familyId: bin2hex(random_bytes(16)),
            expiresAt: $expiresAt,
            createdByIp: '127.0.0.1',
            userAgent: 'phpunit',
        );

        $entityManager = $container->get(EntityManagerInterface::class);
        $entityManager->persist($token);
        $entityManager->flush();

        return $token;
    }
}
