<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\NoNewPlanningRangeException;
use App\Exception\PlanningRangeShrinkException;

/**
 * Which dates a planning extension makes *new* — the only dates an
 * availability collection must ask about (docs/availability-collection.md
 * §5). Pure: no clock, no database.
 *
 * Extending the end by three months yields one window (the three months);
 * extending the start yields one window (the added days before it);
 * extending both sides yields two disjoint windows. The already-covered
 * dates are never part of the result, so nobody is ever asked to confirm
 * them twice.
 */
final class AvailabilityWindowCalculator
{
    /**
     * @return list<DateWindow> ordered by date, never empty
     *
     * @throws PlanningRangeShrinkException when the new range does not contain the current one
     * @throws NoNewPlanningRangeException  when the new range equals the current one
     */
    public function newWindows(
        \DateTimeImmutable $currentStartsAt,
        \DateTimeImmutable $currentEndsAt,
        \DateTimeImmutable $newStartsAt,
        \DateTimeImmutable $newEndsAt,
    ): array {
        if ($newStartsAt > $currentStartsAt || $newEndsAt < $currentEndsAt) {
            throw new PlanningRangeShrinkException();
        }

        $windows = [];
        if ($newStartsAt < $currentStartsAt) {
            $windows[] = new DateWindow($newStartsAt, $currentStartsAt);
        }
        if ($newEndsAt > $currentEndsAt) {
            $windows[] = new DateWindow($currentEndsAt, $newEndsAt);
        }

        if ([] === $windows) {
            throw new NoNewPlanningRangeException();
        }

        return $windows;
    }
}
