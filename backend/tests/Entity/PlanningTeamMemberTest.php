<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Planning;
use App\Entity\PlanningTeam;
use App\Entity\PlanningTeamMember;
use App\Entity\TeamMemberRole;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class PlanningTeamMemberTest extends TestCase
{
    private function newPlanningTeam(): PlanningTeam
    {
        $planning = new Planning(
            'Test Planning',
            new User('creator@example.com', 'Creator', 'User', 'hash'),
            new \DateTimeImmutable('2027-01-01'),
            new \DateTimeImmutable('2028-01-01'),
            'Europe/Brussels',
        );

        return new PlanningTeam($planning, 'Cardiology');
    }

    private function newTeamMember(\DateTimeImmutable $start): PlanningTeamMember
    {
        return new PlanningTeamMember(
            $this->newPlanningTeam(),
            new User('a@example.com', 'A', 'User', 'hash'),
            TeamMemberRole::MEMBER,
            $start,
        );
    }

    public function testIsActiveAtRespectsMembershipStart(): void
    {
        $member = $this->newTeamMember(new \DateTimeImmutable('2027-03-01'));

        self::assertFalse($member->isActiveAt(new \DateTimeImmutable('2027-02-28')));
        self::assertTrue($member->isActiveAt(new \DateTimeImmutable('2027-03-01')));
    }

    public function testIsActiveAtIsExclusiveOfMembershipEnd(): void
    {
        $member = $this->newTeamMember(new \DateTimeImmutable('2027-01-01'));
        $member->close(new \DateTimeImmutable('2027-09-30'));

        self::assertTrue($member->isActiveAt(new \DateTimeImmutable('2027-09-29')));
        self::assertFalse($member->isActiveAt(new \DateTimeImmutable('2027-09-30')));
        self::assertFalse($member->isCurrentlyOpen());
    }

    public function testCloseCannotPrecedeStart(): void
    {
        $member = $this->newTeamMember(new \DateTimeImmutable('2027-03-01'));

        $this->expectException(\InvalidArgumentException::class);
        $member->close(new \DateTimeImmutable('2027-02-01'));
    }

    public function testCannotCloseTwice(): void
    {
        $member = $this->newTeamMember(new \DateTimeImmutable('2027-01-01'));
        $member->close(new \DateTimeImmutable('2027-06-01'));

        $this->expectException(\LogicException::class);
        $member->close(new \DateTimeImmutable('2027-07-01'));
    }

    public function testPlanningIsDenormalizedFromPlanningTeam(): void
    {
        $team = $this->newPlanningTeam();
        $member = new PlanningTeamMember(
            $team,
            new User('b@example.com', 'B', 'User', 'hash'),
            TeamMemberRole::MEMBER,
            new \DateTimeImmutable('2027-01-01'),
        );

        self::assertSame($team->getPlanning(), $member->getPlanning());
    }
}
