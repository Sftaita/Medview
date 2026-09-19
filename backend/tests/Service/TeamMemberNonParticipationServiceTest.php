<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\TeamMemberNonParticipationPeriod;
use App\Entity\TeamMemberRole;
use App\Exception\OverlappingNonParticipationPeriodException;
use App\Repository\TeamMemberNonParticipationPeriodRepository;
use App\Service\PlanningTeamMembershipService;
use App\Service\TeamMemberNonParticipationService;
use App\Tests\PlanningDomainTestHelpers;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TeamMemberNonParticipationServiceTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;

    private function dt(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value);
    }

    public function testCreateValidPeriod(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $membershipService = self::getContainer()->get(PlanningTeamMembershipService::class);
        $service = self::getContainer()->get(TeamMemberNonParticipationService::class);

        $team = $this->createTeam($em);
        $user = $this->createUser($em);
        $member = $membershipService->addMember($team, $user, TeamMemberRole::MEMBER, $this->dt('2026-01-01'));

        $period = $service->create($member, $this->dt('2026-11-01'), $this->dt('2026-11-30'));

        self::assertEquals($this->dt('2026-11-01'), $period->getStartsAt());
    }

    public function testOverlapIsRejected(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $membershipService = self::getContainer()->get(PlanningTeamMembershipService::class);
        $service = self::getContainer()->get(TeamMemberNonParticipationService::class);

        $team = $this->createTeam($em);
        $user = $this->createUser($em);
        $member = $membershipService->addMember($team, $user, TeamMemberRole::MEMBER, $this->dt('2026-01-01'));

        $service->create($member, $this->dt('2026-11-01'), $this->dt('2026-11-15'));

        $this->expectException(OverlappingNonParticipationPeriodException::class);
        $service->create($member, $this->dt('2026-11-10'), $this->dt('2026-11-20'));
    }

    public function testDeleteRemovesThePeriod(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $membershipService = self::getContainer()->get(PlanningTeamMembershipService::class);
        $service = self::getContainer()->get(TeamMemberNonParticipationService::class);
        $repository = self::getContainer()->get(TeamMemberNonParticipationPeriodRepository::class);

        $team = $this->createTeam($em);
        $user = $this->createUser($em);
        $member = $membershipService->addMember($team, $user, TeamMemberRole::MEMBER, $this->dt('2026-01-01'));

        $period = $service->create($member, $this->dt('2026-11-01'), $this->dt('2026-11-30'));
        $service->delete($period);

        self::assertCount(0, $repository->findByTeamMember($member));
    }

    /**
     * A non-participation window is scoped to one PlanningTeamMember (one
     * PlanningTeam of one Planning), never to the User globally —
     * docs/availability.md "Indisponibilité vs non-participation". New
     * scenario 6: since membership is now Planning-scoped
     * (docs/decisions.md D079/D080), the same User can simultaneously hold
     * a membership in Planning A's team and Planning B's team — a
     * non-participation window recorded against their Planning A
     * membership must not affect their separate Planning B membership.
     */
    public function testNonParticipationInOnePlanningDoesNotAffectAnother(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $membershipService = self::getContainer()->get(PlanningTeamMembershipService::class);
        $service = self::getContainer()->get(TeamMemberNonParticipationService::class);
        $repository = self::getContainer()->get(TeamMemberNonParticipationPeriodRepository::class);

        $user = $this->createUser($em);
        $teamA = $this->createTeam($em, 'Team A'); // its own fresh Planning A
        $teamB = $this->createTeam($em, 'Team B'); // its own fresh Planning B
        self::assertNotSame($teamA->getPlanning(), $teamB->getPlanning());

        $memberA = $membershipService->addMember($teamA, $user, TeamMemberRole::MEMBER, $this->dt('2026-01-01'));
        $memberB = $membershipService->addMember($teamB, $user, TeamMemberRole::MEMBER, $this->dt('2026-01-01'));

        $service->create($memberA, $this->dt('2026-11-01'), $this->dt('2026-11-30'));

        self::assertCount(1, $repository->findByTeamMember($memberA));
        self::assertCount(0, $repository->findByTeamMember($memberB), 'Planning B\'s membership must be unaffected by Planning A\'s non-participation window.');
    }

    public function testDatabaseRejectsOverlappingPeriodsEvenBypassingTheService(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $membershipService = self::getContainer()->get(PlanningTeamMembershipService::class);

        $team = $this->createTeam($em);
        $user = $this->createUser($em);
        $member = $membershipService->addMember($team, $user, TeamMemberRole::MEMBER, $this->dt('2026-01-01'));

        $first = new TeamMemberNonParticipationPeriod($member, $this->dt('2026-11-01'), $this->dt('2026-11-15'));
        $em->persist($first);
        $em->flush();

        $overlapping = new TeamMemberNonParticipationPeriod($member, $this->dt('2026-11-10'), $this->dt('2026-11-20'));
        $em->persist($overlapping);

        $this->expectException(DriverException::class);
        $em->flush();
    }
}
