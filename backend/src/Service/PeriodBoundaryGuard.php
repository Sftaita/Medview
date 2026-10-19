<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningPeriodStatus;
use App\Entity\PlanningTeam;
use App\Exception\PeriodBoundarySplitsBlockException;
use App\Repository\DutyPatternRepository;
use App\Repository\DutyRepository;
use App\Repository\PlanningCycleRepository;

/**
 * No boundary between two periods ever cuts an atomic block of duties
 * (docs/planning-cycles.md §5.1, lot L3, option A).
 *
 * The rule follows materialization exactly (WeeklyDutyCalendarService): a
 * block is a recurring, active DutyPattern of two components or more; its
 * occurrence of a week is the day of each component, at its dayOffset from
 * that week's Monday — whatever the number of days, contiguous or not. An
 * occurrence is materialized only when all its days fall inside one period;
 * one whose first day is before a boundary and last day on or after it is
 * therefore materialized in neither period. A single-day pattern is never a
 * block: one duty starting on the last day of a period and ending in the
 * next one (a night) is a duty of its start day, materialized normally, and
 * only the rest and overlap rules apply to it (PersonCommitmentChecker).
 *
 * Checked where a boundary or a block can appear: creating the next period
 * (its start and its end), moving the end of the last one, changing a
 * line's weekly structure; and reported by the generation preflight.
 *
 * The ends of a planning with a single period are not boundaries between
 * periods: a block cut there behaves as before periods existed.
 *
 * **Historical boundary** (docs/decisions.md D192, the one exception): the
 * start of a period that is the end of a LOCKED previous period (one of its
 * line periods validated, published or archived) can no longer be moved —
 * it was fixed before this rule, when the previous period was created or
 * extended as the only one. A block occurrence it cuts, whose earlier days
 * were never materialized in the previous period for that line, is
 * "historical": the period is created only once the manager confirms it, and
 * the occurrence's days in the new period are materialized as a remainder
 * block (DutyMaterializationService::materializeBlockRemainder()). Never for
 * the end of a period (a boundary still to be chosen), never when the
 * previous period can still move, never when an earlier day of the
 * occurrence already has a duty of that line.
 */
final class PeriodBoundaryGuard
{
    private const LOCKED_STATUSES = [PlanningPeriodStatus::VALIDATED, PlanningPeriodStatus::PUBLISHED, PlanningPeriodStatus::ARCHIVED];

    public function __construct(
        private readonly DutyPatternRepository $patternRepository,
        private readonly PlanningCycleRepository $cycleRepository,
        private readonly DutyRepository $dutyRepository,
    ) {
    }

    /**
     * The blocks of $team's weekly structure: name and day offsets of every
     * active recurring pattern of two components or more.
     *
     * @return list<array{name: string, offsets: list<int>}>
     */
    public function blocksOf(PlanningTeam $team): array
    {
        $blocks = [];
        foreach ($this->patternRepository->findActiveRecurringByTeam($team) as $pattern) {
            $offsets = array_map(static fn ($component): int => $component->getDayOffset(), $pattern->getComponents()->toArray());
            if (\count($offsets) >= 2) {
                $blocks[] = ['name' => $pattern->getName(), 'offsets' => array_values($offsets)];
            }
        }

        return $blocks;
    }

