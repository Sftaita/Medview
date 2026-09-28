<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningGeneration;
use App\Entity\PlanningLine;
use App\Fairness\CoverageStatus;

/**
 * One line's read-only result (docs/decisions.md D130): the D125 rule
 * ("the most recent COMPLETED generation") applied independently per line,
 * exactly like `PlanningAssignmentViewService::currentGenerations()` — a
 * line with no COMPLETED generation yet has `$generation = null` and an
 * empty `$duties`, never a fabricated 0/0 coverage.
 */
final readonly class PlanningResultLine
{
    /**
     * @param list<PlanningResultDuty> $duties empty when $generation is null
     */
    public function __construct(
        public PlanningLine $line,
        public ?PlanningGeneration $generation,
        public ?CoverageStatus $coverageStatus,
        public int $requiredDutyCount,
        public int $coveredRequiredDutyCount,
        public int $uncoveredRequiredDutyCount,
        public array $duties,
        /** docs/decisions.md D165 — conditional duties whose live demand cannot be evaluated (never counted as covered nor as not required). */
        public int $undeterminedDutyCount = 0,
        /** docs/decisions.md D165 — conditional duties not required any more but still held (kept on purpose, warned about). */
        public int $superfluousDutyCount = 0,
        /** docs/decisions.md D166 — conditional duties the live demand does not require (held or not): never "to cover", never "missing". */
        public int $notRequiredDutyCount = 0,
    ) {
    }
}
