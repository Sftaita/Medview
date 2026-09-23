<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AvailabilityAcknowledgementKind;
use App\Entity\PlanningTeamMember;

/**
 * One line of the "collection status" list of a planning: a participant, where
 * they stand in the collection, and how many unavailabilities the planning
 * period already holds for them. Read model only.
 */
final readonly class MemberCollectionRow
{
    public function __construct(
        public PlanningTeamMember $member,
        public MemberCollectionState $state,
        /** Latest confirmation among the relevant collections. */
        public ?\DateTimeImmutable $acknowledgedAt,
        public ?AvailabilityAcknowledgementKind $acknowledgementKind,
        public ?\DateTimeImmutable $lastAvailabilityChangeAt,
        /** UNAVAILABLE periods of the person intersecting the planning period. */
        public int $unavailabilityCount,
        public ?\DateTimeImmutable $lastReminderAt,
        /** Relevant (open) collections still unanswered by the person. */
        public int $pendingCollectionCount,
    ) {
    }
}
