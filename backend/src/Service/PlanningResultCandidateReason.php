<?php

declare(strict_types=1);

namespace App\Service;

/**
 * One real, already-computed reason one candidate could not be assigned a
 * given uncovered Duty — never a fabricated causality, never a candidate
 * who was simply not chosen by the optimum (docs/decisions.md D095, D130).
 */
final readonly class PlanningResultCandidateReason
{
    /**
     * @param list<string> $reasons plain-language labels, never raw enum codes
     */
    public function __construct(
        public string $candidateFirstName,
        public string $candidateLastName,
        public array $reasons,
    ) {
    }
}
