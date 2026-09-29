<?php

declare(strict_types=1);

namespace App\Tests\Solver;

use App\Fairness\CoverageStatus;
use App\Fairness\DutyAssignmentEdge;
use App\Fairness\OptimizationProblem;
use App\Fairness\OptimizationResult;
use App\Fairness\PlanningSolver;
use App\Fairness\SolverMetadata;
use App\Fairness\SolverStatus;
use App\Service\UnsatDiagnosticsBuilder;
use App\Solver\CpSatPayloadBuilder;
use App\Solver\OrToolsPlanningSolver;
use App\Solver\SolverSmokeCheck;
use App\Tests\FaultInjectingPlanningSolver;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * docs/decisions.md D152 — `app:solver:smoke` must pass on a working engine
 * path and must fail on each way that path has broken or could break: the
 * PHP process class missing from the image (the 2026-09-28 production
 * incident), the Python interpreter unusable, or CP-SAT not reaching the
 * known optimum.
 */
final class SolverSmokeCheckTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        FaultInjectingPlanningSolver::reset();
        parent::tearDown();
    }

    public function testTheRealEnginePathPassesThroughTheCommand(): void
    {
        $tester = $this->commandTester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('OR-Tools CP-SAT 9.', $display);
        self::assertStringContainsString('OPTIMAL', $display);
        self::assertStringContainsString('COMPLETE', $display);
        self::assertStringContainsString('Solver smoke test passed.', $display);
    }

    public function testTheCommandNeverReportsSuccessWhenTheProcessClassIsMissing(): void
    {
        FaultInjectingPlanningSolver::$failWith = new \Error('Class "Symfony\Component\Process\Process" not found');
        $tester = $this->commandTester();

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Symfony\Component\Process\Process');
        $tester->execute([]);
    }

    public function testAnUnusablePythonInterpreterFailsTheCheck(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $solver = new OrToolsPlanningSolver(
            $container->get(CpSatPayloadBuilder::class),
            $container->get(UnsatDiagnosticsBuilder::class),
            '/nonexistent/ortools-venv/bin/python3',
        );

        $report = (new SolverSmokeCheck($solver))->run();

        self::assertFalse($report->isSuccessful());
        self::assertSame(SolverStatus::ERROR, $report->result->strictSolverStatus);
        self::assertContains('STRICT status is ERROR, expected OPTIMAL.', $report->failures);
        self::assertContains('The solver reported no OR-Tools version: cp_sat_solver.py did not run to completion.', $report->failures);
    }

    public function testASolutionThatMissesTheSpacingOptimumFailsTheCheck(): void
    {
        $sameCandidateForEverything = new class implements PlanningSolver {
            public function solve(OptimizationProblem $problem): OptimizationResult
            {
                $candidate = (string) $problem->getEligibilityMatrix()->getCandidates()[0]->getSourceTeamMemberStableId();

                return new OptimizationResult(
                    SolverStatus::OPTIMAL,
                    null,
                    CoverageStatus::COMPLETE,
                    array_map(static fn ($unit): DutyAssignmentEdge => new DutyAssignmentEdge($unit->getStableKey(), $candidate), $problem->getRequiredDutyUnits()),
                    [],
                    [],
                    [],
                    null,
                    null,
                    new SolverMetadata('stub', '9.15.6755', null, 0, false),
                );
            }

            public function checkFeasibility(OptimizationProblem $problem, array $excludedEdges): SolverStatus
            {
                return SolverStatus::OPTIMAL;
            }
        };

        $report = (new SolverSmokeCheck($sameCandidateForEverything))->run();

        self::assertSame(
            ['Both duties went to the same candidate: the SPACING_SCORE optimum (one duty each) was not reached.'],
            $report->failures,
        );
    }

    private function commandTester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('app:solver:smoke'));
    }
}