    /**
     * Every occurrence of $blocks that $boundary cuts.
     *
     * @param list<array{name: string, offsets: list<int>}> $blocks
     *
     * @return list<BlockCut>
     */
    public function cutsAt(array $blocks, \DateTimeImmutable $boundary, ?PlanningLine $line = null): array
    {
        $boundaryDay = $boundary->format('Y-m-d');
        $boundaryMonday = $this->mondayOnOrBefore(new \DateTimeImmutable($boundaryDay));

        $cuts = [];
        foreach ($blocks as $block) {
            $offsets = $block['offsets'];
            sort($offsets);
            $lastOffset = max($offsets);
            // Every week whose occurrence can still reach the boundary: from the one whose last day is on it, back.
            for ($weeksBack = 0; $weeksBack * 7 <= $lastOffset; ++$weeksBack) {
                $monday = $boundaryMonday->modify(\sprintf('-%d days', 7 * $weeksBack));
                $days = array_map(static fn (int $offset): \DateTimeImmutable => $monday->modify(\sprintf('+%d days', $offset)), $offsets);
                if ($days[0]->format('Y-m-d') < $boundaryDay && $days[\count($days) - 1]->format('Y-m-d') >= $boundaryDay) {
                    $cuts[] = new BlockCut($line, $block['name'], new \DateTimeImmutable($boundaryDay), $days[0], $days[\count($days) - 1], $days);
                }
            }
        }

        return $cuts;
    }

    /**
     * @param list<PlanningLine>       $lines
     * @param list<\DateTimeImmutable> $boundaries
     *
     * @throws PeriodBoundarySplitsBlockException
     */
    public function assertNoCut(array $lines, array $boundaries): void
    {
        $cuts = [];
        foreach ($lines as $line) {
            $blocks = $this->blocksOf($line->getPlanningTeam());
            foreach ($boundaries as $boundary) {
                array_push($cuts, ...$this->cutsAt($blocks, $boundary, $line));
            }
        }

        if ([] !== $cuts) {
            throw new PeriodBoundarySplitsBlockException($cuts);
        }
    }

    /**
     * The occurrences the start of a period following $previous would cut,
     * split into historical ones (D192: $previous is locked, and the line has
     * no duty on their earlier days) and ordinary ones.
     *
     * @param list<PlanningLine> $lines
     *
     * @return array{0: list<BlockCut>, 1: list<BlockCut>} [historical, ordinary]
     */
    public function classifyStartCuts(PlanningCycle $previous, array $lines): array
    {
        $boundary = $previous->getEndsAt();
        $locked = $this->isLocked($previous);
        $historical = [];
        $ordinary = [];
        foreach ($lines as $line) {
            foreach ($this->cutsAt($this->blocksOf($line->getPlanningTeam()), $boundary, $line) as $cut) {
                if ($locked && !$this->earlierDaysMaterialized($line->periodIn($previous), $cut->earlierDays())) {
                    $historical[] = $cut;
                } else {
                    $ordinary[] = $cut;
                }
            }
        }

        return [$historical, $ordinary];
    }

    /**
     * The historical cuts at the start of $cycle (D192), for its lines — what
     * its preflight reports, and what the creation confirmed.
     *
     * @return list<BlockCut>
     */
    public function historicalCutsAt(PlanningCycle $cycle): array
    {
        $previous = $this->previousOf($cycle);
        if (null === $previous) {
            return [];
        }

        $lines = [];
        foreach ($this->cycleRepository->periodsOf($cycle) as $period) {
            if (null !== $period->getLine() && $period->getLine()->isActive()) {
                $lines[] = $period->getLine();
            }
        }

        return $this->classifyStartCuts($previous, $lines)[0];
    }

    /**
     * Whether the occurrence whose $earlierDays lie before the start of
     * $period may be materialized there as a remainder (D192): that start is a
     * historical boundary for the line of $period.
     *
     * @param list<\DateTimeImmutable> $earlierDays
     */
    public function allowsRemainder(PlanningPeriod $period, array $earlierDays): bool
    {
        $cycle = $period->getCycle();
        $line = $period->getLine();
        $previous = null !== $cycle ? $this->previousOf($cycle) : null;
        if (null === $previous || null === $line || [] === $earlierDays) {
            return false;
        }
        foreach ($earlierDays as $day) {
            if ($day->format('Y-m-d') >= $cycle->getStartsAt()->format('Y-m-d')) {
                return false;
            }
        }

        return $this->isLocked($previous) && !$this->earlierDaysMaterialized($line->periodIn($previous), $earlierDays);
    }

