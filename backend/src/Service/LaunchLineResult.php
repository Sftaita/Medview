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
        public PlanningGeneration $generation,
        public ?PlanningSnapshot $snapshot,
        public ?OptimizationResult $result,
        /** A stable machine code (never a raw exception message) when the line could not be generated. */
        public ?string $error = null,
    ) {
    }
}
