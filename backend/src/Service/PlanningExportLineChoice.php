<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningLine;

/**
 * One line the user chose to export, with the name it carries in the
 * document only — never written back to PlanningLine::$name.
 */
final readonly class PlanningExportLineChoice
{
    public function __construct(
        public PlanningLine $line,
        public string $label,
    ) {
    }
}
