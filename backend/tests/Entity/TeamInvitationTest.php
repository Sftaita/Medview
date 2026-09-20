<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Planning;
use App\Entity\PlanningTeam;
use App\Entity\TeamInvitation;
use App\Entity\TeamInvitationStatus;
use App\Entity\TeamMemberRole;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class TeamInvitationTest extends TestCase
{
    private function invitation(string $expiresIn = '+7 days'): TeamInvitation
    {
        $creator = new User('creator@example.com', 'Cre', 'Ator', 'hash');
        $planning = new Planning('P', $creator, new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-05-01'), 'Europe/Brussels');
        $team = new PlanningTeam($planning, 'Seniors');

        return new TeamInvitation($team, '  Marie@Example.COM ', ' Marie ', ' Dupont ', $creator, TeamInvitation::hashToken('raw'), new \DateTimeImmutable($expiresIn));
    }

    public function testItStartsPendingWithNormalizedFieldsAndAStableId(): void
    {
        $invitation = $this->invitation();

        self::assertSame(TeamInvitationStatus::PENDING, $invitation->getStatus());
        self::assertSame('marie@example.com', $invitation->getEmail());
        self::assertSame('Marie', $invitation->getProposedFirstName());
        self::assertSame(TeamMemberRole::MEMBER, $invitation->getRole());
        self::assertSame('7', $invitation->getStableId()->toRfc4122()[14], 'UUID v7');
        self::assertNull($invitation->getAcceptedAt());
    }

    public function testOnlyTheHashOfTheTokenIsKept(): void
    {
        $invitation = $this->invitation();

        self::assertSame(hash('sha256', 'raw'), $invitation->getTokenHash());
        self::assertNotSame('raw', $invitation->getTokenHash());
        self::assertSame(64, \strlen($invitation->getTokenHash()));
    }

    public function testUsabilityDependsOnTheClockNotOnAStoredFlag(): void
    {
        $invitation = $this->invitation('+1 hour');

        self::assertTrue($invitation->isUsableAt(new \DateTimeImmutable('now')));
        self::assertFalse($invitation->isUsableAt(new \DateTimeImmutable('+2 hours')));
        self::assertSame(TeamInvitationStatus::EXPIRED, $invitation->effectiveStatusAt(new \DateTimeImmutable('+2 hours')));
        self::assertSame(TeamInvitationStatus::PENDING, $invitation->getStatus(), 'The persisted flip is lazy.');

        $invitation->markExpired();
        self::assertSame(TeamInvitationStatus::EXPIRED, $invitation->getStatus());
    }

    public function testAcceptIsOneWay(): void
    {
        $invitation = $this->invitation();
        $user = new User('marie@example.com', 'M', 'D', 'hash');

        $invitation->accept($user, new \DateTimeImmutable());

        self::assertSame(TeamInvitationStatus::ACCEPTED, $invitation->getStatus());
        self::assertSame($user, $invitation->getAcceptedBy());
        self::assertFalse($invitation->isUsableAt(new \DateTimeImmutable()));

        $this->expectException(\LogicException::class);
        $invitation->accept($user, new \DateTimeImmutable());
    }

    public function testAnAcceptedInvitationCannotBeRevoked(): void
    {
        $invitation = $this->invitation();
        $invitation->accept(new User('marie@example.com', 'M', 'D', 'hash'), new \DateTimeImmutable());

        $this->expectException(\LogicException::class);
        $invitation->revoke();
    }

    public function testARevokedInvitationIsNotUsableNorAcceptable(): void
    {
        $invitation = $this->invitation();
        $invitation->revoke();

        self::assertFalse($invitation->isUsableAt(new \DateTimeImmutable()));
        self::assertSame(TeamInvitationStatus::REVOKED, $invitation->effectiveStatusAt(new \DateTimeImmutable('+30 days')), 'Revoked never turns into EXPIRED.');

        $this->expectException(\LogicException::class);
        $invitation->accept(new User('marie@example.com', 'M', 'D', 'hash'), new \DateTimeImmutable());
    }
}
