<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\PlanningSnapshot;
use App\Entity\PlanningSnapshotAvailabilityPeriod;
use App\Entity\PlanningSnapshotMember;
use App\Entity\PlanningSnapshotNonParticipationPeriod;
use App\Entity\PlanningSnapshotParticipationPeriod;
use App\Repository\DutyRepository;

/**
 * Computes `snapshotHash = SHA-256(canonicalSnapshotJson)`
 * (docs/allocation-algorithm.md §14, docs/decisions.md D106) — the audit
 * identity of one solve attempt, and the ingredient
 * `PlanningGenerationService::generate()` compares before/after a solve to
 * detect real, relevant data drift (docs/decisions.md D106,
 * `StalePlanningGenerationDataException`).
 *
 * **Only data actually consumed by `EligibilityMatrixBuilder`/
 * `FairnessContextBuilder`/`OptimizationProblemBuilder`/
 * `AssignmentConflictAnalyzer` enters the hash — never the whole snapshot
 * entity blindly copied**:
 *
 * - `RestPolicyOptions` (`AssignmentConflictAnalyzer`).
 * - Each `PlanningSnapshotMember`'s `sourceTeamMemberStableId`/
 *   `sourceUserStableId`/`membershipStart`/`membershipEnd`/`active`
 *   (`EligibilityService`) — `$role` is excluded: its own docblock states
 *   it is "for audit/display only... never read by the future engine".
 * - Each member's `participationPeriods` (`validFrom`/`validTo`/the raw
 *   decimal `participationFactor` string) — consumed by
 *   `EffectiveExposureService` when building `FairnessContext`, even
 *   though `EligibilityService` itself does not read them.
 *   `$changeReason` is excluded — an audit tag, never read by any
 *   computation.
 * - Each member's `availabilityPeriods`/`nonParticipationPeriods` (the
 *   fields `overlapsWith()` actually compares) — each entity's own
 *   `$sourceCreatedAt`/`$sourceUpdatedAt` are excluded as the "technical
 *   timestamps without business value" §14 already rules out.
 * - The **live** `Duty` list of the `PlanningPeriod` (never duplicated
 *   into the snapshot, docs/planning-generation.md §4 — this is the one
 *   genuinely mutable input a synchronous solve can still see drift:
 *   nothing stops a new `Duty` being added mid-solve).
 *
 * **Explicitly excluded**: `PlanningSnapshotRuleSet.configuration` —
 * audited for this lot and confirmed unread by any of the four consumers
 * above (`docs/decisions.md` D104: none of MAX_DUTIES/MAX_WEEKENDS/
 * MAX_CONSECUTIVE_NIGHTS are wired, and D105 moved `TEAM_MIN_REST` off the
 * RuleSet entirely) — including it would hash data that provably has zero
 * effect on the computed result today.
 *
 * Not audited as mutable and therefore not covered by this hash: catalog
 * data read live but not through `PlanningSnapshot`/`DutyRepository`
 * (e.g. `DutyType.workloadValue`, if a future lot ever makes it editable)
 * — a documented limitation, not a silent gap (see the Lot 6E final
 * report's "dette réelle").
 */
final class SnapshotHasher
{
    public function __construct(private readonly DutyRepository $dutyRepository)
    {
    }

