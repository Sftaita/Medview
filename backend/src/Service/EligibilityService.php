<?php

declare(strict_types=1);

namespace App\Service;

use App\Eligibility\DutyUnit;
use App\Eligibility\EligibilityExclusion;
use App\Eligibility\EligibilityResult;
use App\Eligibility\ExclusionReason;
use App\Entity\PlanningSnapshot;
use App\Entity\PlanningSnapshotMember;
use App\Entity\UserAvailabilityType;

/**
 * Answers, deterministically and from the frozen snapshot alone, whether
 * one PlanningSnapshotMember is eligible for one DutyUnit — the
 * Eligibility phase of docs/allocation-algorithm.md §2's "Éligibilité
 * d'abord, optimisation ensuite, explication enfin". Never reads live
 * data: a historical generation must always be interpreted from the state
 * frozen at snapshot time (docs/planning-generation.md).
 *
 * Deliberately narrow — see docs/eligibility.md for the full list of
 * ExclusionReason values this service does not yet compute (missing data
 * or rules not yet modeled) and why each one is deferred rather than
 * faked.
 */
final class EligibilityService
{
    public function evaluate(PlanningSnapshot $snapshot, DutyUnit $dutyUnit, PlanningSnapshotMember $member): EligibilityResult
    {
        if ($member->getSnapshot() !== $snapshot) {
            throw new \InvalidArgumentException('The given PlanningSnapshotMember does not belong to the given PlanningSnapshot.');
        }

        $structuralOpportunity = true;
        $preferred = false;
        $exclusions = [];

        if (!$member->isActive()) {
            $exclusions[] = new EligibilityExclusion(ExclusionReason::USER_INACTIVE);
            $structuralOpportunity = false;
        }

        $membershipAffectedDutyStableIds = [];
        // Component-level UNAVAILABLE/NON_PARTICIPATION causes, collected
        // before any GROUP_UNAVAILABLE wrapping decision — see below.
        $wrappableCauses = [];

        foreach ($dutyUnit->getDuties() as $duty) {
            if (!$member->coversLocalDate($duty->getLocalDate())) {
                $membershipAffectedDutyStableIds[] = (string) $duty->getStableId();
                $structuralOpportunity = false;
                // A date outside the membership window makes checking
                // availability/non-participation for it meaningless.
                continue;
            }

            foreach ($member->getAvailabilityPeriods() as $period) {
                if (!$period->overlapsWith($duty)) {
                    continue;
                }

                if (UserAvailabilityType::PREFER_DUTY === $period->getType()) {
                    // Never an exclusion, never touches structuralOpportunity
                    // — resistance-to-gaming rule (docs/availability.md §2).
                    $preferred = true;
                    continue;
                }

                $wrappableCauses[] = [
                    'dutyStableId' => (string) $duty->getStableId(),
                    'reason' => ExclusionReason::UNAVAILABLE,
                    'sourceAvailabilityStableId' => (string) $period->getSourceAvailabilityStableId(),
                ];
            }

            foreach ($member->getNonParticipationPeriods() as $period) {
                if (!$period->overlapsWith($duty)) {
                    continue;
                }

                $wrappableCauses[] = [
                    'dutyStableId' => (string) $duty->getStableId(),
                    'reason' => ExclusionReason::NON_PARTICIPATION,
                    'sourceNonParticipationStableId' => (string) $period->getSourceNonParticipationStableId(),
                ];
                $structuralOpportunity = false;
            }
        }

        if ([] !== $membershipAffectedDutyStableIds) {
            $exclusions[] = new EligibilityExclusion(ExclusionReason::MEMBERSHIP_OUT_OF_RANGE, [
                'affectedDutyStableIds' => $membershipAffectedDutyStableIds,
                'membershipStart' => $member->getMembershipStart()->format('Y-m-d'),
                'membershipEnd' => $member->getMembershipEnd()?->format('Y-m-d'),
            ]);
        }

        if ([] !== $wrappableCauses) {
            if ($dutyUnit->isGrouped()) {
                // docs/allocation-algorithm.md §3: GROUP_UNAVAILABLE is a
                // derived reason — "un membre du DutyGroupInstance exclu
                // exclut le groupe entier". The root cause(s) stay in
                // $context for audit, never lost.
                $exclusions[] = new EligibilityExclusion(ExclusionReason::GROUP_UNAVAILABLE, [
                    'rootCauses' => array_map(
                        static fn (array $cause): array => [...$cause, 'reason' => $cause['reason']->value],
                        $wrappableCauses,
                    ),
                ]);
            } else {
                foreach ($wrappableCauses as $cause) {
                    $reason = $cause['reason'];
                    unset($cause['reason']);
                    $exclusions[] = new EligibilityExclusion($reason, $cause);
                }
            }
        }

        return new EligibilityResult($exclusions, $structuralOpportunity, $preferred);
    }
}
