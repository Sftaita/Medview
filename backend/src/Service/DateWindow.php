<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A half-open range of calendar dates [startsAt, endsAt) — the same
 * convention as Planning, PlanningPeriod and FairnessPeriod.
 */
final readonly class DateWindow
{
    public function __construct(
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
    ) {
        if ($endsAt <= $startsAt) {
            throw new \InvalidArgumentException('endsAt must be strictly after startsAt.');
        }
    }
}
