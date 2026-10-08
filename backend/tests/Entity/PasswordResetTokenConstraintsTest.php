<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The database as a last line of defence for password_reset_tokens,
 * independent of any PHP code: every statement below bypasses the ORM and
 * PasswordResetService entirely (same approach as TeamInvitationConstraintsTest).
 */
final class PasswordResetTokenConstraintsTest extends WebTestCase
{
    private Connection $db;
    private int $userId;

    protected function setUp(): void
    {
        static::createClient();
        // Persisted directly, not registered through the API: a registration
        // now writes an append-only audit entry referencing the user (D176),
        // which would forbid the DELETE of the cascade test below. Users are
        // never deleted by the application itself.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User('reset.constraints@example.com', 'Test', 'User', 'irrelevant-hash');
        $em->persist($user);
        $em->flush();
        $this->db = static::getContainer()->get(Connection::class);
        $this->userId = (int) $user->getId();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insert(array $overrides = []): void
    {
        $row = array_merge([
            'user_id' => $this->userId,
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'expires_at' => (new \DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s'),
            'consumed_at' => null,
            'revoked_at' => null,
            'requested_by_ip' => '203.0.113.7',
        ], $overrides);

        $this->db->insert('password_reset_tokens', $row);
    }

    public function testAValidRowIsAccepted(): void
    {
        $this->insert();

        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM password_reset_tokens'));
    }

    public function testTokenHashIsUnique(): void
    {
        $hash = hash('sha256', 'shared');
        $this->insert(['token_hash' => $hash]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->insert(['token_hash' => $hash]);
    }

    public function testARawOrMalformedTokenCannotBeStoredInPlaceOfAHash(): void
    {
        $this->expectException(DriverException::class);
        $this->insert(['token_hash' => 'not-a-sha256-hash']);
    }

    public function testExpiryMustFollowCreation(): void
    {
        $this->expectException(DriverException::class);
        $this->insert(['expires_at' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s')]);
    }

    public function testARowCannotBeBothConsumedAndRevoked(): void
    {
        $this->expectException(DriverException::class);
        $this->insert([
            'consumed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'revoked_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function testATokenMustReferenceARealUser(): void
    {
        $this->expectException(DriverException::class);
        $this->insert(['user_id' => 999999]);
    }

    public function testDeletingTheUserDeletesTheirResetTokens(): void
    {
        $this->insert();
        $this->db->executeStatement('DELETE FROM users WHERE id = ?', [$this->userId]);

        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM password_reset_tokens'));
    }
}
