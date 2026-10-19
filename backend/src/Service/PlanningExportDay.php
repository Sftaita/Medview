<?php

declare(strict_types=1);

namespace App\Service;

/**
 * One date of the exported range: one cell per exported line, in document
 * order; an empty cell means no duty that day on that line.
 *
 * `$periodNumber`: the planning period of that date (docs/decisions.md
 * D200). `$unpublished`: the columns whose line period is not published
 * that day — their cell is empty and reads "Non publiée", never "no duty".
 */
final readonly class PlanningExportDay
{
    public const UNPUBLISHED = 'Non publiée';

    /**
     * @param list<list<PlanningExportItem>> $cells
     * @param list<int>                      $unpublished column indexes
     */
    public function __construct(
        public \DateTimeImmutable $date,
        public array $cells,
        public ?int $periodNumber = null,
        public array $unpublished = [],
    ) {
    }

    public function isUnpublished(int $column): bool
    {
        return \in_array($column, $this->unpublished, true);
    }
}
