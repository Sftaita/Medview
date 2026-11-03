<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\DutyDemandType;
use App\Entity\DutyPattern;
use App\Entity\PeriodDateChange;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningPeriodStatus;
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
    private const LOCKED_STATUSES = [PlanningPeriodStatus::VALIDATED, PlanningPeriodStatus::PUBLISHED, PlanningPeriodStatus::ARCHIVED];

    public function __construct(
        private readonly DutyPatternRepository $patternRepository,
        private readonly DutyMaterializationService $materializationService,
        private readonly DutyRepository $dutyRepository,
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningLineDemandPolicyRepository $policyRepository,
        private readonly PeriodBoundaryGuard $boundaryGuard,
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
        $this->projection = null;

        return $this->run($planningPeriod, $until);
    }

    /**
     * docs/decisions.md D224 — what ensureMaterialized() WOULD create, counted
     * in memory: nothing is written, nothing is flushed. The generation
     * preview reads this; only a real launch materializes.
     */
    public function project(PlanningPeriod $planningPeriod, \DateTimeImmutable $until): ProjectedMaterialization
    {
        $this->projection = ['claimed' => [], 'duties' => 0, 'families' => []];
        try {
            $anomalies = $this->run($planningPeriod, $until);

            return new ProjectedMaterialization($this->projection['duties'], $this->projection['families'], $anomalies);
        } finally {
            $this->projection = null;
        }
    }

    /** @var array{claimed: array<string, true>, duties: int, families: array<string, int>}|null set while projecting */
    private ?array $projection = null;

    /**
     * docs/decisions.md D226 — the duties an extension of $period brings, and
     * only those: the occurrences of its CURRENT weekly structure with a day in
     * [$from, $to[. Unlike ensureMaterialized(), this works on a published
     * period: the extension is the explicit decision of a manager (I8's second
     * exception), the duties are created empty, and each one records the change
     * that created it. No day before $from is ever materialized — a day left
     * without duty inside the old dates stays as it was.
     *
     * @return list<CoverageSourceAnomaly>
     */
    public function materializeExtension(PlanningPeriod $period, \DateTimeImmutable $from, \DateTimeImmutable $to, PeriodDateChange $change): array
    {
        $known = [];
        foreach ($this->dutyRepository->findByPlanningPeriod($period) as $duty) {
            $known[(int) $duty->getId()] = true;
        }
        $this->projection = null;
        $this->window = [$from, $to];
        try {
            $anomalies = $this->run($period, $period->getEndsAt());
        } finally {
            $this->window = null;
        }
        foreach ($this->dutyRepository->findByPlanningPeriod($period) as $duty) {
            if (!isset($known[(int) $duty->getId()])) {
                $duty->markAddedBy($change);
            }
        }

        return $anomalies;
    }

    /**
     * What materializeExtension() would create if $period had the dates
     * [$startsAt, $endsAt[ — counted, nothing written. For the "Modifier les
     * dates" preview, before the period has moved.
     */
    public function countExtension(PlanningPeriod $period, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $cutConfirmed = false): int
    {
        $count = 0;
        $claimed = [];
        foreach ($this->patternRepository->findActiveRecurringByTeam($period->getTeam()) as $pattern) {
            for ($monday = $this->mondayOnOrBefore($startsAt); $monday < $endsAt; $monday = $monday->modify('+7 days')) {
                $days = [];
                foreach ($pattern->getComponents() as $component) {
                    $days[] = $monday->modify(\sprintf('+%d days', $component->getDayOffset()));
                }
                if ([] === array_filter($days, static fn (\DateTimeImmutable $day): bool => $day >= $from && $day < $to)) {
                    continue;
                }
                if (!$cutConfirmed && [] !== array_filter($days, static fn (\DateTimeImmutable $day): bool => $day < $startsAt || $day >= $endsAt)) {
                    continue;
                }
                foreach ($days as $day) {
                    if ($day < $startsAt || $day >= $endsAt || isset($claimed[$day->format('Y-m-d')]) || $this->dutyRepository->existsForPeriodAndLocalDate($period, $day)) {
                        continue;
                    }
                    $claimed[$day->format('Y-m-d')] = true;
                    ++$count;
                }
            }
        }

        return $count;
    }

    /** @var array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null set while materializing an extension */
    private ?array $window = null;

    /**
     * @return list<CoverageSourceAnomaly>
     */
    private function run(PlanningPeriod $planningPeriod, \DateTimeImmutable $until): array
    {
        // docs/planning-cycles.md I8 (lot L3): a validated, published or archived period never gets a new duty — a
        // weekly structure changed since its publication applies to the periods still to be generated only.
        // docs/decisions.md D226: the one exception, the days an explicit extension added.
        if (null === $this->window && \in_array($planningPeriod->getStatus(), self::LOCKED_STATUSES, true)) {
            return [];
        }

        $patterns = $this->patternRepository->findActiveRecurringByTeam($planningPeriod->getTeam());
        if ([] === $patterns) {
            return [];
        }

        // A conditional line whose source takes no part in this period (§5.6): no reinforcement can exist in it —
        // nothing is materialized (and never as an independent duty). The generation preflight reports it.
        if ($this->isConditional($planningPeriod) && null === $this->sourcePeriodOf($planningPeriod)) {
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
                if ($this->outsideWindow($pattern, $monday)) {
                    continue;
                }
                array_push($anomalies, ...$this->materializeOccurrence($planningPeriod, $pattern, $monday, $sourcePeriod));
            }
            $monday = $monday->modify('+7 days');
        }

        usort($anomalies, static fn (CoverageSourceAnomaly $a, CoverageSourceAnomaly $b): int => $a->localDate <=> $b->localDate);

        return $anomalies;
    }

    private function outsideWindow(DutyPattern $pattern, \DateTimeImmutable $monday): bool
    {
        if (null === $this->window) {
            return false;
        }
        foreach ($pattern->getComponents() as $component) {
            $day = $monday->modify(\sprintf('+%d days', $component->getDayOffset()));
            if ($day >= $this->window[0] && $day < $this->window[1]) {
                return false;
            }
        }

        return true;
    }

    /**
     * The source line's period IN THE SAME CYCLE when $period's line is
     * conditional (D163, docs/planning-cycles.md §5.6), null otherwise — or
     * when the source line takes no part in that cycle.
     */
    private function sourcePeriodOf(PlanningPeriod $period): ?PlanningPeriod
    {
        $line = $this->lineRepository->findOneByPlanningPeriod($period);
        $policy = null !== $line ? $this->policyRepository->findActiveForLine($line) : null;
        $cycle = $period->getCycle();

        return null !== $policy && $policy->getMode()->isConditional() && null !== $cycle ? $policy->getSourceLine()?->periodIn($cycle) : null;
    }

    private function isConditional(PlanningPeriod $period): bool
    {
        $line = $this->lineRepository->findOneByPlanningPeriod($period);

        return null !== $line && true === $this->policyRepository->findActiveForLine($line)?->getMode()->isConditional();
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

        if ($this->isTaken($period, $day)) {
            return [];
        }

        $coverageSource = null;
        if (null !== $sourcePeriod) {
            $coverageSource = $this->coverageSourceOn($sourcePeriod, $day);
            if ($coverageSource instanceof CoverageSourceAnomaly) {
                return [$coverageSource];
            }
        }

        if (null !== $this->projection) {
            $this->claim([$day], null === $coverageSource, $pattern->getFamily()?->getName());

            return [];
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
        $earlierDays = [];
        $laterDays = false;
        foreach ($pattern->getComponents() as $component) {
            $day = $monday->modify(sprintf('+%d days', $component->getDayOffset()));
            if ($day >= $period->getEndsAt()) {
                // docs/decisions.md D229: only an end the manager confirmed keeps the first days of a block.
                if (true !== $period->getCycle()?->isEndCutConfirmed()) {
                    return [];
                }
                $laterDays = true;
            }
            if ($day < $period->getStartsAt()) {
                $earlierDays[] = $day;
            }
        }
        // docs/decisions.md D192: the one exception to atomicity — an occurrence cut by a historical boundary (the end
        // of a locked previous period, where its earlier days were never materialized) gets its days of this period
        // as a remainder block. Any other occurrence crossing the start is never materialized, as before.
        $remainder = [] !== $earlierDays;
        if ($remainder && !$this->boundaryGuard->allowsRemainder($period, $earlierDays)) {
            return [];
        }
        $remainder = $remainder || $laterDays;

        foreach ($pattern->getComponents() as $component) {
            $day = $monday->modify(sprintf('+%d days', $component->getDayOffset()));
            if ($day < $period->getStartsAt() || $day >= $period->getEndsAt()) {
                continue;
            }

            if ($this->isTaken($period, $day)) {
                // docs/decisions.md D229: extending a period ended inside a block: its first days exist, the rest is added.
                if (null === $this->window) {
                    return [];
                }
                $remainder = true;
                continue;
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
        if ([] === $componentLocalTimes) {
            return [];
        }

        $demandType = null !== $sourcePeriod ? DutyDemandType::CONDITIONAL : DutyDemandType::REQUIRED;
        if (null !== $this->projection) {
            $this->claim(array_map(static fn (array $times): \DateTimeImmutable => $times[0], array_values($componentLocalTimes)), null === $sourcePeriod, $pattern->getFamily()?->getName());

            return [];
        }
        if ($remainder) {
            $this->materializationService->materializeBlockRemainder($period, $pattern, $monday, $componentLocalTimes, $demandType, $coverageSources);
        } else {
            $this->materializationService->materializeGroup(
                $period,
                $pattern,
                $monday,
                $componentLocalTimes,
                $demandType,
                coverageSourcesByOffset: $coverageSources,
            );
        }

        return [];
    }

    /** A day already holding a duty — or, while projecting, one a pattern before this one would have taken. */
    private function isTaken(PlanningPeriod $period, \DateTimeImmutable $day): bool
    {
        return isset($this->projection['claimed'][$day->format('Y-m-d')]) || $this->dutyRepository->existsForPeriodAndLocalDate($period, $day);
    }

    /**
     * @param list<\DateTimeImmutable> $days     one unit's days
     * @param bool                     $required a REQUIRED (intrinsic) unit, counted per family like D137 does
     */
    private function claim(array $days, bool $required, ?string $family): void
    {
        foreach ($days as $day) {
            $this->projection['claimed'][$day->format('Y-m-d')] = true;
        }
        $this->projection['duties'] += \count($days);
        if ($required) {
            $key = $family ?? '';
            $this->projection['families'][$key] = ($this->projection['families'][$key] ?? 0) + 1;
        }
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
