<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\TeamMemberNonParticipationPeriod;
use App\Entity\TeamMemberRole;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class TeamMemberNonParticipationPeriodTest extends TestCase
{
    private function newTeamMember(): TeamMember
    {
        return new TeamMember(
            new Team('Cardiology', 'cardiology'),
            new User('a@example.com', 'A', 'User', 'hash'),
            TeamMemberRole::MEMBER,
            new \DateTimeImmutable('2026-01-01'),
        );
    }

    public function testConstructValid(): void
    {
        $period = new TeamMemberNonParticipationPeriod(
            $this->newTeamMember(),
            new \DateTimeImmutable('2026-11-01'),
            new \DateTimeImmutable('2026-11-30'),
        );

        self::assertEquals(new \DateTimeImmutable('2026-11-01'), $period->getStartsAt());
    }

    public function testStartsAtEqualsEndsAtIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TeamMemberNonParticipationPeriod(
            $this->newTeamMember(),
            new \DateTimeImmutable('2026-11-01'),
            new \DateTimeImmutable('2026-11-01'),
        );
    }

    public function testStartsAtAfterEndsAtIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TeamMemberNonParticipationPeriod(
            $this->newTeamMember(),
            new \DateTimeImmutable('2026-11-30'),
            new \DateTimeImmutable('2026-11-01'),
        );
    }

    public function testRescheduleValid(): void
    {
        $period = new TeamMemberNonParticipationPeriod(
            $this->newTeamMember(),
            new \DateTimeImmutable('2026-11-01'),
            new \DateTimeImmutable('2026-11-30'),
        );

        $period->reschedule(new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-15'));

        self::assertEquals(new \DateTimeImmutable('2026-12-01'), $period->getStartsAt());
        self::assertEquals(new \DateTimeImmutable('2026-12-15'), $period->getEndsAt());
    }

    public function testOverlapsOrTouchesDetectsTouchingBoundary(): void
    {
        $member = $this->newTeamMember();
        $a = new TeamMemberNonParticipationPeriod($member, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-15'));
        $b = new TeamMemberNonParticipationPeriod($member, new \DateTimeImmutable('2026-11-15'), new \DateTimeImmutable('2026-11-20'));

        self::assertTrue($a->overlapsOrTouches($b));
    }

    public function testOverlapsOrTouchesIsFalseForADisjointGap(): void
    {
        $member = $this->newTeamMember();
        $a = new TeamMemberNonParticipationPeriod($member, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-15'));
        $b = new TeamMemberNonParticipationPeriod($member, new \DateTimeImmutable('2026-11-16'), new \DateTimeImmutable('2026-11-20'));

        self::assertFalse($a->overlapsOrTouches($b));
    }
}
