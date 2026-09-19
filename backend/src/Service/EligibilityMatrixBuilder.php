<?php

declare(strict_types=1);

namespace App\Service;

use App\Eligibility\DutyGroupUnit;
use App\Eligibility\DutyUnit;
use App\Eligibility\EligibilityMatrix;
use App\Eligibility\SingleDutyUnit;
use App\Entity\Duty;
use App\Entity\DutyGroupInstance;
use App\Entity\PlanningSnapshot;
use App\Repository\DutyRepository;

/**
 * Builds the full DutyUnit × PlanningSnapshotMember EligibilityMatrix for
 * one snapshot (docs/eligibility.md §Matrice) — every standalone Duty of
 * the PlanningPeriod becomes a SingleDutyUnit, every DutyGroupInstance's
 * constituent Duty rows are folded into one DutyGroupUnit, evaluated
 * against every member captured in the snapshot.
 */
final class EligibilityMatrixBuilder
{
    public function __construct(
        private readonly DutyRepository $dutyRepository,
        private readonly EligibilityService $eligibilityService,
    ) {
    }

    public function build(PlanningSnapshot $snapshot): EligibilityMatrix
    {
        $planningPeriod = $snapshot->getGeneration()->getPlanningPeriod();
        $duties = $this->dutyRepository->findByPlanningPeriod($planningPeriod);

        $dutyUnits = [];

        /** @var array<int, list<Duty>> $groupedDuties */
        $groupedDuties = [];
        /** @var array<int, DutyGroupInstance> $groupInstances */
        $groupInstances = [];

        foreach ($duties as $duty) {
            $group = $duty->getGroupInstance();

            if (null === $group) {
                $dutyUnits[] = new SingleDutyUnit($duty);
                continue;
            }

            $groupInstances[$group->getId()] = $group;
            $groupedDuties[$group->getId()][] = $duty;
        }

        foreach ($groupedDuties as $groupId => $dutiesInGroup) {
            $dutyUnits[] = new DutyGroupUnit($groupInstances[$groupId], $dutiesInGroup);
        }

        // Deterministic regardless of the order rows came back from the
        // database (docs/eligibility.md §Déterminisme) — sorted by stable
        // business key, never by auto-increment id or insertion order.
        usort($dutyUnits, static fn (DutyUnit $a, DutyUnit $b): int => $a->getStableKey() <=> $b->getStableKey());

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
