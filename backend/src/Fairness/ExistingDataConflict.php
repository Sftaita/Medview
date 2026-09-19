<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * A PARTIAL solve that is *itself* `UNSATISFIABLE`
 * (docs/allocation-algorithm.md §10.5/§16) — a strictly more severe,
 * distinct diagnosis than "some REQUIRED duties could not be covered": it
 * means the data already on record contradicts itself independently of
 * any new assignment choice. Never confused with a plain coverage
 * shortfall (`UnsatReport::$unassignedDuties`).
 */
final readonly class ExistingDataConflict
{
    /**
     * @param list<string> $affectedDutyUnitStableKeys
     * @param list<string> $affectedCandidateIds
     * @param list<string> $reasons
     */
    public function __construct(
        public ExistingDataConflictType $type,
        public array $affectedDutyUnitStableKeys,
        public array $affectedCandidateIds,
        public array $reasons,
        public string $message,
    ) {
    }
}
