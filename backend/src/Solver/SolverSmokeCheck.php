<?php

declare(strict_types=1);

namespace App\Solver;

use App\Eligibility\EligibilityMatrix;
use App\Eligibility\EligibilityResult;
use App\Eligibility\SingleDutyUnit;
use App\Entity\Duty;
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
use App\Fairness\CoverageStatus;
use App\Fairness\FairnessDimensionValues;
use App\Fairness\ObjectivePhase;
use App\Fairness\OptimizationMode;
use App\Fairness\OptimizationProblem;
use App\Fairness\PlanningSolver;
use App\Fairness\SolverStatus;
use Symfony\Component\Uid\Uuid;

/**
 * The real engine path, end to end, on a problem whose answer is known in
 * advance (docs/decisions.md D152): PHP → the configured PlanningSolver
 * (OrToolsPlanningSolver in dev/prod) → Symfony Process → the venv's Python
 * → bin/cp_sat_solver.py → OR-Tools CP-SAT → JSON → OptimizationResult.
 *
 * Two REQUIRED duties on consecutive days, two candidates eligible for both,
 * one real SPACING_SCORE phase: giving both duties to the same person costs
 * a spacing penalty, so the only optimum is one duty each. The problem is an
 * in-memory entity graph, never persisted — no database is read or written,
 * which is what makes this safe to run in CI, on a freshly built production
 * image, and in production after a deployment.
 */
final class SolverSmokeCheck
{
    public function __construct(
        private readonly PlanningSolver $solver,
    ) {
    }

    public function run(): SolverSmokeReport
    {
        [$problem, $unitKeys] = $this->buildProblem();
        $result = $this->solver->solve($problem);

        $failures = [];
        if (SolverStatus::OPTIMAL !== $result->strictSolverStatus) {
            $failures[] = \sprintf('STRICT status is %s, expected OPTIMAL.', $result->strictSolverStatus->value);
        }
        if (CoverageStatus::COMPLETE !== $result->coverageStatus) {
            $failures[] = \sprintf('Coverage is %s, expected COMPLETE.', $result->coverageStatus->value);
        }
        if ('' === ($result->solverMetadata?->solverVersion ?? '')) {
            $failures[] = 'The solver reported no OR-Tools version: cp_sat_solver.py did not run to completion.';
        }

        $assigned = [];
        foreach ($result->assignments as $edge) {
            $assigned[$edge->dutyUnitStableKey] = $edge->sourceTeamMemberStableId;
        }
        $expected = $unitKeys;
        $actual = array_keys($assigned);
        sort($expected);
        sort($actual);
        if ($expected !== $actual) {
            $failures[] = \sprintf('Expected exactly the 2 duties to be assigned, got %d assignment(s).', \count($result->assignments));
        } elseif (2 !== \count(array_unique($assigned))) {
            $failures[] = 'Both duties went to the same candidate: the SPACING_SCORE optimum (one duty each) was not reached.';
        }

        return new SolverSmokeReport($result, $failures);
    }

    /**
     * @return array{0: OptimizationProblem, 1: list<string>}
     */
    private function buildProblem(): array
    {
        $timezone = 'Europe/Brussels';
        $creator = new User('solver-smoke@medvue.invalid', 'Solver', 'Smoke', 'not-a-password-hash');
        $planning = new Planning('Solver smoke', $creator, new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-02-01'), $timezone);
        $team = new PlanningTeam($planning, 'Solver smoke');
        $fairnessPeriod = new FairnessPeriod($team, 'Solver smoke', new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-02-01'));
        $period = new PlanningPeriod($team, $fairnessPeriod, 'Solver smoke', new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-02-01'));
        $dutyType = new DutyType($team, 'GARDE', 'Garde');

        $units = [];
        foreach (['2027-01-04', '2027-01-05'] as $day) {
            $startsAt = new \DateTimeImmutable("{$day} 08:00", new \DateTimeZone($timezone));
            $units[] = new SingleDutyUnit(new Duty($period, $dutyType, $startsAt, $startsAt->modify('+1 day'), $timezone, DutyDemandType::REQUIRED));
        }

        $snapshot = new PlanningSnapshot(new PlanningGeneration($period, $creator));
        $candidates = [];
        foreach ([1, 2] as $ignored) {
            $candidates[] = new PlanningSnapshotMember($snapshot, Uuid::v7(), Uuid::v7(), new \DateTimeImmutable('2026-01-01'), null, TeamMemberRole::MEMBER, true);
        }

        $entries = [];
        foreach ($units as $unit) {
            foreach ($candidates as $candidate) {
                $entries[$unit->getStableKey()][(string) $candidate->getSourceTeamMemberStableId()] = new EligibilityResult([], true);
            }
        }

        $problem = new OptimizationProblem(
            OptimizationMode::GENERATE,
            $units,
            [],
            FairnessDimensionValues::empty(),
            new EligibilityMatrix($units, $candidates, $entries),
            [],
            [],
            [],
            new CoveragePolicy(true, []),
            [ObjectivePhase::spacingScore()],
            [],
            timeoutSeconds: 10,
        );

        return [$problem, array_map(static fn (SingleDutyUnit $unit): string => $unit->getStableKey(), $units)];
    }
}
