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
 *   ALLOCATION_FAMILY → Duty.pattern.family.stableId (docs/decisions.md
 *                       D136; paired with a FairnessDimensionKey's
 *                       $allocationFamilyStableId — this case is never used
 *                       bare either), the real WEEKEND_GROUPS successor:
 *                       once every PlanningLine can classify its own
 *                       DutyPatterns into arbitrary, team-defined equity
 *                       buckets ("Week-end", "Semaine", "Samedi seul", ...)
 *                       instead of one universal weekend/weekday split, the
 *                       generic name reflects that no dimension here is
 *                       ever a synonym for "weekend" specifically.
 *
 * Deliberately NOT included: NIGHT, HOLIDAY, NAMED_HOLIDAY — still no real,
 * unambiguous data source in the code today (no holiday calendar entity, no
 * night-shift flag distinct from DutyType). WEEKEND_GROUPS never became a
 * FairnessDimensionType case at all: ALLOCATION_FAMILY supersedes it
 * (docs/decisions.md D136) rather than sitting alongside it — a
 * `DutyPattern` with no family (§ above) already covers "not equity-
 * classified", so a separate binary WEEKEND_GROUPS would only duplicate
 * ALLOCATION_FAMILY's WEEKEND case for teams that choose that name.
 */
enum FairnessDimensionType: string
{
    case TOTAL_DUTIES = 'TOTAL_DUTIES';
    case WEIGHTED_WORKLOAD = 'WEIGHTED_WORKLOAD';
    case FRIDAY = 'FRIDAY';
    case SATURDAY = 'SATURDAY';
    case SUNDAY = 'SUNDAY';
    case DUTY_TYPE = 'DUTY_TYPE';
    case ALLOCATION_FAMILY = 'ALLOCATION_FAMILY';
}