    /**
     * A new weekly structure for $line must fit every boundary between the
     * periods of its planning, and the end of its last period once it has
     * several (the next boundary). A historical boundary (D192) tolerates
     * the cut: its remainder is materialized as for the structure before.
     *
     * @param list<array{name: string, offsets: list<int>}> $blocks
     *
     * @throws PeriodBoundarySplitsBlockException
     */
    public function assertStructureFits(PlanningLine $line, array $blocks): void
    {
        $cycles = $this->cycleRepository->findByPlanning($line->getPlanning());
        $cuts = [];
        foreach ($cycles as $index => $cycle) {
            if (0 === $index) {
                continue;
            }
            $previous = $cycles[$index - 1];
            $locked = $this->isLocked($previous);
            foreach ($this->cutsAt($blocks, $cycle->getStartsAt(), $line) as $cut) {
                if (!$locked || $this->earlierDaysMaterialized($line->periodIn($previous), $cut->earlierDays())) {
                    $cuts[] = $cut;
                }
            }
        }
        if (\count($cycles) > 1) {
            array_push($cuts, ...$this->cutsAt($blocks, $cycles[\count($cycles) - 1]->getEndsAt(), $line));
        }

        if ([] !== $cuts) {
            throw new PeriodBoundarySplitsBlockException($cuts);
        }
    }

    /**
     * The occurrences of $line's blocks cut by a boundary of $cycle with a
     * neighbouring period (its start from period 2 on, its end when a later
     * period exists) and NOT historical — what the generation preflight
     * blocks on.
     *
     * @return list<BlockCut>
     */
    public function cutsForLineInCycle(PlanningLine $line, PlanningCycle $cycle): array
    {
        $cuts = [];
        $previous = $this->previousOf($cycle);
        if (null !== $previous) {
            array_push($cuts, ...$this->classifyStartCuts($previous, [$line])[1]);
        }
        $next = $this->nextOf($cycle);
        if (null !== $next) {
            array_push($cuts, ...$this->classifyStartCuts($cycle, [$line])[1]);
        }

        return $cuts;
    }

    /**
     * Every boundary between two periods of $planning: the start of each
     * period from period 2 on.
     *
     * @return list<\DateTimeImmutable>
     */
    public function boundariesOf(Planning $planning): array
    {
        $boundaries = [];
        foreach ($this->cycleRepository->findByPlanning($planning) as $cycle) {
            if ($cycle->getNumber() > 1) {
                $boundaries[] = $cycle->getStartsAt();
            }
        }

        return $boundaries;
    }

    /** Its end can no longer move: one of its line periods is validated, published or archived. */
    public function isLocked(PlanningCycle $cycle): bool
    {
        foreach ($this->cycleRepository->periodsOf($cycle) as $period) {
            if (\in_array($period->getStatus(), self::LOCKED_STATUSES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<\DateTimeImmutable> $days
     */
    private function earlierDaysMaterialized(?PlanningPeriod $previousPeriod, array $days): bool
    {
        if (null === $previousPeriod) {
            return false; // the line took no part in the previous period: nothing of the occurrence exists there
        }
        foreach ($days as $day) {
            if ($this->dutyRepository->existsForPeriodAndLocalDate($previousPeriod, $day)) {
                return true;
            }
        }

        return false;
    }

    private function previousOf(PlanningCycle $cycle): ?PlanningCycle
    {
        foreach ($this->cycleRepository->findByPlanning($cycle->getPlanning()) as $candidate) {
            if ($candidate->getNumber() === $cycle->getNumber() - 1) {
                return $candidate;
            }
        }

        return null;
    }

    private function nextOf(PlanningCycle $cycle): ?PlanningCycle
    {
        foreach ($this->cycleRepository->findByPlanning($cycle->getPlanning()) as $candidate) {
            if ($candidate->getNumber() === $cycle->getNumber() + 1) {
                return $candidate;
            }
        }

        return null;
    }

    private function mondayOnOrBefore(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->modify(\sprintf('-%d days', (int) $date->format('N') - 1));
    }
}
