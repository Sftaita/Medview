<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;

/**
 * A validated export request (docs/planning-export.md): every line belongs
 * to $planning and is active, the dates lie inside the planning, the
 * order of $lines is the order of the document. Built only by
 * PlanningExportRequestParser.
 *
 * `$operational` (docs/decisions.md D200): the requester manages the
 * calendar (PlanningVoter::MANAGE_CALENDAR) and exports the current
 * calendar; otherwise the export is what was last diffused.
 */
final readonly class PlanningExportRequest
{
    /**
     * @param list<PlanningExportLineChoice> $lines non-empty, in document order
     */
    public function __construct(
        public Planning $planning,
        public PlanningExportFormat $format,
        public string $title,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $toExclusive,
        public array $lines,
        public bool $operational = true,
    ) {
    }
}
