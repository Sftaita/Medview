<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Tests\InvitationTestHelpers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The database as a last line of defence for team_invitations,
 * independent of any PHP code: every statement below bypasses the ORM and
 * the services.
 */
final class TeamInvitationConstraintsTest extends WebTestCase
{
    use InvitationTestHelpers;

    private Connection $db;
    private int $teamId;
    private int $userId;

    protected function setUp(): void
    {
        $client = static::createClient();
        $token = $this->userToken($client, 'constraints.creator@example.com');
        $this->createPlanningWithTeam($client, $token);
        $this->db = static::getContainer()->get(Connection::class);
        $this->teamId = (int) $this->db->fetchOne('SELECT id FROM planning_teams LIMIT 1');
        $this->userId = (int) $this->db->fetchOne("SELECT id FROM users WHERE email = 'constraints.creator@example.com'");
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insert(array $overrides = []): void
    {
        $row = array_merge([
            'stable_id' => '0199a8b0-0000-7000-8000-'.str_pad((string) random_int(1, 999999999), 12, '0', \STR_PAD_LEFT),
            'planning_team_id' => $this->teamId,
            'email' => 'marie@example.com',
            'proposed_first_name' => 'Marie',
            'proposed_last_name' => 'Dupont',
            'invited_by_id' => $this->userId,
            'role' => 'MEMBER',
            'token_hash' => bin2hex(random_bytes(32)),
            'status' => 'PENDING',
            'expires_at' => (new \DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s'),
            'accepted_at' => null,
            'accepted_by_id' => null,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], $overrides);

        $this->db->insert('team_invitations', $row);
    }

    public function testAValidRowIsAccepted(): void
    {
        $this->insert();

        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM team_invitations'));
    }

    public function testAtMostOnePendingInvitationPerTeamAndEmail(): void
    {
        $this->insert();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->insert();
    }

    public function testNonPendingInvitationsDoNotBlockANewOne(): void
    {
        foreach (['ACCEPTED', 'REVOKED', 'EXPIRED'] as $status) {
            $overrides = ['status' => $status];
            if ('ACCEPTED' === $status) {
                $overrides += ['accepted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'accepted_by_id' => $this->userId];
            }
            $this->insert($overrides);
        }
        $this->insert();

        self::assertSame(4, (int) $this->db->fetchOne('SELECT COUNT(*) FROM team_invitations'));
    }

    public function testTheSameEmailMayBeInvitedToDifferentTeams(): void
    {
        $this->insert();
        $this->db->executeStatement("INSERT INTO planning_teams (stable_id, planning_id, name, active, created_at, updated_at) SELECT gen_random_uuid(), planning_id, 'Autre', true, NOW(), NOW() FROM planning_teams LIMIT 1");
        $otherTeam = (int) $this->db->fetchOne("SELECT id FROM planning_teams WHERE name = 'Autre'");

        $this->insert(['planning_team_id' => $otherTeam]);

        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM team_invitations'));
    }

    public function testTokenHashIsUnique(): void
    {
        $hash = bin2hex(random_bytes(32));
        $this->insert(['token_hash' => $hash]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->insert(['token_hash' => $hash, 'email' => 'other@example.com']);
    }

    public function testARawOrMalformedTokenCannotBeStoredInPlaceOfAHash(): void
    {
        $this->expectException(DriverException::class);
        $this->insert(['token_hash' => 'not-a-sha256-hash']);
    }

    public function testEmailMustBeStoredNormalized(): void
    {
        $this->expectException(DriverException::class);
        $this->insert(['email' => 'Marie@Example.com']);
    }

    public function testStatusMustBeKnown(): void
    {
        $this->expectException(DriverException::class);
        $this->insert(['status' => 'PENDINGISH']);
    }

    public function testRoleMustBeKnown(): void
    {
        $this->expectException(DriverException::class);
        $this->insert(['role' => 'GOD']);
    }

    public function testAcceptedStatusRequiresAcceptanceData(): void
    {
        $this->expectException(DriverException::class);
        $this->insert(['status' => 'ACCEPTED']);
    }

    public function testAcceptanceDataRequiresAcceptedStatus(): void
    {
        $this->expectException(DriverException::class);
        $this->insert(['accepted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'accepted_by_id' => $this->userId]);
    }

    public function testExpiryMustFollowCreation(): void
    {
        $this->expectException(DriverException::class);
        $this->insert(['expires_at' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s')]);
    }

    public function testAnInvitationMustReferenceARealTeamAndInviter(): void
    {
        $this->expectException(DriverException::class);
        $this->insert(['planning_team_id' => 999999]);
    }
}
