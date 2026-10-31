<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\TeamMemberNonParticipationPeriod;
use App\Entity\TeamMemberRole;
use App\Entity\UserAvailabilityPeriod;
use App\Entity\UserAvailabilityType;
use App\Exception\OverlappingNonParticipationPeriodException;
use App\Exception\OverlappingUserAvailabilityPeriodException;
use App\Repository\SurgicalHubImportedLeaveRepository;
use App\Repository\TeamMemberNonParticipationPeriodRepository;
use App\Repository\UserAvailabilityPeriodRepository;
use App\Service\AvailabilityCollectionService;
use App\Service\PlanningTeamMembershipService;
use App\Service\TeamMemberNonParticipationService;
use App\Service\UserAvailabilityService;
use App\Tests\PlanningDomainTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * D219 — the exclusion constraint refusing a write the check let through (a
 * concurrent request inserted the overlapping period in between, simulated
 * here by a check that sees nothing) is the same business conflict: the
 * overlap exception (409), never the raw driver error (500), and nothing of
 * the write remains.
 */
final class OverlapRaceTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;

    public function testACalendarPeriodRacedByAnOverlappingOneIsAConflict(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $user = $this->createUser($em);
        $em->persist(new UserAvailabilityPeriod($user, UserAvailabilityType::UNAVAILABLE, new \DateTimeImmutable('2027-01-11T23:00:00Z'), new \DateTimeImmutable('2027-01-12T23:00:00Z')));
        $em->flush();

        $blind = $this->createStub(UserAvailabilityPeriodRepository::class);
        $blind->method('findOverlappingOrTouchingForUser')->willReturn([]);
        $service = new UserAvailabilityService($blind, $em, $container->get(AvailabilityCollectionService::class), $container->get(SurgicalHubImportedLeaveRepository::class));

        try {
            $service->create($user, UserAvailabilityType::UNAVAILABLE, new \DateTimeImmutable('2027-01-13T00:00:00+01:00'), new \DateTimeImmutable('2027-01-14T00:00:00+01:00'));
            self::fail('The overlapping period must be refused.');
        } catch (OverlappingUserAvailabilityPeriodException) {
        }

        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM user_availability_periods WHERE user_id = ?', [$user->getId()]));
    }

    public function testACalendarMoveRacedByAnOverlappingPeriodIsAConflict(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $user = $this->createUser($em);
        $em->persist(new UserAvailabilityPeriod($user, UserAvailabilityType::UNAVAILABLE, new \DateTimeImmutable('2027-03-27T23:00:00Z'), new \DateTimeImmutable('2027-03-28T22:00:00Z')));
        $moved = new UserAvailabilityPeriod($user, UserAvailabilityType::UNAVAILABLE, new \DateTimeImmutable('2027-04-10T22:00:00Z'), new \DateTimeImmutable('2027-04-11T22:00:00Z'));
        $em->persist($moved);
        $em->flush();
        $movedId = $moved->getId();

        $blind = $this->createStub(UserAvailabilityPeriodRepository::class);
        $blind->method('findOverlappingOrTouchingForUser')->willReturn([]);
        $service = new UserAvailabilityService($blind, $em, $container->get(AvailabilityCollectionService::class), $container->get(SurgicalHubImportedLeaveRepository::class));

        $this->expectException(OverlappingUserAvailabilityPeriodException::class);
        try {
            $service->reschedule($moved, UserAvailabilityType::UNAVAILABLE, new \DateTimeImmutable('2027-03-29T00:00:00+02:00'), new \DateTimeImmutable('2027-03-30T00:00:00+02:00'));
        } finally {
            self::assertSame('2027-04-10 22:00:00+00', $em->getConnection()->fetchOne('SELECT starts_at FROM user_availability_periods WHERE id = ?', [$movedId]));
        }
    }

    public function testANonParticipationRacedByAnOverlappingOneIsAConflict(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $member = $container->get(PlanningTeamMembershipService::class)->addMember($this->createTeam($em), $this->createUser($em), TeamMemberRole::MEMBER, $this->date('2026-01-01'));
        $em->persist(new TeamMemberNonParticipationPeriod($member, new \DateTimeImmutable('2027-10-30T22:00:00Z'), new \DateTimeImmutable('2027-10-31T23:00:00Z')));
        $em->flush();

        $blind = $this->createStub(TeamMemberNonParticipationPeriodRepository::class);
        $blind->method('findOverlappingOrTouchingForTeamMember')->willReturn([]);
        $service = new TeamMemberNonParticipationService($blind, $em);

        try {
            $service->create($member, new \DateTimeImmutable('2027-11-01T00:00:00+01:00'), new \DateTimeImmutable('2027-11-02T00:00:00+01:00'));
            self::fail('The overlapping period must be refused.');
        } catch (OverlappingNonParticipationPeriodException) {
        }

        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM team_member_non_participation_periods WHERE team_member_id = ?', [$member->getId()]));
    }
}
