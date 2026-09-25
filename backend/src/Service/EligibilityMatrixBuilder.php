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
    ) {
    }

    public function build(PlanningSnapshot $snapshot): EligibilityMatrix
    {
        $planningPeriod = $snapshot->getGeneration()->getPlanningPeriod();
        $duties = $this->dutyRepository->findByPlanningPeriod($planningPeriod);

        $dutyUnits = $this->dutyUnitFactory->fromDuties($duties);

        $candidates = $snapshot->getMembers()->toArray();
        usort($candidates, static fn ($a, $b): int => (string) $a->getSourceTeamMemberStableId() <=> (string) $b->getSourceTeamMemberStableId());

        $entries = [];
        foreach ($dutyUnits as $dutyUnit) {
            foreach ($candidates as $member) {
                $entries[$dutyUnit->getStableKey()][(string) $member->getSourceTeamMemberStableId()] = $this->eligibilityService->evaluate($snapshot, $dutyUnit, $member);
            }
        }

        return new EligibilityMatrix($dutyUnits, $candidates, $entries);
    }
}
