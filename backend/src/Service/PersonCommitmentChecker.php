<?php

declare(strict_types=1);

namespace App\Service;

use App\Eligibility\CommitmentInterval;
use App\Eligibility\CommitmentViolation;
use App\Eligibility\ExclusionReason;
use App\Entity\Duty;
use App\Entity\RestPolicyOptions;

/**
 * The one rule deciding whether a block of duties is compatible with the
 * duties the same *person* already holds (docs/decisions.md D161) —
 * whether those are frozen in a snapshot (EligibilityService, generation)
 * or read live (ReassignmentCandidateService: manual reassignment,
 * completion, publication preflight).
 *
 * SELF_COVERAGE first (docs/decisions.md D163): when the person already
 * holds the coverage source of one of the block's conditional duties, they
 * would be their own reinforcement — refused whatever the timing (even if
 * the two duties did not overlap).
 *
 * Then (same order as AssignmentConflictAnalyzer, D105): an overlap, then
 * LEGAL rest, then TEAM rest; within each, a duty of the same generation
 * (same line) before one of another line.
 *
 *   same generation  → CONFLICT, LEGAL_MIN_REST, TEAM_MIN_REST — with the
 *                      edited/solved generation's own thresholds (D131);
 *   other generation → CROSS_LINE_CONFLICT, CROSS_LINE_LEGAL_MIN_REST,
 *                      CROSS_LINE_TEAM_MIN_REST — with the STRICTER of the
 *                      two generations' thresholds: each rule is on as soon
 *                      as either generation enabled it, at the larger number
 *                      of hours. A rest rule chosen for one line also
 *                      protects the person when the other line is edited,
 *                      so the check gives the same answer whichever of the
 *                      two lines is being changed.
 *
 * Instants only (Unix seconds via RestGapCalculator), never wall-clock: DST
 * never skews a gap. A gap exactly equal to the minimum is allowed (`<`).
 */
final class PersonCommitmentChecker
{
    public function __construct(private readonly RestGapCalculator $restGapCalculator)
    {
    }

    /**
     * @param list<Duty>                   $block       the duties to be taken, together (a whole DutyGroupInstance)
     * @param iterable<CommitmentInterval> $commitments what the same person already holds (the block's own duties excluded)
     * @param RestPolicyOptions            $own         the rest policy of the generation being solved/edited
     */
    public function firstViolation(array $block, iterable $commitments, RestPolicyOptions $own): ?CommitmentViolation
    {
        /** @var array<string, CommitmentViolation> $found keyed by reason, first commitment found per reason */
        $found = [];

        $coverageSourceIds = [];
        foreach ($block as $duty) {
            if (null !== $duty->getCoverageSource()) {
                $coverageSourceIds[(string) $duty->getCoverageSource()->getStableId()] = true;
            }
        }

        foreach ($commitments as $commitment) {
            if (isset($coverageSourceIds[$commitment->dutyStableId])) {
                return new CommitmentViolation(ExclusionReason::SELF_COVERAGE, $commitment);
            }

            $reason = $this->reasonFor($block, $commitment, $own);
            if (null !== $reason && !isset($found[$reason->value])) {
                $found[$reason->value] = new CommitmentViolation($reason, $commitment);
            }
        }

        foreach (self::PRECEDENCE as $reason) {
            if (isset($found[$reason->value])) {
                return $found[$reason->value];
            }
        }

        return null;
    }

    private const PRECEDENCE = [
        ExclusionReason::CONFLICT,
        ExclusionReason::CROSS_LINE_CONFLICT,
        ExclusionReason::LEGAL_MIN_REST,
        ExclusionReason::CROSS_LINE_LEGAL_MIN_REST,
        ExclusionReason::TEAM_MIN_REST,
        ExclusionReason::CROSS_LINE_TEAM_MIN_REST,
    ];

    /**
     * @param list<Duty> $block
     */
    private function reasonFor(array $block, CommitmentInterval $commitment, RestPolicyOptions $own): ?ExclusionReason
    {
        $minGapHours = null;
        foreach ($block as $duty) {
            if ($duty->getStartsAt() < $commitment->endsAt && $commitment->startsAt < $duty->getEndsAt()) {
                return $commitment->sameGeneration ? ExclusionReason::CONFLICT : ExclusionReason::CROSS_LINE_CONFLICT;
            }

            $gap = $this->restGapCalculator->gapHoursBetween($duty->getStartsAt(), $duty->getEndsAt(), $commitment->startsAt, $commitment->endsAt);
            if (null === $minGapHours || $gap < $minGapHours) {
                $minGapHours = $gap;
            }
        }

        if (null === $minGapHours) {
            return null;
        }

        if ($commitment->sameGeneration) {
            if ($own->legalMinRestEnabled && $minGapHours < $own->legalMinRestHours) {
                return ExclusionReason::LEGAL_MIN_REST;
            }
            if ($own->teamMinRestEnabled && $minGapHours < $own->teamMinRestHours) {
                return ExclusionReason::TEAM_MIN_REST;
            }

            return null;
        }

        $legal = self::stricter($own->legalMinRestEnabled ? $own->legalMinRestHours : null, $commitment->ownerLegalMinRestHours);
        if (null !== $legal && $minGapHours < $legal) {
            return ExclusionReason::CROSS_LINE_LEGAL_MIN_REST;
        }

        $team = self::stricter($own->teamMinRestEnabled ? $own->teamMinRestHours : null, $commitment->ownerTeamMinRestHours);
        if (null !== $team && $minGapHours < $team) {
            return ExclusionReason::CROSS_LINE_TEAM_MIN_REST;
        }

        return null;
    }

    /** The larger of two optional thresholds — null only when neither is set. */
    private static function stricter(?int $a, ?int $b): ?int
    {
        if (null === $a) {
            return $b;
        }

        return null === $b ? $a : max($a, $b);
    }
}
