<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningCycle;
use App\Entity\PlanningLine;
use App\Entity\PlanningPublication;

/**
 * Where one period of a Planning stands with respect to its diffusion
 * (docs/decisions.md D143, D196): never published, or published — first and
 * last diffusion of the period — with the changes made since the last one to
 * its published lines ("Modifications non publiées" exactly when `$changes`
 * is not empty), and the lines generated but never published in it.
 */
final readonly class PublicationState
{
    /**
     * @param list<PublicationChange>   $changes                  on the period's published lines only
     * @param list<PlanningPublication> $history                  the period's, oldest first
     * @param list<PlanningLine>        $linesAwaitingPublication generated (or validated) lines of the period never published in it
     */
    public function __construct(
        public ?PlanningPublication $first,
        public ?PlanningPublication $latest,
        public array $changes,
        public array $history,
        public ?PlanningCycle $cycle = null,
        public array $linesAwaitingPublication = [],
    ) {
    }

    public function isPublished(): bool
    {
        return null !== $this->latest;
    }

    public function hasUnpublishedChanges(): bool
    {
        return [] !== $this->changes;
    }
}
