<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\TeamMemberRole;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class TeamMemberTest extends TestCase
{
    private function newTeamMember(\DateTimeImmutable $start): TeamMember
    {
        return new TeamMember(
            new Team('Cardiology', 'cardiology'),
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
}
