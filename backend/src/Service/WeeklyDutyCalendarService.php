<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DutyPattern;
use App\Entity\PlanningPeriod;
use App\Repository\DutyPatternRepository;
use App\Repository\DutyRepository;

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
 */
final class WeeklyDutyCalendarService
{
    public function __construct(
        private readonly DutyPatternRepository $patternRepository,
        private readonly DutyMaterializationService $materializationService,
        private readonly DutyRepository $dutyRepository,
    ) {
    }

    /**
     * Materializes every missing week from the period's own start up to
     * $until (clamped to the period's own end — never beyond what the
     * period actually covers).
     */
    public function ensureMaterialized(PlanningPeriod $planningPeriod, \DateTimeImmutable $until): void
    {
        $patterns = $this->patternRepository->findActiveRecurringByTeam($planningPeriod->getTeam());
        if ([] === $patterns) {
            // No weekly structure configured for this line yet — never
            // guess one (docs/decisions.md D136); nothing to materialize.
            return;
        }

        $periodEnd = min($until, $planningPeriod->getEndsAt());
        if ($periodEnd <= $planningPeriod->getStartsAt()) {
            return;
        }

        $monday = $this->mondayOnOrBefore($planningPeriod->getStartsAt());
        while ($monday < $periodEnd) {
            foreach ($patterns as $pattern) {
                $this->materializeOccurrence($planningPeriod, $pattern, $monday);
            }
            $monday = $monday->modify('+7 days');
        }
    }

    private function materializeOccurrence(PlanningPeriod $period, DutyPattern $pattern, \DateTimeImmutable $monday): void
    {
        if (1 === \count($pattern->getComponents())) {
            $this->materializeSolo($period, $pattern, $monday);

            return;
        }

        $this->materializeBlock($period, $pattern, $monday);
    }

    private function materializeSolo(PlanningPeriod $period, DutyPattern $pattern, \DateTimeImmutable $monday): void
    {
        $component = $pattern->getComponents()->first();
        if (false === $component) {
            // A pattern always has at least one component once it is
            // usable (WeekStructureService::replace() never persists an
            // empty one) — defense-in-depth, never reachable in practice.
            return;
        }

        $day = $monday->modify(sprintf('+%d days', $component->getDayOffset()));
        if ($day < $period->getStartsAt() || $day >= $period->getEndsAt()) {
            return;
        }

        if ($this->dutyRepository->existsForPeriodAndLocalDate($period, $day)) {
            return;
        }

        $this->materializationService->createStandaloneDuty(
            $period,
            $component->getDutyType(),
            $day,
            $day->modify('+1 day'),
            pattern: $pattern,
        );
    }

    private function materializeBlock(PlanningPeriod $period, DutyPattern $pattern, \DateTimeImmutable $monday): void
    {
        $componentLocalTimes = [];
        foreach ($pattern->getComponents() as $component) {
            $day = $monday->modify(sprintf('+%d days', $component->getDayOffset()));
            if ($day < $period->getStartsAt() || $day >= $period->getEndsAt()) {
                return;
            }

            if ($this->dutyRepository->existsForPeriodAndLocalDate($period, $day)) {
                // Atomic: even one already-claimed day cancels the whole
                // occurrence for this week — never a partial block.
                return;
            }

            $componentLocalTimes[$component->getDayOffset()] = [$day, $day->modify('+1 day')];
        }

        $this->materializationService->materializeGroup($period, $pattern, $monday, $componentLocalTimes);
    }

    private function mondayOnOrBefore(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $isoWeekday = (int) $date->format('N'); // 1 = Monday .. 7 = Sunday

        return $date->modify(sprintf('-%d days', $isoWeekday - 1));
    }
}
