<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningCycle;
use App\Entity\PlanningLine;

/**
 * The current calendar's real publication readiness for one period
 * (docs/decisions.md D133, D196) — built from the *current* `DutyAssignment`
 * state, never the solver's original historical result (`OptimizationResult`,
 * `coverageStatus` of the generation, initial diagnostics — those stay
 * available for audit but never decide publishability here).
 */
final readonly class PublicationPreflight
{
    public const EARLIER_PERIOD_UNPUBLISHED = 'EARLIER_PERIOD_UNPUBLISHED';

    /**
     * @param list<PublicationLineReadiness>     $lines                the lines that count in the period, published or not
     * @param list<UncoveredPublicationDuty>     $uncoveredDuties
     * @param list<InconsistentPublicationGroup> $inconsistentGroups
     * @param list<InvalidPublicationAssignment> $invalidAssignments
     * @param list<PublicationConflict>          $conflicts
     * @param list<ConditionalPublicationDuty>   $undeterminedDuties   docs/decisions.md D165 — blocks publication AND republication
     * @param list<ConditionalPublicationDuty>   $superfluousCoverages docs/decisions.md D165 — a warning, never a blocker
     * @param list<PublicationNeutralLine>       $neutralLines         docs/decisions.md D194 — active lines with nothing demanded: neither checked nor published, never a blocker
     * @param list<int>                          $earlierUnpublished   docs/decisions.md D196 — numbers of earlier periods with a line still to publish: a warning, never a blocker
     */
    public function __construct(
        /** All or nothing (historical meaning): nothing published in the period yet, and every line that counts ready. */
        public bool $publishable,
        public array $lines,
        public array $uncoveredDuties,
        public array $inconsistentGroups,
        public array $invalidAssignments,
        public array $conflicts,
        /** docs/decisions.md D143/D196 — the period's published lines can be republished: same checks on them, an uncovered duty (an announced removal) excepted. Lines not yet published never play a part. */
        public bool $republishable = false,
        public array $undeterminedDuties = [],
        public array $superfluousCoverages = [],
        public array $neutralLines = [],
        public ?PlanningCycle $cycle = null,
        public array $earlierUnpublished = [],
    ) {
    }

    public function line(PlanningLine $line): ?PublicationLineReadiness
    {
        foreach ($this->lines as $readiness) {
            if ($readiness->line === $line) {
                return $readiness;
            }
        }

        return null;
    }

    /**
     * @return list<PublicationLineReadiness>
     */
    public function publishedLines(): array
    {
        return array_values(array_filter($this->lines, static fn (PublicationLineReadiness $line): bool => $line->isPublished()));
    }

    /**
     * @return list<PublicationLineReadiness> not published yet, ready to be
     */
    public function newLinesReady(): array
    {
        return array_values(array_filter($this->lines, static fn (PublicationLineReadiness $line): bool => $line->isFirstPublishable()));
    }
}
