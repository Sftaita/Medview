<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningPublication;

/**
 * Where a Planning stands with respect to its diffusion
 * (docs/decisions.md D143): never published, or published — first and
 * last diffusion — with the changes made since the last one ("Modifications
 * non publiées" exactly when `$changes` is not empty).
 */
final readonly class PublicationState
{
    /**
     * @param list<PublicationChange>   $changes
     * @param list<PlanningPublication> $history oldest first
     */
    public function __construct(
        public ?PlanningPublication $first,
        public ?PlanningPublication $latest,
        public array $changes,
        public array $history,
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
