<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\TeamMemberRole;
use App\Exception\PlanningTeamMembershipConflictException;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\TeamMemberParticipationPeriodRepository;
use App\Service\PlanningTeamMembershipService;
use App\Tests\PlanningDomainTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlanningTeamMembershipServiceTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;

    public function testAddingAMemberOpensBothMembershipAndParticipationHistory(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PlanningTeamMembershipService::class);

        $team = $this->createTeam($em);
        $user = $this->createUser($em);

        $member = $service->addMember($team, $user, TeamMemberRole::MEMBER, $this->date('2027-01-01'));

        self::assertTrue($member->isCurrentlyOpen());
        self::assertCount(1, $member->getParticipationPeriods());
        self::assertSame(1.0, $member->getParticipationPeriods()->first()->toFloat());
    }

    /**
     * New scenario 2: a User may simultaneously hold an open membership in
     * PlanningTeams of two *different* Plannings — membership uniqueness is
     * scoped per-Planning, not app-wide (docs/decisions.md D080, replacing
     * the abandoned app-wide D072 rule). createTeam() with no explicit
     * $planning attaches each team to its own fresh Planning by default
     * (see PlanningDomainTestHelpers), so teamA/teamB below already belong
     * to two different Plannings.
     */
    public function testCanJoinATeamOfADifferentPlanningWhileMembershipIsOpenElsewhere(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PlanningTeamMembershipService::class);

        $user = $this->createUser($em);
        $teamA = $this->createTeam($em, 'Cardiology');
        $teamB = $this->createTeam($em, 'Radiology');
        self::assertNotSame($teamA->getPlanning(), $teamB->getPlanning());

        $stintA = $service->addMember($teamA, $user, TeamMemberRole::MEMBER, $this->date('2027-01-01'));
        $stintB = $service->addMember($teamB, $user, TeamMemberRole::OWNER, $this->date('2027-01-01'));

        self::assertTrue($stintA->isCurrentlyOpen());
        self::assertTrue($stintB->isCurrentlyOpen());
    }

    /**
     * New scenario 3: within the SAME Planning, a User may never hold two
     * open memberships at once, even across two different PlanningTeams of
     * that Planning (docs/decisions.md D080).
     */
    public function testCannotJoinAnotherTeamOfTheSamePlanningWhileMembershipIsOpen(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PlanningTeamMembershipService::class);

        $user = $this->createUser($em);
        $planning = $this->createStandalonePlanning($em);
        $teamA = $this->createTeam($em, 'Cardiology', $planning);
        $teamB = $this->createTeam($em, 'Radiology', $planning);

        $service->addMember($teamA, $user, TeamMemberRole::MEMBER, $this->date('2027-01-01'));

        $this->expectException(PlanningTeamMembershipConflictException::class);
        $service->addMember($teamB, $user, TeamMemberRole::OWNER, $this->date('2027-01-01'));
    }

    /**
     * New scenario 4: closing the membership in Team A of a Planning
     * immediately frees the User to join Team B of that SAME Planning.
     */
    public function testCanJoinAnotherTeamOfTheSamePlanningAfterClosingThePreviousMembership(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PlanningTeamMembershipService::class);
        /** @var PlanningTeamMemberRepository $repository */
        $repository = self::getContainer()->get(PlanningTeamMemberRepository::class);

        $user = $this->createUser($em);
        $planning = $this->createStandalonePlanning($em);
        $teamA = $this->createTeam($em, 'Cardiology', $planning);
        $teamB = $this->createTeam($em, 'Radiology', $planning);

        $stintA = $service->addMember($teamA, $user, TeamMemberRole::MEMBER, $this->date('2027-01-01'));
        $service->endMembership($stintA, $this->date('2027-06-01'));

        $stintB = $service->addMember($teamB, $user, TeamMemberRole::OWNER, $this->date('2027-06-01'));

        self::assertTrue($stintB->isCurrentlyOpen());
        $history = $repository->findByUser($user);
        self::assertCount(2, $history, 'the closed Team A stint must remain in history, not be overwritten by the Team B one');
        self::assertFalse($stintA->isCurrentlyOpen());
    }

    public function testCannotOpenASecondMembershipWhileOneIsAlreadyOpen(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PlanningTeamMembershipService::class);

        $team = $this->createTeam($em);
        $user = $this->createUser($em);
        $service->addMember($team, $user, TeamMemberRole::MEMBER, $this->date('2027-01-01'));

        $this->expectException(PlanningTeamMembershipConflictException::class);
        $service->addMember($team, $user, TeamMemberRole::ADMIN, $this->date('2027-06-01'));
    }

    public function testLeavingThenRejoiningCreatesANewMembershipAndPreservesHistory(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PlanningTeamMembershipService::class);
        /** @var PlanningTeamMemberRepository $memberRepository */
        $memberRepository = self::getContainer()->get(PlanningTeamMemberRepository::class);
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
        $service = self::getContainer()->get(PlanningTeamMembershipService::class);

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
        $service = self::getContainer()->get(PlanningTeamMembershipService::class);

        $team = $this->createTeam($em);
        $user = $this->createUser($em);
        $service->addMember($team, $user, TeamMemberRole::OWNER, $this->date('2027-01-01'));

        self::assertSame(['ROLE_USER'], $user->getRoles(), 'a team role must never leak into User::getRoles()');
    }
}
