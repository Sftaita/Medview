<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\DutyDemandType;
use App\Entity\DutyPattern;
use App\Entity\PlanningPeriod;
use App\Repository\DutyPatternRepository;
use App\Repository\DutyRepository;
use App\Repository\PlanningLineDemandPolicyRepository;
use App\Repository\PlanningLineRepository;

/**
 * Materializes a PlanningPeriod's Duty calendar from its team's active
 * weekly structure (docs/decisions.md D136) — the pipeline
 * `docs/week-structure.md` §4 always described as "à venir" and that never
 * actually existed in production before this lot (every previous Duty seen
 * in this codebase was created directly by a test or a throwaway script).
 *
 * Each active DutyPattern (docs/decisions.md D136, WeekStructureService) is
 * re-anchored on the Monday of every calendar week the period covers —
 * `dayOffset` 0..6 read as Monday..Sunday of *that* week, never the
 * pattern's original creation date. A one-component pattern (a "solo" day)
 * materializes a standalone Duty; a multi-component pattern (a "block")
 * materializes one atomic DutyGroupInstance, exactly the two paths
 * `DutyMaterializationService` already exposed — no new materialization
 * primitive was needed.
 *
 * Whole-calendar-day duties only (00:00 → next day's 00:00): no shift
 * start/end time is asked for anywhere in the weekly-structure spec, and
 * `DutyType` carries none today — guessing one would be exactly the kind
 * of invented data this codebase refuses (docs/fairness.md §3's own
 * standard). A future lot can add configurable shift times to `DutyType`
 * without touching this service's anchoring logic.
 *
 * **Idempotency is scoped per calendar day, never per pattern**
 * (`DutyRepository::existsForPeriodAndLocalDate()`, docs/decisions.md
 * D136) — a real bug found and fixed while building this lot's UAT: an
 * earlier version checked "does *this* pattern already have an occurrence
 * for this date", which let a structure change *before any real
 * generation ever ran* materialize a second, conflicting Duty for a day a
 * now-retired pattern had already produced. Once *any* Duty exists for a
 * given calendar day of this period — from any pattern, current or
 * retired — that day is permanently decided; a later structure change only
 * ever affects days nothing has materialized yet. `Duty`/`DutyGroupInstance`
 * rows are never mutated or deleted once created (docs/planning-domain.md
 * §14), so this is a safe, permanent fact once true.
 *
 * A block whose constituent days do not *all* fall inside
 * `[planningPeriod.startsAt, planningPeriod.endsAt)`, or where *any* single
 * day is already claimed by an existing Duty, is skipped entirely for that
 * week — a DutyGroupInstance is atomic (docs/allocation-algorithm.md §9),
 * so a partial block is never materialized; a solo day simply checks its
 * own single date.
 *
 * **Conditional line** (docs/decisions.md D163): the line keeps its own
 * weekly structure (days where a reinforcement may exist, blocks,
 * exclusions); every duty it materializes is CONDITIONAL and linked,
 * explicitly and for good, to its coverage source — THE duty of the
 * policy's source line on the same calendar day. That correspondence comes
 * from the two lines' calendars and the policy's source line, never from
 * the current assignments. No such duty → no reinforcement possible that
 * day; several → ambiguous: in both cases the day (for a block, the whole
 * occurrence) is not materialized and reported (CoverageSourceAnomaly),
 * never resolved by guessing. The source line must therefore be
 * materialized first — PlanningLineOrder puts sources first, and the
 * generation preflight follows that order.
 */
final class WeeklyDutyCalendarService
{
    public function __construct(
        private readonly DutyPatternRepository $patternRepository,
        private readonly DutyMaterializationService $materializationService,
        private readonly DutyRepository $dutyRepository,
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningLineDemandPolicyRepository $policyRepository,
    ) {
    }

    /**
     * Materializes every missing week from the period's own start up to
     * $until (clamped to the period's own end — never beyond what the
     * period actually covers).
     *
     * @return list<CoverageSourceAnomaly> the days of a conditional line left unmaterialized because their coverage
     *                                     source is missing or ambiguous (always empty for any other line)
     */
    public function ensureMaterialized(PlanningPeriod $planningPeriod, \DateTimeImmutable $until): array
    {
        $patterns = $this->patternRepository->findActiveRecurringByTeam($planningPeriod->getTeam());
        if ([] === $patterns) {
            return [];
        }

        $periodEnd = min($until, $planningPeriod->getEndsAt());
        if ($periodEnd <= $planningPeriod->getStartsAt()) {
            return [];
        }

        $sourcePeriod = $this->sourcePeriodOf($planningPeriod);
        $anomalies = [];
        $monday = $this->mondayOnOrBefore($planningPeriod->getStartsAt());
        while ($monday < $periodEnd) {
            foreach ($patterns as $pattern) {
                array_push($anomalies, ...$this->materializeOccurrence($planningPeriod, $pattern, $monday, $sourcePeriod));
            }
            $monday = $monday->modify('+7 days');
        }

        usort($anomalies, static fn (CoverageSourceAnomaly $a, CoverageSourceAnomaly $b): int => $a->localDate <=> $b->localDate);

        return $anomalies;
    }

