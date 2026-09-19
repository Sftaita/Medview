<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\PlanningPeriod;
use App\Entity\RestPolicyOptions;
use App\Entity\SolverParameterSet;
use App\Fairness\OptimizationProblem;
use App\Service\EligibilityMatrixBuilder;
use App\Service\FairnessContextBuilder;
use App\Service\OptimizationProblemBuilder;
use App\Service\PlanningSnapshotService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lot 6B (docs/planning-solver.md) fixture plumbing — extends
 * FairnessTestHelpers's PlanningSnapshot -> EligibilityMatrix ->
 * FairnessContext pipeline one step further, into a real
 * `OptimizationProblem`, ready to hand to `OrToolsPlanningSolver`.
 */
trait SolverTestHelpers
{
    use FairnessTestHelpers;

    private function buildOptimizationProblem(
        EntityManagerInterface $em,
        PlanningSnapshotService $snapshotService,
        EligibilityMatrixBuilder $matrixBuilder,
        FairnessContextBuilder $contextBuilder,
        OptimizationProblemBuilder $problemBuilder,
        PlanningPeriod $planningPeriod,
        ?RestPolicyOptions $restPolicy = null,
        ?SolverParameterSet $parameterSet = null,
    ): OptimizationProblem {
        $context = $this->buildFairnessContext($em, $snapshotService, $matrixBuilder, $contextBuilder, $planningPeriod, $restPolicy);

        return $problemBuilder->build($context, $parameterSet ?? $this->testSolverParameterSet());
    }

    /**
     * A generous, purely in-memory SolverParameterSet — never persisted,
     * since OptimizationProblemBuilder only ever reads its two scalar
     * getters. 30s/phase is ample for these small fixture-sized problems.
     */
    private function testSolverParameterSet(): SolverParameterSet
    {
        return new SolverParameterSet(1, 30, 1);
    }
}
