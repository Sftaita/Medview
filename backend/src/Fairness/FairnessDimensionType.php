<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * The fairness dimensions this lot actually supports, each with a real,
 * currently-modeled data source (docs/fairness.md §Dimensions) — never a
 * dimension invented ahead of the data that would feed it:
 *
 *   TOTAL_DUTIES      → Duty.demandType (count of REQUIRED duties)
 *   WEIGHTED_WORKLOAD → DutyType.workloadValue
 *   FRIDAY/SATURDAY/SUNDAY → Duty.localDate's day of week
 *   DUTY_TYPE         → Duty.dutyType.stableId (paired with a
 *                       FairnessDimensionKey's $dutyTypeStableId — this
 *                       case is never used bare)
 *
 * Deliberately NOT included: NIGHT, HOLIDAY, NAMED_HOLIDAY, WEEKEND_GROUPS
 * — docs/allocation-algorithm.md §5/§6 names them, but none has a real,
 * unambiguous data source in the code today (no holiday calendar entity,
 * no night-shift flag distinct from DutyType, no week-end pattern
 * classification). Adding them now would mean guessing — deferred, not
 * dropped, see docs/fairness.md.
 */
enum FairnessDimensionType: string
{
    case TOTAL_DUTIES = 'TOTAL_DUTIES';
    case WEIGHTED_WORKLOAD = 'WEIGHTED_WORKLOAD';
    case FRIDAY = 'FRIDAY';
    case SATURDAY = 'SATURDAY';
    case SUNDAY = 'SUNDAY';
    case DUTY_TYPE = 'DUTY_TYPE';
}
