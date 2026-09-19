<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Eligibility\EligibilityExclusion;
use App\Eligibility\EligibilityMatrix;
use App\Eligibility\EligibilityResult;
use App\Eligibility\ExclusionReason;
use App\Eligibility\SingleDutyUnit;
use App\Entity\Duty;
use App\Entity\DutyCriticality;
use App\Entity\DutyDemandType;
use App\Entity\DutyType;
use App\Entity\FairnessPeriod;
use App\Entity\Planning;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningSnapshot;
use App\Entity\PlanningSnapshotMember;
use App\Entity\PlanningTeam;
use App\Entity\TeamMemberRole;
use App\Entity\User;
use App\Fairness\CoveragePolicy;
use App\Fairness\ExistingDataConflict;
use App\Fairness\ExistingDataConflictType;
use App\Fairness\FairnessDimensionValues;
use App\Fairness\OptimizationMode;
use App\Fairness\OptimizationProblem;
use App\Fairness\SolverStatus;
use App\Fairness\StructuralDiagnosticCode;
use App\Service\UnsatDiagnosticsBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Direct, hand-built-matrix tests (same convention as
 * StructurallyForcedAnalyzerTest's own hand-built EligibilityMatrix) —
 * UnsatDiagnosticsBuilder is solver-agnostic and needs no real subprocess,
 * no database, only an OptimizationProblem.
 */
final class UnsatDiagnosticsBuilderTest extends TestCase
{
    public function testDutyWithNoEligibleCandidateGetsANoEligibleCandidateStructuralDiagnostic(): void
    {
        [$problem, $units] = $this->buildScenario();
        $builder = new UnsatDiagnosticsBuilder();

        $report = $builder->buildForCoverageShortfall(
            $problem,
            SolverStatus::UNSATISFIABLE,
            SolverStatus::OPTIMAL,
            [$units['noCandidate']->getStableKey()],
        );

        self::assertCount(1, $report->structuralDiagnostics);
        self::assertSame(StructuralDiagnosticCode::NO_ELIGIBLE_CANDIDATE, $report->structuralDiagnostics[0]->code);
        self::assertSame($units['noCandidate']->getStableKey(), $report->structuralDiagnostics[0]->dutyUnitStableKey);
    }

    public function testUnavailableCandidateExclusionIsVisible(): void
    {
        [$problem, $units] = $this->buildScenario();
        $builder = new UnsatDiagnosticsBuilder();

        $report = $builder->buildForCoverageShortfall(
            $problem,
            SolverStatus::UNSATISFIABLE,
            SolverStatus::OPTIMAL,
            [$units['mixed']->getStableKey()],
        );

        $duty = $report->unassignedDuties[0];
        $reasons = $this->reasonsFor($duty, 'unavailable-candidate');

        self::assertSame([ExclusionReason::UNAVAILABLE], $reasons);
    }

    public function testMembershipOutOfRangeCandidateExclusionIsVisible(): void
    {
        [$problem, $units] = $this->buildScenario();
        $builder = new UnsatDiagnosticsBuilder();

        $report = $builder->buildForCoverageShortfall(
            $problem,
            SolverStatus::UNSATISFIABLE,
            SolverStatus::OPTIMAL,
            [$units['mixed']->getStableKey()],
        );

        $duty = $report->unassignedDuties[0];
        $reasons = $this->reasonsFor($duty, 'out-of-range-candidate');

        self::assertSame([ExclusionReason::MEMBERSHIP_OUT_OF_RANGE], $reasons);
    }

    public function testEligibleButUnselectedCandidateNeverGetsAFabricatedExclusion(): void
    {
        [$problem, $units] = $this->buildScenario();
        $builder = new UnsatDiagnosticsBuilder();

        $report = $builder->buildForCoverageShortfall(
            $problem,
            SolverStatus::UNSATISFIABLE,
            SolverStatus::OPTIMAL,
            [$units['mixed']->getStableKey()],
        );

        $duty = $report->unassignedDuties[0];
        $candidateIds = array_map(static fn ($c) => $c->candidateId, $duty->candidateExclusions);

        self::assertNotContains('eligible-candidate', $candidateIds, 'an eligible candidate must never appear in candidateExclusions, fabricated or not');
    }

    public function testCriticalityIsCorrectlyReportedPerUnassignedUnit(): void
    {
        [$problem, $units] = $this->buildScenario();
        $builder = new UnsatDiagnosticsBuilder();

        $report = $builder->buildForCoverageShortfall(
            $problem,
            SolverStatus::UNSATISFIABLE,
            SolverStatus::OPTIMAL,
            [$units['noCandidate']->getStableKey(), $units['mixed']->getStableKey()],
        );

        $byKey = [];
        foreach ($report->unassignedDuties as $d) {
            $byKey[$d->dutyUnitStableKey] = $d;
        }

        self::assertTrue($byKey[$units['noCandidate']->getStableKey()]->critical);
        self::assertFalse($byKey[$units['mixed']->getStableKey()]->critical);
    }

    public function testNoPolicyHardIsEverProposedAsARelaxationToday(): void
    {
        [$problem] = $this->buildScenario();
        $builder = new UnsatDiagnosticsBuilder();

        $report = $builder->buildForCoverageShortfall($problem, SolverStatus::UNSATISFIABLE, SolverStatus::OPTIMAL, []);

        self::assertSame([], $report->diagnosticRelaxations, 'no POLICY_HARD reason is ever produced by EligibilityService today (docs/eligibility.md §3)');
    }

    public function testSolverAnalysisIsAlwaysUnavailableInThisLot(): void
    {
        [$problem] = $this->buildScenario();
        $builder = new UnsatDiagnosticsBuilder();

        $report = $builder->buildForCoverageShortfall($problem, SolverStatus::UNSATISFIABLE, SolverStatus::OPTIMAL, []);

        self::assertFalse($report->solverAnalysis->available);
        self::assertNull($report->solverAnalysis->infeasibleCore);
    }

    public function testRequiredAndAssignedDutyCountsAreCorrect(): void
    {
        [$problem, $units] = $this->buildScenario();
        $builder = new UnsatDiagnosticsBuilder();

        $report = $builder->buildForCoverageShortfall(
            $problem,
            SolverStatus::UNSATISFIABLE,
            SolverStatus::OPTIMAL,
            [$units['noCandidate']->getStableKey()],
        );

        self::assertSame(2, $report->requiredDutyCount);
        self::assertSame(1, $report->assignedDutyCount);
    }

    /**
     * docs/decisions.md D098: this path is contract-ready but unreachable
     * through real CP-SAT solving today — verified directly here instead.
     */
    public function testExistingDataConflictBuildsACompleteReport(): void
    {
        [$problem] = $this->buildScenario();
        $builder = new UnsatDiagnosticsBuilder();
        $conflict = new ExistingDataConflict(
            ExistingDataConflictType::LOCKED_ASSIGNMENT_CONTRADICTION,
            ['unit-a'],
            ['candidate-a'],
            ['synthetic reason for this test'],
            'synthetic message for this test',
        );

        $report = $builder->buildForExistingDataConflict($problem, SolverStatus::UNSATISFIABLE, $conflict);

        self::assertSame(SolverStatus::UNSATISFIABLE, $report->strictSolverStatus);
        self::assertSame(SolverStatus::UNSATISFIABLE, $report->partialSolverStatus);
        self::assertSame($conflict, $report->existingDataConflict);
        self::assertSame([], $report->unassignedDuties);
        self::assertSame(0, $report->assignedDutyCount);
    }

    private function reasonsFor(mixed $unassignedDutyDiagnostic, string $candidateId): array
    {
        foreach ($unassignedDutyDiagnostic->candidateExclusions as $exclusion) {
            if ($exclusion->candidateId === $candidateId) {
                return array_map(static fn (EligibilityExclusion $e) => $e->reason, $exclusion->exclusions);
            }
        }

        self::fail(sprintf('no candidateExclusions entry found for %s', $candidateId));
    }

    /**
     * @return array{0: OptimizationProblem, 1: array<string, SingleDutyUnit>}
     */
    private function buildScenario(): array
    {
        $planning = new Planning('P', new User('creator@example.com', 'C', 'U', 'h'), new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2028-01-01'), 'Europe/Brussels');
        $team = new PlanningTeam($planning, 'Team');
        $fairnessPeriod = new FairnessPeriod($team, '2027', new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2028-01-01'));
        $planningPeriod = new PlanningPeriod($team, $fairnessPeriod, 'P1', new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-05-01'));
        $dutyType = new DutyType($team, 'DAY', 'Day');

        $dutyNoCandidate = new Duty($planningPeriod, $dutyType, new \DateTimeImmutable('2027-02-01 08:00', new \DateTimeZone('Europe/Brussels')), new \DateTimeImmutable('2027-02-01 20:00', new \DateTimeZone('Europe/Brussels')), 'Europe/Brussels', DutyDemandType::REQUIRED, DutyCriticality::CRITICAL);
        $dutyMixed = new Duty($planningPeriod, $dutyType, new \DateTimeImmutable('2027-02-02 08:00', new \DateTimeZone('Europe/Brussels')), new \DateTimeImmutable('2027-02-02 20:00', new \DateTimeZone('Europe/Brussels')), 'Europe/Brussels', DutyDemandType::REQUIRED, DutyCriticality::STANDARD);

        $unitNoCandidate = new SingleDutyUnit($dutyNoCandidate);
        $unitMixed = new SingleDutyUnit($dutyMixed);

        $generation = new PlanningGeneration($planningPeriod);
        $snapshot = new PlanningSnapshot($generation);

        $unavailableMember = new PlanningSnapshotMember($snapshot, Uuid::v7(), Uuid::v7(), new \DateTimeImmutable('2027-01-01'), null, TeamMemberRole::MEMBER, true);
        $outOfRangeMember = new PlanningSnapshotMember($snapshot, Uuid::v7(), Uuid::v7(), new \DateTimeImmutable('2027-01-01'), null, TeamMemberRole::MEMBER, true);
        $eligibleMember = new PlanningSnapshotMember($snapshot, Uuid::v7(), Uuid::v7(), new \DateTimeImmutable('2027-01-01'), null, TeamMemberRole::MEMBER, true);

        $matrix = new EligibilityMatrix(
            [$unitNoCandidate, $unitMixed],
            [$unavailableMember, $outOfRangeMember, $eligibleMember],
            [
                $unitNoCandidate->getStableKey() => [
                    'unavailable-candidate' => new EligibilityResult([new EligibilityExclusion(ExclusionReason::UNAVAILABLE)], true),
                    'out-of-range-candidate' => new EligibilityResult([new EligibilityExclusion(ExclusionReason::MEMBERSHIP_OUT_OF_RANGE)], false),
                ],
                $unitMixed->getStableKey() => [
                    'unavailable-candidate' => new EligibilityResult([new EligibilityExclusion(ExclusionReason::UNAVAILABLE)], true),
                    'out-of-range-candidate' => new EligibilityResult([new EligibilityExclusion(ExclusionReason::MEMBERSHIP_OUT_OF_RANGE)], false),
                    'eligible-candidate' => new EligibilityResult([], true),
                ],
            ],
        );

        $coveragePolicy = new CoveragePolicy(true, [$unitNoCandidate->getStableKey()]);

        $problem = new OptimizationProblem(
            OptimizationMode::GENERATE,
            [$unitNoCandidate, $unitMixed],
            [],
            FairnessDimensionValues::empty(),
            $matrix,
            [],
            [],
            [],
            $coveragePolicy,
            [],
            [],
        );

        return [$problem, ['noCandidate' => $unitNoCandidate, 'mixed' => $unitMixed]];
    }
}
