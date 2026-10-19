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
        /** docs/decisions.md D195: the scope asked for (`unlocked_lines`), or null for the historical behaviour. */
        public ?string $scope = null,
        /**
         * docs/decisions.md D195: `unlocked_lines` when the period holds lines kept (locked) next to lines to
         * generate and no scope was asked — the launch is refused until it is, explicitly.
         */
        public ?string $requiredScope = null,
    ) {
    }

    /**
     * @return list<LaunchLineReadiness> the lines this launch generates, in resolution order
     */
    public function generatedLines(): array
    {
        return array_values(array_filter($this->lines, static fn (LaunchLineReadiness $line): bool => $line->isGenerated()));
    }

    public function canGenerate(): bool
    {
        return [] === $this->blockers;
    }
}
