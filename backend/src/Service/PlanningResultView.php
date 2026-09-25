<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;

/**
 * The whole read-only result of a planning (docs/decisions.md D130): one
 * `PlanningResultLine` per line, each independently following D125.
 */
final readonly class PlanningResultView
{
    /**
     * @param list<PlanningResultLine> $lines
     */
    public function __construct(
        public Planning $planning,
        public array $lines,
    ) {
    }
}
