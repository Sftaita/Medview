<?php

declare(strict_types=1);

namespace App\Fairness;

use App\Eligibility\EligibilityExclusion;

/**
 * One candidate's real exclusion reasons for one unassigned DutyUnit
 * (docs/allocation-algorithm.md §16 "candidateExclusions",
 * docs/planning-solver.md). `$exclusions` is always non-empty — reused
 * directly from `EligibilityResult::$exclusions`
 * (`App\Eligibility\EligibilityExclusion`), never re-derived or
 * paraphrased. A candidate who was *eligible* but simply not selected by
 * the global optimum never appears here at all (docs/decisions.md D095 —
 * "the non-selection of an eligible candidate is not a causality", never
 * a fabricated reason).
 */
final readonly class CandidateExclusionDiagnostic
{
    /**
     * @param non-empty-list<EligibilityExclusion> $exclusions
     */
    public function __construct(
        public string $candidateId,
        public array $exclusions,
    ) {
    }
}
