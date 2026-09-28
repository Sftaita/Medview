<?php

declare(strict_types=1);

namespace App\Service;

/**
 * One duty of an exported cell: who holds it right now (null = uncovered)
 * and, only when the cell holds several duties, which duty it is.
 */
final readonly class PlanningExportItem
{
    public function __construct(
        public ?string $personName,
        public ?string $dutyTypeName,
    ) {
    }
}
