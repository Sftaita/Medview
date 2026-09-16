<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\TeamMemberRole;
use App\Entity\UserAvailabilityPeriod;
use App\Entity\UserAvailabilityType;
use App\Exception\OverlappingUserAvailabilityPeriodException;
use App\Repository\UserAvailabilityPeriodRepository;
use App\Service\TeamMembershipService;
use App\Service\UserAvailabilityService;
use App\Tests\PlanningDomainTestHelpers;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserAvailabilityServiceTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;

    private function dt(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value);
    }

    public function testCreateUnavailablePeriod(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(UserAvailabilityService::class);
        $user = $this->createUser($em);

        $period = $service->create($user, UserAvailabilityType::UNAVAILABLE, $this->dt('2026-11-10 08:00'), $this->dt('2026-11-11 08:00'));

        self::assertSame(UserAvailabilityType::UNAVAILABLE, $period->getType());
    }

    public function testOverlappingSameTypeIsRejected(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(UserAvailabilityService::class);
        $user = $this->createUser($em);

        $service->create($user, UserAvailabilityType::UNAVAILABLE, $this->dt('2026-11-10'), $this->dt('2026-11-15'));

        $this->expectException(OverlappingUserAvailabilityPeriodException::class);
        $service->create($user, UserAvailabilityType::UNAVAILABLE, $this->dt('2026-11-12'), $this->dt('2026-11-20'));
    }

    public function testTouchingSameTypeIsRejected(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(UserAvailabilityService::class);
        $user = $this->createUser($em);

        $service->create($user, UserAvailabilityType::UNAVAILABLE, $this->dt('2026-11-10'), $this->dt('2026-11-15'));

        $this->expectException(OverlappingUserAvailabilityPeriodException::class);
        $service->create($user, UserAvailabilityType::UNAVAILABLE, $this->dt('2026-11-15'), $this->dt('2026-11-20'));
    }

    public function testOverlappingDifferentTypesIsAllowed(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(UserAvailabilityService::class);
        $user = $this->createUser($em);

        $service->create($user, UserAvailabilityType::UNAVAILABLE, $this->dt('2026-11-10'), $this->dt('2026-11-15'));
        $preferPeriod = $service->create($user, UserAvailabilityType::PREFER_DUTY, $this->dt('2026-11-10'), $this->dt('2026-11-15'));

        self::assertSame(UserAvailabilityType::PREFER_DUTY, $preferPeriod->getType());
    }

    public function testRescheduleCanShiftAPeriodOverItsOwnFormerDates(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(UserAvailabilityService::class);
        $user = $this->createUser($em);

        $period = $service->create($user, UserAvailabilityType::UNAVAILABLE, $this->dt('2026-11-10'), $this->dt('2026-11-15'));
        $service->reschedule($period, UserAvailabilityType::UNAVAILABLE, $this->dt('2026-11-11'), $this->dt('2026-11-16'));

        self::assertEquals($this->dt('2026-11-11'), $period->getStartsAt());
    }

    public function testRescheduleIntoAnotherPeriodIsRejected(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(UserAvailabilityService::class);
        $user = $this->createUser($em);

        $service->create($user, UserAvailabilityType::UNAVAILABLE, $this->dt('2026-01-01'), $this->dt('2026-01-05'));
        $second = $service->create($user, UserAvailabilityType::UNAVAILABLE, $this->dt('2026-02-01'), $this->dt('2026-02-05'));

        $this->expectException(OverlappingUserAvailabilityPeriodException::class);
        $service->reschedule($second, UserAvailabilityType::UNAVAILABLE, $this->dt('2026-01-03'), $this->dt('2026-01-10'));
    }

    public function testDeleteRemovesThePeriod(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(UserAvailabilityService::class);
        $repository = self::getContainer()->get(UserAvailabilityPeriodRepository::class);
        $user = $this->createUser($em);

        $period = $service->create($user, UserAvailabilityType::UNAVAILABLE, $this->dt('2026-11-10'), $this->dt('2026-11-15'));
        $service->delete($period);

        self::assertCount(0, $repository->findByUser($user));
    }

    /**
     * A User's UNAVAILABLE period is stored once on User and must be
     * queryable regardless of which of their Teams is asking — see
     * docs/availability.md "Multi-team".
     */
    public function testUnavailabilityIsStoredOnceAndVisibleAcrossBothTeams(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $membershipService = self::getContainer()->get(TeamMembershipService::class);
        $service = self::getContainer()->get(UserAvailabilityService::class);
        $repository = self::getContainer()->get(UserAvailabilityPeriodRepository::class);

        $user = $this->createUser($em);
        $teamA = $this->createTeam($em, 'Team A', 'team-a');
        $teamB = $this->createTeam($em, 'Team B', 'team-b');
        $membershipService->addMember($teamA, $user, TeamMemberRole::MEMBER, $this->dt('2026-01-01'));
        $membershipService->addMember($teamB, $user, TeamMemberRole::MEMBER, $this->dt('2026-01-01'));

        $service->create($user, UserAvailabilityType::UNAVAILABLE, $this->dt('2026-11-10'), $this->dt('2026-11-11'));

        $periods = $repository->findByUser($user);
        self::assertCount(1, $periods, 'The unavailability must be stored exactly once, not per team.');
    }

    public function testDatabaseRejectsOverlappingPeriodsEvenBypassingTheService(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createUser($em);

        $first = new UserAvailabilityPeriod($user, UserAvailabilityType::UNAVAILABLE, $this->dt('2026-11-10'), $this->dt('2026-11-15'));
        $em->persist($first);
        $em->flush();

        $overlapping = new UserAvailabilityPeriod($user, UserAvailabilityType::UNAVAILABLE, $this->dt('2026-11-12'), $this->dt('2026-11-20'));
        $em->persist($overlapping);

        $this->expectException(DriverException::class);
        $em->flush();
    }
}
