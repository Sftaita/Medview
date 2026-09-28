<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AvailabilityResponseStatus;
use App\Entity\Planning;
use App\Entity\PlanningLineType;
use App\Entity\PlanningTeam;
use App\Entity\PlanningTeamMember;
use App\Entity\TeamMemberRole;
use App\Exception\PlanningTeamMembershipConflictException;
use App\Repository\AvailabilityCollectionRepository;
use App\Repository\AvailabilityCollectionResponseRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\TeamMemberParticipationPeriodRepository;
use App\Service\PlanningLineService;
use App\Service\PlanningService;
use App\Service\PlanningTeamMembershipService;
use App\Tests\PlanningDomainTestHelpers;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlanningTeamMembershipServiceTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;

    /** A real Planning (primary line + its initial availability collection), created the way the API does. */
    private function createPlanningViaService(EntityManagerInterface $em, string $startsAt, string $endsAt): Planning
    {
        return self::getContainer()->get(PlanningService::class)->create(
            'Gardes '.bin2hex(random_bytes(3)),
            $this->createUser($em),
            $this->date($startsAt),
            $this->date($endsAt),
            'Europe/Brussels',
            'Ligne principale',
            false,
        );
    }

    private function primaryTeamOf(Planning $planning): PlanningTeam
    {
        return self::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning)[0]->getPlanningTeam();
    }

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
     * scoped per team, never app-wide (docs/decisions.md D150, after D080
     * and the abandoned app-wide D072 rule). createTeam() with no explicit
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
     * Scenario 3 (docs/decisions.md D150, relaxing D080): within the SAME
     * Planning, a User may hold open memberships in two different
     * PlanningTeams at once — e.g. holder on the main line, reinforcement
     * on a secondary line. Two distinct stints, one per team.
     */
    public function testCanJoinAnotherTeamOfTheSamePlanningWhileMembershipIsOpen(): void
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
        $stintB = $service->addMember($teamB, $user, TeamMemberRole::OWNER, $this->date('2027-01-01'));

        self::assertNotSame($stintA, $stintB);
        self::assertTrue($stintA->isCurrentlyOpen());
        self::assertTrue($stintB->isCurrentlyOpen());
        self::assertSame([$stintA, $stintB], $repository->findOpenMembershipsForUserInPlanning($planning, $user));
        self::assertTrue($repository->hasOpenMembershipInPlanning($planning, $user));
    }

    /**
     * The remaining invariant (D150) is enforced by the database itself,
     * not only by the service: two open stints of one User in one team are
     * refused by the partial unique index on (planning_team_id, user_id),
     * while one open stint in each of two teams of one Planning is accepted.
     */
    public function testTheDatabaseRefusesTwoOpenStintsInOneTeamButAcceptsOnePerTeam(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = $this->createUser($em);
        $planning = $this->createStandalonePlanning($em);
        $teamA = $this->createTeam($em, 'Cardiology', $planning);
        $teamB = $this->createTeam($em, 'Radiology', $planning);

        $em->persist(new PlanningTeamMember($teamA, $user, TeamMemberRole::MEMBER, $this->date('2027-01-01')));
        $em->persist(new PlanningTeamMember($teamB, $user, TeamMemberRole::MEMBER, $this->date('2027-01-01')));
        $em->flush();

        $em->persist(new PlanningTeamMember($teamA, $user, TeamMemberRole::ADMIN, $this->date('2027-02-01')));
        $this->expectException(UniqueConstraintViolationException::class);
        $em->flush();
    }

    /**
     * Availability is collected per person and per Planning
     * (docs/availability-collection.md §7): leaving one line while still
     * belonging to another line of the same Planning must never withdraw
     * the person from the Planning's open collections.
     */
    public function testLeavingOneLineWhileStillInAnotherNeverWithdrawsFromTheCollection(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PlanningTeamMembershipService::class);
        $collections = self::getContainer()->get(AvailabilityCollectionRepository::class);
        $responses = self::getContainer()->get(AvailabilityCollectionResponseRepository::class);

        $planning = $this->createPlanningViaService($em, '2027-03-01', '2027-06-01');
        $teamA = $this->primaryTeamOf($planning);
        $teamB = self::getContainer()->get(PlanningLineService::class)->addLine($planning, 'Renfort', PlanningLineType::SECONDARY)->getPlanningTeam();
        $collection = $collections->findByPlanning($planning)[0];
        $user = $this->createUser($em);

        $stintA = $service->addMember($teamA, $user, TeamMemberRole::MEMBER, $this->date('2027-01-01'));
        $service->addMember($teamB, $user, TeamMemberRole::MEMBER, $this->date('2027-01-01'));

        // Leaves the main line before the window starts, but stays on the secondary line.
        $service->endMembership($stintA, $this->date('2027-02-01'));

        self::assertSame(AvailabilityResponseStatus::PENDING, $responses->findOneForUser($collection, $user)?->getStatus());
    }

    /**
     * Leaving the last line of the Planning withdraws the person — at the
     * latest end among their memberships, so an earlier-closed stint on
     * another line never cuts short a participation that lasts longer.
     */
    public function testLeavingTheLastLineWithdrawsAtTheLatestMembershipEnd(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PlanningTeamMembershipService::class);
        $collections = self::getContainer()->get(AvailabilityCollectionRepository::class);
        $responses = self::getContainer()->get(AvailabilityCollectionResponseRepository::class);

        $planning = $this->createPlanningViaService($em, '2027-03-01', '2027-06-01');
        $teamA = $this->primaryTeamOf($planning);
        $teamB = self::getContainer()->get(PlanningLineService::class)->addLine($planning, 'Renfort', PlanningLineType::SECONDARY)->getPlanningTeam();
        $collection = $collections->findByPlanning($planning)[0];
        $user = $this->createUser($em);

        $stintA = $service->addMember($teamA, $user, TeamMemberRole::MEMBER, $this->date('2027-01-01'));
        $stintB = $service->addMember($teamB, $user, TeamMemberRole::MEMBER, $this->date('2027-01-01'));

        // The secondary stint is closed first, in the middle of the window: still expected for their part.
        $service->endMembership($stintB, $this->date('2027-04-15'));
        self::assertSame(AvailabilityResponseStatus::PENDING, $responses->findOneForUser($collection, $user)?->getStatus());

        // The main-line stint then ends before the window — but the secondary one lasted until 2027-04-15,
        // so the person still took part in the window and stays expected.
        $service->endMembership($stintA, $this->date('2027-02-01'));
        self::assertSame(AvailabilityResponseStatus::PENDING, $responses->findOneForUser($collection, $user)?->getStatus());
    }

    /**
     * Control case for the two tests above: someone whose only membership
     * ends before the window is withdrawn, exactly as before D150.
     */
    public function testLeavingTheOnlyLineBeforeTheWindowStillWithdraws(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(PlanningTeamMembershipService::class);
        $collections = self::getContainer()->get(AvailabilityCollectionRepository::class);
        $responses = self::getContainer()->get(AvailabilityCollectionResponseRepository::class);

        $planning = $this->createPlanningViaService($em, '2027-03-01', '2027-06-01');
        $collection = $collections->findByPlanning($planning)[0];
        $user = $this->createUser($em);

        $stint = $service->addMember($this->primaryTeamOf($planning), $user, TeamMemberRole::MEMBER, $this->date('2027-01-01'));
        $service->endMembership($stint, $this->date('2027-02-01'));

        self::assertSame(AvailabilityResponseStatus::WITHDRAWN, $responses->findOneForUser($collection, $user)?->getStatus());
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
