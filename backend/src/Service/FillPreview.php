<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The preview of "Générer les postes non pourvus" (docs/decisions.md D222):
 * one plan per active line of the period, in resolution order.
 */
final readonly class FillPreview
{
    /**
     * @param list<FillLinePlan> $lines
     * @param list<string>       $blockers PreflightIssueCode values that block every line (no solver parameters)
     */
    public function __construct(
        public array $lines,
        public array $blockers = [],
    ) {
    }

    public function anyFirstGeneration(): bool
    {
        return [] !== array_filter($this->lines, static fn (FillLinePlan $plan): bool => FillLinePlan::GENERATE === $plan->action);
    }

    /**
     * The plans of $lineStableIds that would write something.
     *
     * @param list<string> $lineStableIds
     *
     * @return list<FillLinePlan>
     */
    public function writing(array $lineStableIds): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (FillLinePlan $plan): bool => $plan->writes() && \in_array((string) $plan->line->getStableId(), $lineStableIds, true),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lines' => array_map(static fn (FillLinePlan $plan): array => $plan->toArray(), $this->lines),
            'blockers' => $this->blockers,
            'needsRestPolicy' => $this->anyFirstGeneration(),
        ];
    }
}
