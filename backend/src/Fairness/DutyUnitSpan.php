<?php

declare(strict_types=1);

namespace App\Fairness;

use App\Eligibility\DutyUnit;

/**
 * The spacing-relevant projection of one `DutyUnit` (docs/decisions.md
 * D139) — `startDate`/`endDate`/`family`, exactly the three facts §4 of
 * this lot's spec asks for. A standalone Duty has `startDate === endDate`;
 * an atomic block (e.g. Ven/Sam/Dim) has `startDate` = its first
 * constituent Duty's date, `endDate` = its last — the block is always
 * reasoned about as **one** span, never three days to space against each
 * other (that would double-penalize an atomic assignment decision).
 *
 * Never a generic "Duty span" — the unit is the assignment decision unit
 * everywhere else in this domain (D052/D136), and spacing must reason at
 * the exact same granularity or its own metric would silently disagree
 * with what the solver actually decides over.
 */
final readonly class DutyUnitSpan
{
    private function __construct(
        public string $dutyUnitStableKey,
        public \DateTimeImmutable $startDate,
        public \DateTimeImmutable $endDate,
        public ?string $allocationFamilyStableId,
    ) {
    }

    public static function fromDutyUnit(DutyUnit $unit): self
    {
        $duties = $unit->getDuties();
        $dates = array_map(static fn ($d) => $d->getLocalDate(), $duties);
        usort($dates, static fn (\DateTimeImmutable $a, \DateTimeImmutable $b) => $a <=> $b);

        return new self(
            $unit->getStableKey(),
            $dates[0],
            $dates[\count($dates) - 1],
            null !== $duties[0]->getAllocationFamily() ? (string) $duties[0]->getAllocationFamily()->getStableId() : null,
        );
    }

    /**
     * `freeDays = next.startDate - this.endDate - 1` (§5 of this lot's
     * spec) — the real number of calendar days with no garde between two
     * units, never the raw date difference (which would count the day
     * the next unit starts as "free"). Negative only if the spans
     * overlap/touch in an order this method was not called with — callers
     * always pass the temporally-earlier span first (see
     * `SpacingPenaltyCalculator`, which sorts before pairing).
     */
    public function freeDaysUntil(self $next): int
    {
        $days = (int) $this->endDate->diff($next->startDate)->format('%r%a');

        return $days - 1;
    }
}