    public function hash(PlanningSnapshot $snapshot): string
    {
        $generation = $snapshot->getGeneration();
        $restPolicy = $generation->getRestPolicy();

        $members = $snapshot->getMembers()->toArray();
        usort($members, static fn (PlanningSnapshotMember $a, PlanningSnapshotMember $b): int => (string) $a->getSourceTeamMemberStableId() <=> (string) $b->getSourceTeamMemberStableId());

        $duties = $this->dutyRepository->findByPlanningPeriod($generation->getPlanningPeriod());
        usort($duties, static fn (Duty $a, Duty $b): int => (string) $a->getStableId() <=> (string) $b->getStableId());

        $canonical = [
            'restPolicy' => [
                'legalMinRestEnabled' => $restPolicy->legalMinRestEnabled,
                'legalMinRestHours' => $restPolicy->legalMinRestHours,
                'teamMinRestEnabled' => $restPolicy->teamMinRestEnabled,
                'teamMinRestHours' => $restPolicy->teamMinRestHours,
            ],
            'members' => array_map($this->canonicalizeMember(...), $members),
            'duties' => array_map($this->canonicalizeDuty(...), $duties),
        ];

        return hash('sha256', json_encode($canonical, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalizeMember(PlanningSnapshotMember $member): array
    {
        $participationPeriods = $member->getParticipationPeriods()->toArray();
        usort($participationPeriods, static fn (PlanningSnapshotParticipationPeriod $a, PlanningSnapshotParticipationPeriod $b): int => $a->getValidFrom() <=> $b->getValidFrom());

        $availabilityPeriods = $member->getAvailabilityPeriods()->toArray();
        usort($availabilityPeriods, static fn (PlanningSnapshotAvailabilityPeriod $a, PlanningSnapshotAvailabilityPeriod $b): int => (string) $a->getSourceAvailabilityStableId() <=> (string) $b->getSourceAvailabilityStableId());

        $nonParticipationPeriods = $member->getNonParticipationPeriods()->toArray();
        usort($nonParticipationPeriods, static fn (PlanningSnapshotNonParticipationPeriod $a, PlanningSnapshotNonParticipationPeriod $b): int => (string) $a->getSourceNonParticipationStableId() <=> (string) $b->getSourceNonParticipationStableId());

        return [
            'sourceTeamMemberStableId' => (string) $member->getSourceTeamMemberStableId(),
            'sourceUserStableId' => (string) $member->getSourceUserStableId(),
            'membershipStart' => $member->getMembershipStart()->format('Y-m-d'),
            'membershipEnd' => $member->getMembershipEnd()?->format('Y-m-d'),
            'active' => $member->isActive(),
            'participationPeriods' => array_map(static fn (PlanningSnapshotParticipationPeriod $p): array => [
                'validFrom' => $p->getValidFrom()->format('Y-m-d'),
                'validTo' => $p->getValidTo()?->format('Y-m-d'),
                'participationFactor' => $p->getParticipationFactorRaw(),
            ], $participationPeriods),
            'availabilityPeriods' => array_map(static fn (PlanningSnapshotAvailabilityPeriod $p): array => [
                'sourceAvailabilityStableId' => (string) $p->getSourceAvailabilityStableId(),
                'type' => $p->getType()->value,
                'startsAt' => $p->getStartsAt()->format(\DATE_ATOM),
                'endsAt' => $p->getEndsAt()->format(\DATE_ATOM),
            ], $availabilityPeriods),
            'nonParticipationPeriods' => array_map(static fn (PlanningSnapshotNonParticipationPeriod $p): array => [
                'sourceNonParticipationStableId' => (string) $p->getSourceNonParticipationStableId(),
                'startsAt' => $p->getStartsAt()->format(\DATE_ATOM),
                'endsAt' => $p->getEndsAt()->format(\DATE_ATOM),
            ], $nonParticipationPeriods),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalizeDuty(Duty $duty): array
    {
        return [
            'stableId' => (string) $duty->getStableId(),
            'startsAt' => $duty->getStartsAt()->format(\DATE_ATOM),
            'endsAt' => $duty->getEndsAt()->format(\DATE_ATOM),
            'dutyTypeStableId' => (string) $duty->getDutyType()->getStableId(),
            'demandType' => $duty->getDemandType()->value,
            'criticality' => $duty->getCriticality()->value,
            'groupInstanceStableId' => null !== $duty->getGroupInstance() ? (string) $duty->getGroupInstance()->getStableId() : null,
        ];
    }
}
