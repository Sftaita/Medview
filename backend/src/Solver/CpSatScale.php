<?php

declare(strict_types=1);

namespace App\Solver;

use App\Fairness\FairnessDimensionType;

/**
 * Centralized integer scaling for CP-SAT (docs/planning-solver.md
 * §Scaling, docs/decisions.md D092). CP-SAT only accepts integer
 * coefficients — every fractional fairness quantity (targets, deviations,
 * WEIGHTED_WORKLOAD) is scaled to an integer exactly once, here, never ad
 * hoc inside `cp_sat_solver.py`.
 *
 * `SCALE = 10_000` — audited against the one real fractional precision
 * that exists in the domain today: `DutyType.workloadValue` is stored as
 * `decimal(6,2)` (`src/Entity/DutyType.php`), so its smallest real unit is
 * `0.01`. 10,000 is the smallest round power-of-ten multiple of 100 (so
 * `0.01 * SCALE` is always an exact integer, 100, never a rounding
 * artifact) — it was not picked to match
 * `docs/allocation-algorithm.md` §22's own illustrative "×10 000" example,
 * it was derived independently from the real column precision, and the
 * two happen to agree.
 */
final class CpSatScale
{
    public const SCALE = 10_000;

    /**
     * `smallestUnit(d)` from docs/allocation-algorithm.md §5: the smallest
     * real, representable step for a dimension — `1` for every dimension
     * counted in whole Duties, `0.01` for `WEIGHTED_WORKLOAD` (the real
     * precision of `DutyType.workloadValue`, not an arbitrary choice).
     */
    public static function smallestUnit(FairnessDimensionType $type): float
    {
        return match ($type) {
            FairnessDimensionType::WEIGHTED_WORKLOAD => 0.01,
            FairnessDimensionType::TOTAL_DUTIES,
            FairnessDimensionType::FRIDAY,
            FairnessDimensionType::SATURDAY,
            FairnessDimensionType::SUNDAY,
            FairnessDimensionType::DUTY_TYPE,
            FairnessDimensionType::ALLOCATION_FAMILY => 1.0,
        };
    }
}
