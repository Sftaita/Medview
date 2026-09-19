<?php

declare(strict_types=1);

namespace App\Fairness;

/**
 * The full diagnostic view of one unassigned REQUIRED DutyUnit
 * (docs/allocation-algorithm.md §16 "unassigned[]") — deliberately a
 * *different*, richer type than `UnassignedDutyUnit`
 * (`OptimizationResult::$unassignedDuties`, always cheap/available even
 * on a plain lightweight read): this one belongs exclusively to
 * `UnsatReport::$unassignedDuties`, built only when a real diagnostic was
 * actually run.
 */
final readonly class UnassignedDutyDiagnostic
{
    /**
     * @param list<CandidateExclusionDiagnostic> $candidateExclusions only candidates with at least one real exclusion reason — never every candidate
     */
    public function __construct(
        public string $dutyUnitStableKey,
        public bool $critical,
        public array $candidateExclusions,
    ) {
    }
}
