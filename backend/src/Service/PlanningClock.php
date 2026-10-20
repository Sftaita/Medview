<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningCycle;
use Symfony\Component\Clock\ClockInterface;

/**
 * "Today" as a Planning sees it — the calendar date in its own timezone
 * (docs/planning-cycles.md §4.1) — and the periods it makes current, next
 * and selected by default. Dates are compared as Y-m-d, like every date
 * range of the domain ([start, end), end exclusive).
 */
final class PlanningClock
{
    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    /** Midnight of the Planning's local date, as a plain date. */
    public function today(Planning $planning): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone($planning->getTimezone()))->format('Y-m-d'));
    }

    /**
     * @param list<PlanningCycle> $cycles ordered by number
     */
    public function current(array $cycles, \DateTimeImmutable $today): ?PlanningCycle
    {
        foreach ($cycles as $cycle) {
            if ($cycle->containsDay($today)) {
                return $cycle;
            }
        }

        return null;
    }

    /**
     * The first period starting after today.
     *
     * @param list<PlanningCycle> $cycles ordered by number
     */
    public function next(array $cycles, \DateTimeImmutable $today): ?PlanningCycle
    {
        foreach ($cycles as $cycle) {
            if ($cycle->getStartsAt()->format('Y-m-d') > $today->format('Y-m-d')) {
                return $cycle;
            }
        }

        return null;
    }

    /**
     * The period an endpoint works on when none is named (docs/planning-cycles.md
     * §7): the current one, else the next, else the last.
     *
     * @param non-empty-list<PlanningCycle> $cycles ordered by number
     */
    public function defaultOf(array $cycles, \DateTimeImmutable $today): PlanningCycle
    {
        return $this->current($cycles, $today) ?? $this->next($cycles, $today) ?? $cycles[\count($cycles) - 1];
    }

    /** past | current | future, relative to today. */
    public function timeState(PlanningCycle $cycle, \DateTimeImmutable $today): string
    {
        $day = $today->format('Y-m-d');

        return match (true) {
            $cycle->getEndsAt()->format('Y-m-d') <= $day => 'past',
            $cycle->getStartsAt()->format('Y-m-d') > $day => 'future',
            default => 'current',
        };
    }
}
