<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\PlanningSnapshot;
use App\Entity\PlanningSnapshotAvailabilityPeriod;
use App\Entity\PlanningSnapshotDemandDecision;
use App\Entity\PlanningSnapshotDemandTrigger;
use App\Entity\PlanningSnapshotExternalCommitment;
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
 * - The snapshot's `externalCommitments` (docs/decisions.md D161) — the
 *   duties its people hold on the lines solved before this one, read by
 *   `EligibilityService` for the cross-line exclusions: every field the
 *   check uses (person, interval, the owning generation's rest thresholds)
 *   plus the stable ids that identify the commitment.
 * - A conditional line's frozen demand (docs/decisions.md D164): the
 *   frozen policy (stable id, version, source line, source generation,
 *   every trigger) and every demand decision (duty, source duty, weekday,
 *   source holder, trigger, day reason, required, reason), plus each
 *   conditional duty's `coverageSource` — the whole reason the problem has
 *   the units it has.
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
        return hash('sha256', json_encode($this->canonicalForm($snapshot), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * Exactly what is hashed — public so its shape can be audited and
     * tested (e.g. that a snapshot without conditional demand keeps its
     * pre-D164 form, docs/decisions.md D164). Never persisted.
     *
     * @return array<string, mixed>
     */
    public function canonicalForm(PlanningSnapshot $snapshot): array
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

        // Only when there is something to hash: a snapshot without any cross-line commitment (every
        // snapshot taken before D161, every single-line Planning) keeps exactly the hash it always had.
        $externalCommitments = $this->canonicalizeExternalCommitments($snapshot);
        if ([] !== $externalCommitments) {
            $canonical['externalCommitments'] = $externalCommitments;
        }

        // Same rule (D164): only a conditional line's snapshot has a frozen demand; every other hash is unchanged.
        $demandPolicy = $snapshot->getDemandPolicy();
        if (null !== $demandPolicy) {
            $canonical['demand'] = $this->canonicalizeDemand($snapshot);
        }

        return $canonical;
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
                'startsAt' => self::instant($p->getStartsAt()),
                'endsAt' => self::instant($p->getEndsAt()),
            ], $availabilityPeriods),
            'nonParticipationPeriods' => array_map(static fn (PlanningSnapshotNonParticipationPeriod $p): array => [
                'sourceNonParticipationStableId' => (string) $p->getSourceNonParticipationStableId(),
                'startsAt' => self::instant($p->getStartsAt()),
                'endsAt' => self::instant($p->getEndsAt()),
            ], $nonParticipationPeriods),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function canonicalizeExternalCommitments(PlanningSnapshot $snapshot): array
    {
        $commitments = $snapshot->getExternalCommitments()->toArray();
        usort($commitments, static fn (PlanningSnapshotExternalCommitment $a, PlanningSnapshotExternalCommitment $b): int => [(string) $a->getSourceUserStableId(), (string) $a->getSourceDutyStableId()] <=> [(string) $b->getSourceUserStableId(), (string) $b->getSourceDutyStableId()]);

        return array_map(static fn (PlanningSnapshotExternalCommitment $c): array => [
            'sourceUserStableId' => (string) $c->getSourceUserStableId(),
            'sourceLineStableId' => (string) $c->getSourceLineStableId(),
            'sourceGenerationStableId' => (string) $c->getSourceGenerationStableId(),
            'sourceDutyStableId' => (string) $c->getSourceDutyStableId(),
            'startsAt' => self::instant($c->getStartsAt()),
            'endsAt' => self::instant($c->getEndsAt()),
            'sourceLegalMinRestHours' => $c->getSourceLegalMinRestHours(),
            'sourceTeamMinRestHours' => $c->getSourceTeamMinRestHours(),
        ], $commitments);
    }

    /**
     * One canonical text per instant, whatever the timezone of the PHP
     * object carrying it: always UTC. A Duty materialized in the very
     * process that solves carries its team's zone (+01:00), the same Duty
     * reloaded from PostgreSQL carries the session's (UTC) — the same
     * instant, which must never hash differently (a real bug found while
     * building docs/decisions.md D164: a conditional line's duties are
     * materialized by the worker's own preflight, so its stored hash did
     * not match a recomputation). UTC is what a reloaded instant already
     * formats to, so the hash of every snapshot computed from reloaded data
     * is unchanged.
     */
    private static function instant(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalizeDemand(PlanningSnapshot $snapshot): array
    {
        $policy = $snapshot->getDemandPolicy();
        $triggers = $policy->getTriggers()->toArray();
        usort($triggers, static fn (PlanningSnapshotDemandTrigger $a, PlanningSnapshotDemandTrigger $b): int => (string) $a->getUserStableId() <=> (string) $b->getUserStableId());
        $decisions = $snapshot->getDemandDecisions()->toArray();
        usort($decisions, static fn (PlanningSnapshotDemandDecision $a, PlanningSnapshotDemandDecision $b): int => (string) $a->getDuty()->getStableId() <=> (string) $b->getDuty()->getStableId());

        return [
            'policyStableId' => (string) $policy->getPolicyStableId(),
            'policyVersion' => $policy->getPolicyVersion(),
            'sourceLineStableId' => (string) $policy->getSourceLineStableId(),
            'sourceGenerationStableId' => (string) $policy->getSourceGenerationStableId(),
            'triggers' => array_map(static fn (PlanningSnapshotDemandTrigger $t): array => [
                'triggerStableId' => (string) $t->getTriggerStableId(),
                'userStableId' => (string) $t->getUserStableId(),
                'weekdays' => array_map(static fn ($w): string => $w->value, $t->getWeekdays()),
                'increment' => $t->getIncrement(),
            ], $triggers),
            'decisions' => array_map(static fn (PlanningSnapshotDemandDecision $d): array => [
                'dutyStableId' => (string) $d->getDuty()->getStableId(),
                'sourceDutyStableId' => (string) $d->getSourceDutyStableId(),
                'weekday' => $d->getWeekday()->value,
                'sourceUserStableId' => null !== $d->getSourceUserStableId() ? (string) $d->getSourceUserStableId() : null,
                'triggerStableId' => null !== $d->getTriggerStableId() ? (string) $d->getTriggerStableId() : null,
                'dayReason' => $d->getDayReason()->value,
                'required' => $d->getRequired(),
                'reason' => $d->getReason()->value,
            ], $decisions),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalizeDuty(Duty $duty): array
    {
        $canonical = [
            'stableId' => (string) $duty->getStableId(),
            'startsAt' => self::instant($duty->getStartsAt()),
            'endsAt' => self::instant($duty->getEndsAt()),
            'dutyTypeStableId' => (string) $duty->getDutyType()->getStableId(),
            'demandType' => $duty->getDemandType()->value,
            'criticality' => $duty->getCriticality()->value,
            'groupInstanceStableId' => null !== $duty->getGroupInstance() ? (string) $duty->getGroupInstance()->getStableId() : null,
            // docs/decisions.md D136: now consumed by DimensionMembershipCalculator/
            // RequiredDemandBuilder/EffectiveExposureService via
            // Duty::getAllocationFamily() — must enter the hash under the same rule
            // as every other field on this list ("only data actually consumed
            // enters the hash").
            'allocationFamilyStableId' => null !== $duty->getAllocationFamily() ? (string) $duty->getAllocationFamily()->getStableId() : null,
        ];

        // docs/decisions.md D164: a conditional duty's coverage source decides its demand — hashed only when present,
        // so every existing (non-conditional) duty keeps exactly the canonical form it always had.
        if (null !== $duty->getCoverageSource()) {
            $canonical['coverageSourceStableId'] = (string) $duty->getCoverageSource()->getStableId();
        }

        return $canonical;
    }
}
