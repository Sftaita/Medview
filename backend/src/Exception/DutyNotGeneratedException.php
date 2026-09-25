<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A Duty can only be reassigned within a generation (docs/decisions.md
 * D131) — its line has no COMPLETED PlanningGeneration yet (D125). There is
 * nothing to reassign: the calendar for this line has never been
 * generated.
 */
final class DutyNotGeneratedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This Duty\'s line has no completed generation yet — nothing to reassign.');
    }
}
