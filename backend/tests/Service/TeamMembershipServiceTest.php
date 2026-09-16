<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\TeamMemberRole;
use App\Exception\TeamMembershipConflictException;
use App\Repository\TeamMemberParticipationPeriodRepository;
use App\Repository\TeamMemberRepository;
use App\Service\TeamMembershipService;
use App\Tests\PlanningDomainTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TeamMembershipServiceTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;

    public function testAddingAMemberOpensBothMembershipAndParticipationHistory(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(TeamMembershipService::class);

        $team = $this->createTeam($em);
        $user = $this->createUser($em);

        $member = $service->addMember($team, $user, TeamMemberRole::MEMBER, $this->date('2027-01-01'));

        self::assertTrue($member->isCurrentlyOpen());
        self::assertCount(1, $member->getParticipationPeriods());
        self::assertSame(1.0, $member->getParticipationPeriods()->first()->toFloat());
    }

    public function testUserCanBelongToSeveralTeams(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(TeamMembershipService::class);
        /** @var TeamMemberRepository $repository */
        $repository = self::getContainer()->get(TeamMemberRepository::class);

        $user = $this->createUser($em);
        $teamA = $this->createTeam($em, 'Cardiology');
        $teamB = $this->createTeam($em, 'Radiology');

        $service->addMember($teamA, $user, TeamMemberRole::MEMBER, $this->date('2027-01-01'));
        $service->addMember($teamB, $user, TeamMemberRole::OWNER, $this->date('2027-01-01'));

        self::assertCount(2, $repository->findByUser($user));
    }

    public function testCannotOpenASecondMembershipWhileOneIsAlreadyOpen(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(TeamMembershipService::class);

        $team = $this->createTeam($em);
        $user = $this->createUser($em);
        $service->addMember($team, $user, TeamMemberRole::MEMBER, $this->date('2027-01-01'));

        $this->expectException(TeamMembershipConflictException::class);
        $service->addMember($team, $user, TeamMemberRole::ADMIN, $this->date('2027-06-01'));
    }

    public function testLeavingThenRejoiningCreatesANewMembershipAndPreservesHistory(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(TeamMembershipService::class);
        /** @var TeamMemberRepository $memberRepository */
        $memberRepository = self::getContainer()->get(TeamMemberRepository::class);
        /** @var TeamMemberParticipationPeriodRepository $periodRepository */
        $periodRepository = self::getContainer()->get(TeamMemberParticipationPeriodRepository::class);

        $team = $this->createTeam($em);
        $user = $this->createUser($em);

        $firstStint = $service->addMember($team, $user, TeamMemberRole::MEMBER, $this->date('2027-01-01'));
        $service->endMembership($firstStint, $this->date('2027-06-01'));

        self::assertFalse($firstStint->isCurrentlyOpen());
        self::assertNull($periodRepository->findOpenPeriod($firstStint), 'ending a membership must also close its open participation period');

        $secondStint = $service->addMember($team, $user, TeamMemberRole::MEMBER, $this->date('2027-09-01'));

        self::assertNotSame($firstStint, $secondStint);
        self::assertCount(2, $memberRepository->findByUser($user), 'the first stint must remain in history, not be overwritten');
    }

    public function testCanRejoinAfterLeaving(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(TeamMembershipService::class);

        $team = $this->createTeam($em);
        $user = $this->createUser($em);

        $firstStint = $service->addMember($team, $user, TeamMemberRole::MEMBER, $this->date('2027-01-01'));
        $service->endMembership($firstStint, $this->date('2027-06-01'));

        // No conflict expected: the previous membership is closed.
        $secondStint = $service->addMember($team, $user, TeamMemberRole::MEMBER, $this->date('2027-09-01'));
        self::assertTrue($secondStint->isCurrentlyOpen());
    }

    public function testRolesAreDistinctFromGlobalUserRoles(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(TeamMembershipService::class);

        $team = $this->createTeam($em);
        $user = $this->createUser($em);
        $service->addMember($team, $user, TeamMemberRole::OWNER, $this->date('2027-01-01'));

        self::assertSame(['ROLE_USER'], $user->getRoles(), 'a team role must never leak into User::getRoles()');
    }
}
