<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * docs/allocation-algorithm.md §6's business default: PRIMARY =
 * `weekendGroups`, `namedHolidays`. SECONDARY = `fridays`, `saturdays`,
 * `sundays`, `holidays`, `nights`, `dutyTypeSpecific`, `weightedWorkload`,
 * `totalDuties`.
 *
 * **Closed at D136**: `ALLOCATION_FAMILY` (docs/decisions.md D136) is now
 * the real, generic successor to `weekendGroups` this classifier's own
 * docblock used to say was missing — a team's own equity buckets
 * ("Week-end", "Semaine", ...) are what actually gets classified PRIMARY,
 * never a hardcoded Friday/Saturday/Sunday notion of "weekend".
 * `NAMED_HOLIDAY` remains unimplemented (no `HolidayDefinition` exists) —
 * still correctly absent from both lists.
 *
 * `weightedWorkload`/`totalDuties` are therefore always classified
 * SECONDARY here, never PRIMARY by accident — the concern
 * CLAUDE.md's non-negotiable fairness principle exists to guard against.
 */
final class DefaultFairnessDimensionClassifier implements FairnessDimensionClassifier
{
    public function primaryDimensions(array $dimensions): array
    {
        return array_values(array_filter(
            $dimensions,
            static fn (FairnessDimensionKey $key): bool => FairnessDimensionType::ALLOCATION_FAMILY === $key->type,
        ));
    }

    public function secondaryDimensions(array $dimensions): array
    {
        return array_values(array_filter(
            $dimensions,
            static fn (FairnessDimensionKey $key): bool => match ($key->type) {
                FairnessDimensionType::FRIDAY,
                FairnessDimensionType::SATURDAY,
                FairnessDimensionType::SUNDAY,
                FairnessDimensionType::WEIGHTED_WORKLOAD,
                FairnessDimensionType::TOTAL_DUTIES,
                FairnessDimensionType::DUTY_TYPE => true,
                FairnessDimensionType::ALLOCATION_FAMILY => false,
            },
        ));
    }
}
