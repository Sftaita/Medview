<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PlanningGeneration;
use App\Entity\PlanningGenerationStatus;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningPeriodStatus;
use App\Entity\SolverParameterSet;
use App\Entity\SolverRunMetadata;
use App\Exception\InvalidPlanningPeriodTransitionException;
use App\Exception\PlanningPeriodNotReadyToPublishException;
use App\Fairness\CoverageStatus;
use App\Fairness\SolverStatus;
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
        $this->completeGeneration($em, $period, CoverageStatus::COMPLETE);

        $lifecycleService->transition($period, PlanningPeriodStatus::GENERATED);
        $lifecycleService->transition($period, PlanningPeriodStatus::VALIDATED);
        $lifecycleService->transition($period, PlanningPeriodStatus::PUBLISHED);

        $this->expectException(InvalidPlanningPeriodTransitionException::class);
        $lifecycleService->transition($period, PlanningPeriodStatus::DRAFT);
    }

    /**
     * docs/decisions.md D106 — PUBLISHED now requires a COMPLETED
     * generation with COMPLETE coverage.
     */
    public function testPublishRequiresACompletedGenerationWithCompleteCoverage(): void
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

        $this->expectException(PlanningPeriodNotReadyToPublishException::class);
        $lifecycleService->transition($period, PlanningPeriodStatus::PUBLISHED);
    }

    public function testPublishRejectsAnIncompleteCoverageGeneration(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $fairnessService = self::getContainer()->get(FairnessPeriodService::class);
        $lifecycleService = self::getContainer()->get(PlanningPeriodLifecycleService::class);

        $team = $this->createTeam($em);
        $fairnessPeriod = $fairnessService->create($team, '2027', $this->date('2027-01-01'), $this->date('2028-01-01'));
        $period = $lifecycleService->create($team, $fairnessPeriod, 'Jan-Apr', $this->date('2027-01-01'), $this->date('2027-05-01'));
        $this->completeGeneration($em, $period, CoverageStatus::INCOMPLETE);

        $lifecycleService->transition($period, PlanningPeriodStatus::GENERATED);
        $lifecycleService->transition($period, PlanningPeriodStatus::VALIDATED);

        $this->expectException(PlanningPeriodNotReadyToPublishException::class);
        $lifecycleService->transition($period, PlanningPeriodStatus::PUBLISHED);
    }

    /**
     * Directly walks a fresh PlanningGeneration through its own status
     * graph to COMPLETED with a chosen coverageStatus — never a real
     * snapshot/solve, PlanningPeriodLifecycleService's PUBLISHED guard only
     * ever reads PlanningGeneration's own persisted status/coverageStatus.
     */
    private function completeGeneration(EntityManagerInterface $em, PlanningPeriod $period, CoverageStatus $coverageStatus): void
    {
        // Version 1 is already seeded by the Lot 6E migration (docs/decisions.md
        // D106) — a high, fixed version here avoids colliding with it or with
        // another call to this helper within the same rolled-back test transaction.
        $parameterSet = new SolverParameterSet(9001, 30, 1);
        $em->persist($parameterSet);

        $generation = new PlanningGeneration($period);
        $em->persist($generation);
        $generation->transitionTo(PlanningGenerationStatus::SNAPSHOTTED);
        $generation->transitionTo(PlanningGenerationStatus::SOLVING);
        $generation->recordSolverRun(
            new SolverRunMetadata('test-v1', 'FAKE', '1.0', 1, 'seed', 'hash', SolverStatus::OPTIMAL, null, $coverageStatus, [], [], 0, false, null),
            $parameterSet,
        );
        $generation->transitionTo(PlanningGenerationStatus::COMPLETED);
        $em->flush();
    }
}
