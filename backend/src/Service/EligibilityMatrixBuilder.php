<?php

declare(strict_types=1);

namespace App\Service;

use App\Eligibility\EligibilityMatrix;
use App\Entity\PlanningSnapshot;
use App\Repository\DutyRepository;

/**
 * Builds the full DutyUnit × PlanningSnapshotMember EligibilityMatrix for
 * one snapshot (docs/eligibility.md §Matrice) — every standalone Duty of
 * the PlanningPeriod becomes a SingleDutyUnit, every DutyGroupInstance's
 * constituent Duty rows are folded into one DutyGroupUnit (DutyUnitFactory,
 * docs/decisions.md D136), evaluated against every member captured in the
 * snapshot.
 */
final class EligibilityMatrixBuilder
{
    public function __construct(
        private readonly DutyRepository $dutyRepository,
        private readonly EligibilityService $eligibilityService,
        private readonly DutyUnitFactory $dutyUnitFactory,
        private readonly SnapshotDemandViewFactory $demandViewFactory,
    ) {
    }

    /**
     * @param bool $withFrozenExternalCommitments false leaves out the duties frozen from other lines
     *                                            (docs/decisions.md D161) — only for a caller that
     *                                            re-checks cross-line compatibility against the live
     *                                            calendar instead (PlanningCompletionService): a frozen
     *                                            commitment may no longer hold, and must then never keep
     *                                            excluding a person who is free today
     */
    public function build(PlanningSnapshot $snapshot, bool $withFrozenExternalCommitments = true): EligibilityMatrix
    {
        $planningPeriod = $snapshot->getGeneration()->getPlanningPeriod();
        $duties = $this->dutyRepository->findByPlanningPeriod($planningPeriod);

        // docs/decisions.md D164: the units of THIS generation's problem, per its frozen demand — an intrinsic unit as
        // always; a conditional unit only when its frozen decision is "required" (absent otherwise, never optional).
        // Everything built from the matrix (eligibility, exposure, forced load, targets, conflicts, the problem)
        // therefore only ever sees the demand this generation really has.
        $dutyUnits = $this->dutyUnitFactory->fromDuties($duties, $this->demandViewFactory->forSnapshot($snapshot));

        $candidates = $snapshot->getMembers()->toArray();
        usort($candidates, static fn ($a, $b): int => (string) $a->getSourceTeamMemberStableId() <=> (string) $b->getSourceTeamMemberStableId());

        // Grouped by person once, not filtered again for every (unit, candidate) pair.
        $commitmentsByUser = [];
        if ($withFrozenExternalCommitments) {
            foreach ($snapshot->getExternalCommitments() as $commitment) {
                $commitmentsByUser[(string) $commitment->getSourceUserStableId()][] = $commitment;
            }
        }

        $entries = [];
        foreach ($dutyUnits as $dutyUnit) {
            foreach ($candidates as $member) {
                $entries[$dutyUnit->getStableKey()][(string) $member->getSourceTeamMemberStableId()] = $this->eligibilityService->evaluate(
                    $snapshot,
                    $dutyUnit,
                    $member,
                    $commitmentsByUser[(string) $member->getSourceUserStableId()] ?? [],
                );
            }
        }

        return new EligibilityMatrix($dutyUnits, $candidates, $entries);
    }
}
