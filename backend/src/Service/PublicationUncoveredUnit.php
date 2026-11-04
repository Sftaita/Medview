<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningLine;

/**
 * One required post without holder that keeps a line from its first
 * publication (docs/decisions.md D232): a block once, its dates, for each
 * day who holds the source line and why the post is required, and why it is
 * still empty — never only a count.
 */
final readonly class PublicationUncoveredUnit
{
    /**
     * @param non-empty-list<PublicationUncoveredDay> $days in date order; a block's days without holder only
     */
    public function __construct(
        public PlanningLine $line,
        public ?PlanningLine $sourceLine,
        public bool $conditional,
        /** False: required since a reassignment, the duty is not created yet (the completion creates it, D224). */
        public bool $materialized,
        public array $days,
        public PublicationVacancy $vacancy,
    ) {
    }
}
