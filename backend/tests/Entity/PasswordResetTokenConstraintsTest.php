<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Tests\AuthenticationTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The database as a last line of defence for password_reset_tokens,
 * independent of any PHP code: every statement below bypasses the ORM and
 * PasswordResetService entirely (same approach as TeamInvitationConstraintsTest).
 */
final class PasswordResetTokenConstraintsTest extends WebTestCase
{
    use AuthenticationTestHelpers;

    private Connection $db;
    private int $userId;

    protected function setUp(): void
    {
        $client = static::createClient();
        $this->registerUser($client, 'reset.constraints@example.com', 'correct-horse-battery');
        $this->db = static::getContainer()->get(Connection::class);
        $this->userId = (int) $this->db->fetchOne("SELECT id FROM users WHERE email = 'reset.constraints@example.com'");
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
