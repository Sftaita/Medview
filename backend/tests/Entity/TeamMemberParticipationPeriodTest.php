<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ParticipationFactorChangeReason;
use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\TeamMemberParticipationPeriod;
use App\Entity\TeamMemberRole;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class TeamMemberParticipationPeriodTest extends TestCase
{
    private function newTeamMember(): TeamMember
    {
        $team = new Team('Cardiology', 'cardiology');
        $user = new User('a@example.com', 'A', 'User', 'hash');

        return new TeamMember($team, $user, TeamMemberRole::MEMBER, new \DateTimeImmutable('2027-01-01'));
    }

    public function testRejectsNonPositiveFactor(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TeamMemberParticipationPeriod(
            $this->newTeamMember(),
            new \DateTimeImmutable('2027-01-01'),
            0.0,
            ParticipationFactorChangeReason::INITIAL,
        );
    }

    public function testAllowsFactorAboveOne(): void
    {
        $period = new TeamMemberParticipationPeriod(
            $this->newTeamMember(),
            new \DateTimeImmutable('2027-01-01'),
            1.5,
            ParticipationFactorChangeReason::INITIAL,
        );

        self::assertSame(1.5, $period->toFloat());
    }

    public function testCoversIsHalfOpenOnValidTo(): void
    {
        $period = new TeamMemberParticipationPeriod(
            $this->newTeamMember(),
            new \DateTimeImmutable('2027-01-01'),
            1.0,
            ParticipationFactorChangeReason::INITIAL,
        );
        $period->close(new \DateTimeImmutable('2027-07-01'));

        self::assertFalse($period->covers(new \DateTimeImmutable('2026-12-31')));
        self::assertTrue($period->covers(new \DateTimeImmutable('2027-01-01')));
        self::assertTrue($period->covers(new \DateTimeImmutable('2027-06-30')));
        self::assertFalse($period->covers(new \DateTimeImmutable('2027-07-01')), 'validTo is exclusive');
    }

    public function testOpenPeriodCoversEverythingFromValidFromOnward(): void
    {
        $period = new TeamMemberParticipationPeriod(
            $this->newTeamMember(),
            new \DateTimeImmutable('2027-07-01'),
            0.5,
            ParticipationFactorChangeReason::CONTRACTUAL_CHANGE,
        );

        self::assertTrue($period->covers(new \DateTimeImmutable('2099-01-01')));
        self::assertTrue($period->isOpen());
    }

    public function testCannotCloseTwice(): void
    {
        $period = new TeamMemberParticipationPeriod(
            $this->newTeamMember(),
            new \DateTimeImmutable('2027-01-01'),
            1.0,
            ParticipationFactorChangeReason::INITIAL,
        );
        $period->close(new \DateTimeImmutable('2027-07-01'));

        $this->expectException(\LogicException::class);
        $period->close(new \DateTimeImmutable('2027-08-01'));
    }

    public function testCannotCloseBeforeOrOnValidFrom(): void
    {
        $period = new TeamMemberParticipationPeriod(
            $this->newTeamMember(),
            new \DateTimeImmutable('2027-01-01'),
            1.0,
            ParticipationFactorChangeReason::INITIAL,
        );

        $this->expectException(\InvalidArgumentException::class);
        $period->close(new \DateTimeImmutable('2027-01-01'));
    }
}
