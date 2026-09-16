<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\TeamMemberNonParticipationPeriod;
use App\Entity\TeamMemberRole;
use App\Exception\OverlappingNonParticipationPeriodException;
use App\Repository\TeamMemberNonParticipationPeriodRepository;
use App\Service\TeamMemberNonParticipationService;
use App\Service\TeamMembershipService;
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
        $membershipService = self::getContainer()->get(TeamMembershipService::class);
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
        $membershipService = self::getContainer()->get(TeamMembershipService::class);
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
        $membershipService = self::getContainer()->get(TeamMembershipService::class);
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
     * A non-participation window is scoped to one TeamMember (one Team),
     * never to the User globally — docs/availability.md "Indisponibilité
     * vs non-participation". The same User's non-participation in Team A
     * must not affect Team B.
     */
    public function testNonParticipationInOneTeamDoesNotAffectAnother(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $membershipService = self::getContainer()->get(TeamMembershipService::class);
        $service = self::getContainer()->get(TeamMemberNonParticipationService::class);
        $repository = self::getContainer()->get(TeamMemberNonParticipationPeriodRepository::class);

        $user = $this->createUser($em);
        $teamA = $this->createTeam($em, 'Team A', 'team-a');
        $teamB = $this->createTeam($em, 'Team B', 'team-b');
        $memberA = $membershipService->addMember($teamA, $user, TeamMemberRole::MEMBER, $this->dt('2026-01-01'));
        $memberB = $membershipService->addMember($teamB, $user, TeamMemberRole::MEMBER, $this->dt('2026-01-01'));

        $service->create($memberA, $this->dt('2026-11-01'), $this->dt('2026-11-30'));

        self::assertCount(1, $repository->findByTeamMember($memberA));
        self::assertCount(0, $repository->findByTeamMember($memberB), 'Team B must be unaffected by Team A\'s non-participation window.');
    }

    public function testDatabaseRejectsOverlappingPeriodsEvenBypassingTheService(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $membershipService = self::getContainer()->get(TeamMembershipService::class);

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
