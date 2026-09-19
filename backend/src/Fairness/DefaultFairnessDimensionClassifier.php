<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * docs/allocation-algorithm.md §6's business default: PRIMARY =
 * `weekendGroups`, `namedHolidays`. SECONDARY = `fridays`, `saturdays`,
 * `sundays`, `holidays`, `nights`, `dutyTypeSpecific`, `weightedWorkload`,
 * `totalDuties`.
 *
 * `primaryDimensions()` deliberately returns an empty list today —
 * confirmed with the user and recorded in docs/decisions.md D086. The
 * spec's default is real and not forgotten, but `WEEKEND_GROUPS`/
 * `NAMED_HOLIDAY` do not exist as `FairnessDimensionType` cases: no
 * `HolidayDefinition`, no weekend-group classification, so neither
 * dimension has a `requiredDemand`, `effectiveExposure`, or
 * `dimensionMembership` anywhere in the fairness pipeline. Adding them
 * only to this classifier — an identity with no data behind it — would be
 * exactly the "guess ahead of the data" this codebase has refused since
 * Lot 4/5 (docs/fairness.md §3). Applying the real default is a future
 * lot's job, and touches more than this file: `FairnessDimensionType`,
 * `FairnessDimensionKey`, `RequiredDemandBuilder`, `EffectiveExposureService`
 * (if applicable), `DimensionMembershipCalculator`, and
 * `FairnessContextBuilder::deriveSupportedDimensions()` all need the real
 * dimension before this classifier is the last, not the first, thing to
 * update.
 *
 * `weightedWorkload`/`totalDuties` are therefore always classified
 * SECONDARY here, never PRIMARY by accident — the concern
 * CLAUDE.md's non-negotiable fairness principle exists to guard against.
 */
final class DefaultFairnessDimensionClassifier implements FairnessDimensionClassifier
{
    public function primaryDimensions(array $dimensions): array
    {
        return [];
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
            },
        ));
    }
}
