<?php

declare(strict_types=1);

namespace App\Service;

/**
 * An exported line: its stable id and the name it carries in the document.
 */
final readonly class PlanningExportLine
{
    public function __construct(
        public string $stableId,
        public string $label,
    ) {
    }
}
