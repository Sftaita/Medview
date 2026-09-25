<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningLine;
use App\Entity\PlanningPeriodStatus;

/**
 * One active line's outcome of a publish call (docs/decisions.md D133) —
 * `alreadyPublished` distinguishes a line this call actually transitioned
 * from one that was already PUBLISHED before it ran (e.g. a secondary line
 * added after the primary was first published) — never conflated.
 */
final readonly class PublicationLineResult
{
    public function __construct(
        public PlanningLine $line,
        public PlanningPeriodStatus $periodStatus,
        public bool $alreadyPublished,
    ) {
    }
}
