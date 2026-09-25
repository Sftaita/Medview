<?php

declare(strict_types=1);

namespace App\Service;

use App\Eligibility\DutyUnit;
use App\Eligibility\EligibilityMatrix;
use App\Entity\PlanningSnapshot;
use App\Entity\PlanningSnapshotMember;
use App\Fairness\FairnessCandidate;
use App\Fairness\FairnessContext;
use App\Fairness\FairnessDimensionKey;
use App\Repository\PlanningLineRepository;

/**
 * Assembles one FairnessContext, strictly scoped to the single
 * PlanningLine/PlanningTeam/PlanningPeriod/PlanningGeneration/
 * PlanningSnapshot the given snapshot belongs to (docs/fairness.md
 * §FairnessContextBuilder) — the pipeline step between EligibilityMatrix
 * and the future OptimizationProblemBuilder:
 *
 *   PlanningSnapshot + EligibilityMatrix
 *     → DimensionMembership, RequiredDemand, EffectiveExposure,
 *       StructurallyForced, FairnessTargets
 *     → FairnessContext
 *
 * Takes an already-built EligibilityMatrix rather than building one
 * itself — the matrix is a distinct upstream artifact
 * (EligibilityMatrixBuilder), never re-derived here, and a caller that
 * already has one (e.g. the eligibility audit endpoint) never pays to
 * build it twice.
 */
final class FairnessContextBuilder
{
    public function __construct(
        private readonly PlanningLineRepository $planningLineRepository,
        private readonly RequiredDemandBuilder $requiredDemandBuilder,
        private readonly EffectiveExposureService $effectiveExposureService,
        private readonly FairnessTargetService $fairnessTargetService,
        private readonly StructurallyForcedAnalyzer $structurallyForcedAnalyzer,
        private readonly DimensionMembershipCalculator $dimensionMembershipCalculator,
    ) {
    }

    public function build(PlanningSnapshot $snapshot, EligibilityMatrix $matrix): FairnessContext
    {
        $generation = $snapshot->getGeneration();
        $planningPeriod = $generation->getPlanningPeriod();
        $planningTeam = $planningPeriod->getTeam();

        $planningLine = $this->planningLineRepository->findOneByPlanningPeriod($planningPeriod);
        if (null === $planningLine) {
            // Never true for a PlanningPeriod created through the real
            // application flow — PlanningPeriodLifecycleService::create()
            // is only ever called from PlanningLineService::addLine()
            // (docs/planning.md §3). Only reachable if a test builds a
            // PlanningPeriod directly, bypassing Planning/PlanningLine —
            // a fixture bug, never a state FairnessContext can represent.
            throw new \LogicException('FairnessContext can only be built for a PlanningPeriod that belongs to a PlanningLine.');
        }

        $dutyUnits = $matrix->getDutyUnits();
        $candidates = $this->buildCandidates($matrix);
        $supportedDimensions = $this->deriveSupportedDimensions($dutyUnits);

        $requiredDemand = $this->requiredDemandBuilder->build($planningPeriod);
        $effectiveExposure = $this->effectiveExposureService->build($matrix, $dutyUnits, $candidates);

        [$grossTargets, $applicableDimensions] = $this->fairnessTargetService->buildGrossTargets(
            $requiredDemand,
            $effectiveExposure,
            $supportedDimensions,
        );

        $structurallyForcedUnits = $this->structurallyForcedAnalyzer->analyze($matrix);
        $structurallyForcedLoad = $this->structurallyForcedAnalyzer->buildForcedLoad($structurallyForcedUnits);

        $discretionaryTargets = $this->fairnessTargetService->buildDiscretionaryTargets(
            $grossTargets,
            $structurallyForcedLoad,
            $supportedDimensions,
        );

        $dimensionMembershipByUnit = [];
        foreach ($dutyUnits as $unit) {
            $dimensionMembershipByUnit[$unit->getStableKey()] = $this->dimensionMembershipCalculator->forDutyUnit($unit);
        }

        return new FairnessContext(
            $planningLine,
            $planningTeam,
            $planningPeriod,
            $generation,
            $snapshot,
            $matrix,
            $candidates,
            $supportedDimensions,
            $applicableDimensions,
            $requiredDemand,
            $dimensionMembershipByUnit,
            $effectiveExposure,
            $grossTargets,
            $discretionaryTargets,
            $structurallyForcedUnits,
            $structurallyForcedLoad,
        );
    }

    /**
     * Groups the matrix's stint-level candidates back into one
     * FairnessCandidate per real person (docs/decisions.md D082 Audit A) —
     * never trust `EligibilityMatrix::getCandidates()` order/count as the
     * fairness candidate list directly.
     *
     * @return list<FairnessCandidate>
     */
    private function buildCandidates(EligibilityMatrix $matrix): array
    {
        /** @var array<string, list<PlanningSnapshotMember>> $stintsByUser */
        $stintsByUser = [];
        foreach ($matrix->getCandidates() as $member) {
            $stintsByUser[(string) $member->getSourceUserStableId()][] = $member;
        }

        $candidates = [];
        foreach ($stintsByUser as $userStableId => $stints) {
            $candidates[] = new FairnessCandidate($userStableId, $stints);
        }

        return $candidates;
    }

    /**
     * The 5 fixed dimensions always apply; one DUTY_TYPE dimension is added
     * per distinct DutyType actually present among this period's Duties —
     * never a DutyType from another PlanningTeam (dutyUnits are already
     * scoped to this PlanningPeriod alone). One ALLOCATION_FAMILY dimension
     * (docs/decisions.md D136) is likewise added per distinct
     * AllocationFamily actually referenced by a unit's pattern — read once
     * per *unit* (never per constituent Duty, though every Duty of one unit
     * necessarily shares the same family, see DimensionMembershipCalculator)
     * — a unit whose pattern has no family (or no pattern at all,
     * pre-D136) contributes nothing here, exactly like a Duty whose
     * DutyType is never guessed.
     *
     * @param list<DutyUnit> $dutyUnits
     *
     * @return list<FairnessDimensionKey>
     */
    private function deriveSupportedDimensions(array $dutyUnits): array
    {
        $dimensions = [
            FairnessDimensionKey::totalDuties(),
            FairnessDimensionKey::weightedWorkload(),
            FairnessDimensionKey::friday(),
            FairnessDimensionKey::saturday(),
            FairnessDimensionKey::sunday(),
        ];

        $seenDutyTypeIds = [];
        $seenFamilyIds = [];
        foreach ($dutyUnits as $unit) {
            foreach ($unit->getDuties() as $duty) {
                $stableId = (string) $duty->getDutyType()->getStableId();
                if (!isset($seenDutyTypeIds[$stableId])) {
                    $seenDutyTypeIds[$stableId] = true;
                    $dimensions[] = FairnessDimensionKey::dutyType($stableId);
                }
            }

            $family = $unit->getDuties()[0]->getAllocationFamily();
            if (null !== $family) {
                $familyStableId = (string) $family->getStableId();
                if (!isset($seenFamilyIds[$familyStableId])) {
                    $seenFamilyIds[$familyStableId] = true;
                    $dimensions[] = FairnessDimensionKey::allocationFamily($familyStableId);
                }
            }
        }

        return $dimensions;
    }
}
