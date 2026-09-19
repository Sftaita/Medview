<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\PlanningGeneration;
use App\Entity\PlanningPeriod;
use App\Entity\RestPolicyOptions;
use App\Fairness\FairnessContext;
use App\Service\EligibilityMatrixBuilder;
use App\Service\FairnessContextBuilder;
use App\Service\PlanningSnapshotService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Shared plumbing for Lot 5 (fairness) tests, built on top of
 * PlanningTestHelpers/PlanningGenerationTestHelpers/PlanningDomainTestHelpers.
 *
 * A FairnessContext can only be built for a PlanningPeriod that belongs to
 * a real PlanningLine (FairnessContextBuilder's own invariant,
 * docs/fairness.md) — so, unlike earlier lots' tests,
 * PlanningDomainTestHelpers::createTeam()/createPlanningPeriod() (which
 * build a "bare" PlanningTeam/PlanningPeriod with no owning PlanningLine)
 * cannot be used as the base fixture here. Fairness tests must go through
 * PlanningTestHelpers::createPlanning()/addLine() instead, then read the
 * resulting PlanningTeam/PlanningPeriod off the created PlanningLine.
 */
trait FairnessTestHelpers
{
    /**
     * Runs the PlanningGeneration → PlanningSnapshot → EligibilityMatrix →
     * FairnessContext pipeline for an already fully set-up PlanningPeriod
     * (team, duty types, duties, members, active rule set all created by
     * the caller beforehand).
     */
    private function buildFairnessContext(
        EntityManagerInterface $em,
        PlanningSnapshotService $snapshotService,
        EligibilityMatrixBuilder $matrixBuilder,
        FairnessContextBuilder $contextBuilder,
        PlanningPeriod $planningPeriod,
        ?RestPolicyOptions $restPolicy = null,
    ): FairnessContext {
        $generation = new PlanningGeneration($planningPeriod, restPolicy: $restPolicy ?? RestPolicyOptions::none());
        $em->persist($generation);
        $em->flush();

        $snapshot = $snapshotService->createSnapshot($generation);
        $matrix = $matrixBuilder->build($snapshot);

        return $contextBuilder->build($snapshot, $matrix);
    }
}
