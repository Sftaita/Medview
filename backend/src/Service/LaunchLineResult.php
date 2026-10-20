<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningGeneration;
use App\Entity\PlanningLine;
use App\Entity\PlanningSnapshot;
use App\Fairness\OptimizationResult;

/** The outcome of the pipeline for one line. `error` is set when it stopped before a usable result. */
final readonly class LaunchLineResult
{
    public function __construct(
        public PlanningLine $line,
        /** Null only for a conditional line not resolved because its source line failed (docs/decisions.md D164). */
        public ?PlanningGeneration $generation,
        public ?PlanningSnapshot $snapshot,
        public ?OptimizationResult $result,
        /** A stable machine code (never a raw exception message) when the line could not be generated. */
        public ?string $error = null,
        /** A conditional line's demand, per unit (D164) — null for an independent line. */
        public ?LineDemandSummary $demand = null,
        /**
         * Why a result solved in this launch was not written (`launch_inconsistent`, docs/decisions.md D191): each
         * rule the final calendar would have broken.
         *
         * @var list<array<string, mixed>>
         */
        public array $conflicts = [],
        /** docs/decisions.md D195: GENERATE, or KEEP / EXCLUDE for a line this launch left as it is (no generation). */
        public string $decision = LaunchLineReadiness::GENERATE,
        /** docs/decisions.md D194/D195: why a line was kept or excluded — null for a line generated. */
        public ?LineApplicability $applicability = null,
    ) {
    }

    public function isGenerated(): bool
    {
        return LaunchLineReadiness::GENERATE === $this->decision;
    }
}
