<?php

declare(strict_types=1);

namespace App\Service;

/**
 * One date of the exported range: one cell per exported line, in document
 * order; an empty cell means no duty that day on that line.
 */
final readonly class PlanningExportDay
{
    /**
     * @param list<list<PlanningExportItem>> $cells
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public array $cells,
    ) {
    }
}
