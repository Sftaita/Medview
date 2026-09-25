<?php

declare(strict_types=1);

namespace App\Service;

use App\Eligibility\DutyUnit;
use App\Fairness\DutyUnitSpan;
use App\Fairness\SpacingPairPenalty;

/**
 * `SPACING_SCORE`'s real metric (docs/decisions.md D139, closing the gap
 * audited there: the phase existed structurally since D087 but was always
 * `NEUTRAL` — no data ever backed it). A **soft** signal only: this class
 * never removes an eligible (unit, candidate) edge, never turns a
 * combination infeasible — it only tells `CpSatPayloadBuilder` which pairs
 * of `DutyUnit`s should cost something *if* the same candidate ends up
 * with both, among solutions that already tie on every fairness phase.
 *
 * Reasons in **DutyUnit**, never in constituent `Duty` (§4 of this lot's
 * spec) — an atomic Ven/Sam/Dim block is one span, never three days
 * penalized against each other (`DutyUnitSpan::fromDutyUnit()` already
 * enforces this).
 *
 * Two independent, additive sources of penalty for one candidate holding
 * both units of a pair:
 *
 * 1. **Raw calendar adjacency** (§6/§8) — `freeDays` between the two
 *    spans (`DutyUnitSpan::freeDaysUntil()`), tiered and strictly
 *    decreasing, zero from 3 free days on. This alone also covers "temporal
 *    concentration" (§8): two units close together in *any* candidate's
 *    own timeline are exactly a low-freeDays pair, no separate density
 *    metric needed (deliberately not built — YAGNI, per this lot's own
 *    instruction not to over-engineer this).
 * 2. **Same-family repetition** (§7) — two `DutyUnit`s of the *same*
 *    `AllocationFamily`, consecutive in that family's own chronological
 *    occurrence sequence (no other unit of that family between them).
 *    Purely ordinal/structural — never assumes "7 days" or any hardcoded
 *    cadence, never reads a family's name (`WEEK_END` stays exactly as
 *    opaque here as `AllocationFamily` is everywhere else in this
 *    codebase since D136).
 *
 * Every weight below is a **local, ordinal** choice for this one phase's
 * own internal ranking — never a business/legal constant (nothing here
 * claims to represent a real duration or rule, unlike e.g.
 * `RestPolicyOptions.legalMinRestHours`, D036) — and never mixed with a
 * fairness dimension's own scale (D032: no global weighted score, ever).
 */
final class SpacingPenaltyCalculator
{
    /** Strictly decreasing, zero (no term at all) from 3 free days on. */
    private const array FREE_DAYS_TIER_PENALTY = [0 => 100, 1 => 60, 2 => 20];
    private const int MAX_FREE_DAYS_CONSIDERED = 2;
    private const int SAME_FAMILY_CONSECUTIVE_PENALTY = 40;

    /**
     * @param list<DutyUnit> $dutyUnits every unit of the problem (required + optional), any order
     *
     * @return list<SpacingPairPenalty> deduplicated, one entry per penalized pair
     */
    public function buildPairPenalties(array $dutyUnits): array
    {
        $spans = array_map(DutyUnitSpan::fromDutyUnit(...), $dutyUnits);
        usort($spans, static fn (DutyUnitSpan $a, DutyUnitSpan $b): int => $a->startDate <=> $b->startDate);

        /** @var array<string, int> $penaltyByPairKey */
        $penaltyByPairKey = [];

        $this->addFreeDaysPenalties($spans, $penaltyByPairKey);
        $this->addSameFamilyRepetitionPenalties($spans, $penaltyByPairKey);

        $result = [];
        foreach ($penaltyByPairKey as $pairKey => $penalty) {
            [$unitAKey, $unitBKey] = explode('|', $pairKey, 2);
            $result[] = new SpacingPairPenalty($unitAKey, $unitBKey, $penalty);
        }

        return $result;
    }

    /**
     * @param list<DutyUnitSpan> $spans            sorted by startDate
     * @param array<string, int> $penaltyByPairKey
     */
    private function addFreeDaysPenalties(array $spans, array &$penaltyByPairKey): void
    {
        $count = \count($spans);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                $freeDays = $spans[$i]->freeDaysUntil($spans[$j]);
                if ($freeDays > self::MAX_FREE_DAYS_CONSIDERED) {
                    // Spans are sorted by startDate: every later $j only
                    // grows farther from $i — safe to stop this inner loop
                    // entirely (the bounded-horizon window this lot's
                    // performance requirement asks for).
                    break;
                }
                if ($freeDays < 0) {
                    // Structurally impossible for one real candidate
                    // (AssignmentConflict/CONFLICT already forbids
                    // overlapping units) — never penalized as if adjacent.
                    continue;
                }
                $this->addPenalty($penaltyByPairKey, $spans[$i]->dutyUnitStableKey, $spans[$j]->dutyUnitStableKey, self::FREE_DAYS_TIER_PENALTY[$freeDays]);
            }
        }
    }

    /**
     * @param list<DutyUnitSpan> $spans            sorted by startDate
     * @param array<string, int> $penaltyByPairKey
     */
    private function addSameFamilyRepetitionPenalties(array $spans, array &$penaltyByPairKey): void
    {
        /** @var array<string, list<DutyUnitSpan>> $byFamily */
        $byFamily = [];
        foreach ($spans as $span) {
            if (null !== $span->allocationFamilyStableId) {
                // $spans is already startDate-sorted; grouping preserves
                // that relative order (stable) — each per-family list is
                // therefore already that family's own chronological
                // occurrence sequence, no re-sort needed.
                $byFamily[$span->allocationFamilyStableId][] = $span;
            }
        }

        foreach ($byFamily as $familySpans) {
            for ($i = 0, $last = \count($familySpans) - 1; $i < $last; ++$i) {
                $this->addPenalty($penaltyByPairKey, $familySpans[$i]->dutyUnitStableKey, $familySpans[$i + 1]->dutyUnitStableKey, self::SAME_FAMILY_CONSECUTIVE_PENALTY);
            }
        }
    }

    /**
     * @param array<string, int> $penaltyByPairKey
     */
    private function addPenalty(array &$penaltyByPairKey, string $unitAKey, string $unitBKey, int $penalty): void
    {
        $pairKey = $unitAKey.'|'.$unitBKey;
        $penaltyByPairKey[$pairKey] = ($penaltyByPairKey[$pairKey] ?? 0) + $penalty;
    }
}
