<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The control shown before "Générer le planning". Purely informative — it
 * captures nothing: the snapshot is taken by the generation itself, at the
 * moment it runs (docs/decisions.md D129), from whatever the data is then.
 */
final readonly class PlanningGenerationPreflight
{
    /**
     * @param list<LaunchLineReadiness> $lines    active lines, in display order
     * @param list<PreflightIssue>      $blockers technical impossibilities
     * @param list<PreflightIssue>      $warnings organisational signals, never blocking
     */
    public function __construct(
        public PlanningCollectionStatus $collection,
        public array $lines,
        public array $blockers,
        public array $warnings,
    ) {
    }

    public function canGenerate(): bool
    {
        return [] === $this->blockers;
    }
}
