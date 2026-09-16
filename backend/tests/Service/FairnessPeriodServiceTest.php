<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\OverlappingFairnessPeriodException;
use App\Service\FairnessPeriodService;
use App\Tests\PlanningDomainTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FairnessPeriodServiceTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;

    public function testCreatesAValidPeriod(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(FairnessPeriodService::class);

        $team = $this->createTeam($em);
        $period = $service->create($team, '2027', $this->date('2027-01-01'), $this->date('2028-01-01'));

        self::assertTrue($period->contains($this->date('2027-06-15')));
        self::assertFalse($period->contains($this->date('2028-01-01')), 'endsAt is exclusive');
    }

    public function testRejectsInvertedDatesAtConstruction(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(FairnessPeriodService::class);

        $team = $this->createTeam($em);

        $this->expectException(\InvalidArgumentException::class);
        $service->create($team, '2027', $this->date('2028-01-01'), $this->date('2027-01-01'));
    }

    public function testRejectsOverlapWithinTheSameTeam(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(FairnessPeriodService::class);

        $team = $this->createTeam($em);
        $service->create($team, '2027', $this->date('2027-01-01'), $this->date('2028-01-01'));

        $this->expectException(OverlappingFairnessPeriodException::class);
        $service->create($team, '2027 bis', $this->date('2027-06-01'), $this->date('2027-09-01'));
    }

    public function testAllowsBackToBackNonOverlappingPeriods(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(FairnessPeriodService::class);

        $team = $this->createTeam($em);
        $service->create($team, '2027', $this->date('2027-01-01'), $this->date('2028-01-01'));

        // Starts exactly where the previous one ends — legal (half-open ranges).
        $period2028 = $service->create($team, '2028', $this->date('2028-01-01'), $this->date('2029-01-01'));
        self::assertTrue($period2028->contains($this->date('2028-01-01')));
    }

    public function testDoesNotRejectOverlapAcrossDifferentTeams(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $service = self::getContainer()->get(FairnessPeriodService::class);

        $teamA = $this->createTeam($em, 'Cardiology');
        $teamB = $this->createTeam($em, 'Radiology');

        $service->create($teamA, '2027', $this->date('2027-01-01'), $this->date('2028-01-01'));
        $periodB = $service->create($teamB, '2027', $this->date('2027-01-01'), $this->date('2028-01-01'));

        self::assertTrue($periodB->contains($this->date('2027-06-01')));
    }
}