    /** The source line's period when $period's line is conditional (D163), null otherwise. */
    private function sourcePeriodOf(PlanningPeriod $period): ?PlanningPeriod
    {
        $line = $this->lineRepository->findOneByPlanningPeriod($period);
        $policy = null !== $line ? $this->policyRepository->findActiveForLine($line) : null;

        return null !== $policy && $policy->getMode()->isConditional() ? $policy->getSourceLine()?->getPlanningPeriod() : null;
    }

    /**
     * @return list<CoverageSourceAnomaly>
     */
    private function materializeOccurrence(PlanningPeriod $period, DutyPattern $pattern, \DateTimeImmutable $monday, ?PlanningPeriod $sourcePeriod): array
    {
        if (1 === \count($pattern->getComponents())) {
            return $this->materializeSolo($period, $pattern, $monday, $sourcePeriod);
        }

        return $this->materializeBlock($period, $pattern, $monday, $sourcePeriod);
    }

    /**
     * @return list<CoverageSourceAnomaly>
     */
    private function materializeSolo(PlanningPeriod $period, DutyPattern $pattern, \DateTimeImmutable $monday, ?PlanningPeriod $sourcePeriod): array
    {
        $component = $pattern->getComponents()->first();
        if (false === $component) {
            return [];
        }

        $day = $monday->modify(sprintf('+%d days', $component->getDayOffset()));
        if ($day < $period->getStartsAt() || $day >= $period->getEndsAt()) {
            return [];
        }

        if ($this->dutyRepository->existsForPeriodAndLocalDate($period, $day)) {
            return [];
        }

        $coverageSource = null;
        if (null !== $sourcePeriod) {
            $coverageSource = $this->coverageSourceOn($sourcePeriod, $day);
            if ($coverageSource instanceof CoverageSourceAnomaly) {
                return [$coverageSource];
            }
        }

        $this->materializationService->createStandaloneDuty(
            $period,
            $component->getDutyType(),
            $day,
            $day->modify('+1 day'),
            null !== $coverageSource ? DutyDemandType::CONDITIONAL : DutyDemandType::REQUIRED,
            pattern: $pattern,
            coverageSource: $coverageSource,
        );

        return [];
    }

    /**
     * @return list<CoverageSourceAnomaly>
     */
    private function materializeBlock(PlanningPeriod $period, DutyPattern $pattern, \DateTimeImmutable $monday, ?PlanningPeriod $sourcePeriod): array
    {
        $componentLocalTimes = [];
        $coverageSources = [];
        $anomalies = [];
        foreach ($pattern->getComponents() as $component) {
            $day = $monday->modify(sprintf('+%d days', $component->getDayOffset()));
            if ($day < $period->getStartsAt() || $day >= $period->getEndsAt()) {
                return [];
            }

            if ($this->dutyRepository->existsForPeriodAndLocalDate($period, $day)) {
                return [];
            }

            $componentLocalTimes[$component->getDayOffset()] = [$day, $day->modify('+1 day')];

            if (null !== $sourcePeriod) {
                $source = $this->coverageSourceOn($sourcePeriod, $day);
                if ($source instanceof CoverageSourceAnomaly) {
                    $anomalies[] = $source;
                } else {
                    $coverageSources[$component->getDayOffset()] = $source;
                }
            }
        }

        // A block is atomic: one day without a unique coverage source leaves the whole occurrence unmaterialized.
        if ([] !== $anomalies) {
            return $anomalies;
        }

        $this->materializationService->materializeGroup(
            $period,
            $pattern,
            $monday,
            $componentLocalTimes,
            null !== $sourcePeriod ? DutyDemandType::CONDITIONAL : DutyDemandType::REQUIRED,
            coverageSourcesByOffset: $coverageSources,
        );

        return [];
    }

    /** THE duty of the source line that calendar day — or why there is none to pick. */
    private function coverageSourceOn(PlanningPeriod $sourcePeriod, \DateTimeImmutable $day): Duty|CoverageSourceAnomaly
    {
        $candidates = $this->dutyRepository->findByPeriodAndLocalDate($sourcePeriod, $day);

        return match (\count($candidates)) {
            0 => new CoverageSourceAnomaly($day, CoverageSourceAnomaly::MISSING),
            1 => $candidates[0],
            default => new CoverageSourceAnomaly($day, CoverageSourceAnomaly::AMBIGUOUS),
        };
    }

    private function mondayOnOrBefore(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $isoWeekday = (int) $date->format('N'); // 1 = Monday .. 7 = Sunday

        return $date->modify(sprintf('-%d days', $isoWeekday - 1));
    }
}
