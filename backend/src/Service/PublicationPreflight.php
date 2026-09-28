<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The current calendar's real publication readiness (docs/decisions.md
 * D133) — built from the *current* `DutyAssignment` state, never the
 * solver's original historical result (`OptimizationResult`,
 * `coverageStatus` of the generation, initial diagnostics — those stay
 * available for audit but never decide publishability here).
 */
final readonly class PublicationPreflight
{
    /**
     * @param list<PublicationLineReadiness>     $lines
     * @param list<UncoveredPublicationDuty>     $uncoveredDuties
     * @param list<InconsistentPublicationGroup> $inconsistentGroups
     * @param list<InvalidPublicationAssignment> $invalidAssignments
     * @param list<PublicationConflict>          $conflicts
     * @param list<ConditionalPublicationDuty>   $undeterminedDuties  docs/decisions.md D165 — blocks publication AND republication
     * @param list<ConditionalPublicationDuty>   $superfluousCoverages docs/decisions.md D165 — a warning, never a blocker
     */
    public function __construct(
        public bool $publishable,
        public array $lines,
        public array $uncoveredDuties,
        public array $inconsistentGroups,
        public array $invalidAssignments,
        public array $conflicts,
        /** docs/decisions.md D143 — same checks as $publishable, except an uncovered duty on an already-published line (a removal being announced) does not block. */
        public bool $republishable = false,
        public array $undeterminedDuties = [],
        public array $superfluousCoverages = [],
    ) {
    }
}
