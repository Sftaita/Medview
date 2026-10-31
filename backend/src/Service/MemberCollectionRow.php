<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningTeamMember;

/**
 * One line of the availability list of a planning: a participant, how many
 * unavailabilities the period holds for them and when they last changed their
 * calendar on it. Read model only. docs/decisions.md D214: no confirmation
 * state — a person without any unavailability is available.
 */
final readonly class MemberCollectionRow
{
    public function __construct(
        public PlanningTeamMember $member,
        /** Takes part in a relevant collection: told by the announcement and the reminders (false: left before, or an inactive account). */
        public bool $participant,
        /** The last change of their calendar on the period's dates (the journal, D215), or an older note of an open collection. */
        public ?\DateTimeImmutable $lastAvailabilityChangeAt,
        /** UNAVAILABLE periods of the person intersecting the period. */
        public int $unavailabilityCount,
        public ?\DateTimeImmutable $lastReminderAt,
    ) {
    }
}
