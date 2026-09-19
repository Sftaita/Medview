<?php

declare(strict_types=1);

namespace App\Tests\Fairness;

use App\Eligibility\EligibilityMatrix;
use App\Fairness\CoveragePolicy;
use App\Fairness\CoverageStatus;
use App\Fairness\DutyAssignmentEdge;
use App\Fairness\FairnessDimensionValues;
use App\Fairness\OptimizationMode;
use App\Fairness\OptimizationProblem;
use App\Fairness\OptimizationResult;
use App\Fairness\SolverStatus;
use PHPUnit\Framework\TestCase;

final class FakePlanningSolverTest extends TestCase
{
    public function testCanReturnOptimal(): void
    {
        $result = new OptimizationResult(SolverStatus::OPTIMAL, null, CoverageStatus::COMPLETE, [], [], [], [], null, null, null);
        $solver = new FakePlanningSolver($result);

        self::assertSame(SolverStatus::OPTIMAL, $solver->solve($this->buildEmptyProblem())->strictSolverStatus);
    }

    public function testCanReturnFeasible(): void
    {
        $result = new OptimizationResult(SolverStatus::FEASIBLE, null, CoverageStatus::COMPLETE, [], [], [], [], null, null, null);
        $solver = new FakePlanningSolver($result);

        self::assertSame(SolverStatus::FEASIBLE, $solver->solve($this->buildEmptyProblem())->strictSolverStatus);
    }

    public function testUnknownStaysDistinctFromUnsatisfiable(): void
    {
        $result = new OptimizationResult(SolverStatus::UNKNOWN, null, CoverageStatus::INCOMPLETE, [], [], [], [], null, null, null);
        $solver = new FakePlanningSolver($result);

        $status = $solver->solve($this->buildEmptyProblem())->strictSolverStatus;

        self::assertSame(SolverStatus::UNKNOWN, $status);
        self::assertNotSame(SolverStatus::UNSATISFIABLE, $status);
    }

    public function testErrorStaysDistinctFromUnsatisfiable(): void
    {
        $result = new OptimizationResult(SolverStatus::ERROR, null, CoverageStatus::INCOMPLETE, [], [], [], [], null, null, null);
        $solver = new FakePlanningSolver($result);

        $status = $solver->solve($this->buildEmptyProblem())->strictSolverStatus;

        self::assertSame(SolverStatus::ERROR, $status);
        self::assertNotSame(SolverStatus::UNSATISFIABLE, $status);
    }

    public function testCheckFeasibilityAcceptsExcludedEdgesWithoutModifyingTheProblem(): void
    {
        $solver = new FakePlanningSolver(configuredFeasibility: SolverStatus::UNSATISFIABLE);
        $problem = $this->buildEmptyProblem();
        $requiredBefore = $problem->getRequiredDutyUnits();

        $status = $solver->checkFeasibility($problem, [
            new DutyAssignmentEdge('duty-unit-1', 'team-member-1'),
            new DutyAssignmentEdge('duty-unit-2', 'team-member-2'),
        ]);

        self::assertSame(SolverStatus::UNSATISFIABLE, $status);
        self::assertSame($requiredBefore, $problem->getRequiredDutyUnits());
        self::assertCount(1, $solver->getFeasibilityCalls());
        self::assertCount(2, $solver->getFeasibilityCalls()[0]['excludedEdges']);
    }

    public function testCheckFeasibilityNeverCollapsesUnknownIntoFalse(): void
    {
        $solver = new FakePlanningSolver(configuredFeasibility: SolverStatus::UNKNOWN);

        $status = $solver->checkFeasibility($this->buildEmptyProblem(), []);

        self::assertSame(SolverStatus::UNKNOWN, $status);
        self::assertNotSame(SolverStatus::UNSATISFIABLE, $status);
    }

    public function testSolveNeverMutatesTheProblemItReceives(): void
    {
        $solver = new FakePlanningSolver();
        $problem = $this->buildEmptyProblem();

        $modeBefore = $problem->getMode();
        $phasesBefore = $problem->getObjectivePhases();

        $solver->solve($problem);

        self::assertSame($modeBefore, $problem->getMode());
        self::assertSame($phasesBefore, $problem->getObjectivePhases());
    }

    public function testMutatingAnExposedCollectionNeverAffectsTheProblem(): void
    {
        $problem = $this->buildEmptyProblem();

        $units = $problem->getRequiredDutyUnits();
        $units[] = 'not-a-real-unit';

        self::assertSame([], $problem->getRequiredDutyUnits(), 'PHP arrays are copy-on-write, but this locks the guarantee in as a regression test');
    }

    private function buildEmptyProblem(): OptimizationProblem
    {
        return new OptimizationProblem(
            OptimizationMode::GENERATE,
            [],
            [],
            FairnessDimensionValues::empty(),
            new EligibilityMatrix([], [], []),
            [],
            [],
            [],
            new CoveragePolicy(true, []),
            [],
            [],
        );
    }
}
