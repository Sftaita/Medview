<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PlanningPeriodStatus;
use App\Exception\InvalidPlanningPeriodTransitionException;
use App\Service\FairnessPeriodService;
use App\Service\PlanningPeriodLifecycleService;
use App\Tests\PlanningDomainTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlanningPeriodLifecycleServiceTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;

    public function testCreatingAPeriodOutsideItsFairnessPeriodIsRejected(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $fairnessService = self::getContainer()->get(FairnessPeriodService::class);
        $lifecycleService = self::getContainer()->get(PlanningPeriodLifecycleService::class);

        $team = $this->createTeam($em);
        $fairnessPeriod = $fairnessService->create($team, '2027', $this->date('2027-01-01'), $this->date('2028-01-01'));

        $this->expectException(\InvalidArgumentException::class);
        $lifecycleService->create($team, $fairnessPeriod, 'Q1 overrun', $this->date('2026-12-01'), $this->date('2027-04-01'));
    }

    public function testNewPeriodStartsAsDraftWithAStableIdThatSurvivesTransitions(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $fairnessService = self::getContainer()->get(FairnessPeriodService::class);
        $lifecycleService = self::getContainer()->get(PlanningPeriodLifecycleService::class);

        $team = $this->createTeam($em);
        $fairnessPeriod = $fairnessService->create($team, '2027', $this->date('2027-01-01'), $this->date('2028-01-01'));
        $period = $lifecycleService->create($team, $fairnessPeriod, 'Jan-Apr', $this->date('2027-01-01'), $this->date('2027-05-01'));

        self::assertSame(PlanningPeriodStatus::DRAFT, $period->getStatus());
        $stableId = $period->getStableId();

        $lifecycleService->transition($period, PlanningPeriodStatus::GENERATED);
        $lifecycleService->transition($period, PlanningPeriodStatus::VALIDATED);

        self::assertTrue($stableId->equals($period->getStableId()), 'stableId must never change across lifecycle transitions');
        self::assertSame(PlanningPeriodStatus::VALIDATED, $period->getStatus());
    }

    public function testIllegalTransitionIsRejected(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $fairnessService = self::getContainer()->get(FairnessPeriodService::class);
        $lifecycleService = self::getContainer()->get(PlanningPeriodLifecycleService::class);

        $team = $this->createTeam($em);
        $fairnessPeriod = $fairnessService->create($team, '2027', $this->date('2027-01-01'), $this->date('2028-01-01'));
        $period = $lifecycleService->create($team, $fairnessPeriod, 'Jan-Apr', $this->date('2027-01-01'), $this->date('2027-05-01'));

        $this->expectException(InvalidPlanningPeriodTransitionException::class);
        $lifecycleService->transition($period, PlanningPeriodStatus::PUBLISHED);
    }

    public function testPublishedNeverGoesBackward(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $fairnessService = self::getContainer()->get(FairnessPeriodService::class);
        $lifecycleService = self::getContainer()->get(PlanningPeriodLifecycleService::class);

        $team = $this->createTeam($em);
        $fairnessPeriod = $fairnessService->create($team, '2027', $this->date('2027-01-01'), $this->date('2028-01-01'));
        $period = $lifecycleService->create($team, $fairnessPeriod, 'Jan-Apr', $this->date('2027-01-01'), $this->date('2027-05-01'));

        $lifecycleService->transition($period, PlanningPeriodStatus::GENERATED);
        $lifecycleService->transition($period, PlanningPeriodStatus::VALIDATED);
        $lifecycleService->transition($period, PlanningPeriodStatus::PUBLISHED);

        $this->expectException(InvalidPlanningPeriodTransitionException::class);
        $lifecycleService->transition($period, PlanningPeriodStatus::DRAFT);
    }
}
