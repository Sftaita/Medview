<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AvailabilityCollectionResponse;
use App\Entity\PlanningAvailabilityReminder;
use App\Entity\TeamMemberNonParticipationPeriod;
use App\Entity\TeamMemberParticipationPeriod;
use App\Entity\UserAvailabilityPeriod;

/**
 * Everything the member drawer shows for one participant of a planning.
 * Unavailabilities are the person's own UserAvailabilityPeriod rows
 * intersecting the planning period — read live, never copied per planning.
 */
final readonly class MemberAvailabilityDetail
{
    /**
     * @param list<AvailabilityCollectionResponse>   $responses               per relevant collection
     * @param list<UserAvailabilityPeriod>           $unavailabilities        UNAVAILABLE only
     * @param list<UserAvailabilityPeriod>           $preferences             PREFER_DUTY only, kept apart on purpose
     * @param list<TeamMemberParticipationPeriod>    $participationPeriods
     * @param list<TeamMemberNonParticipationPeriod> $nonParticipationPeriods
     * @param list<PlanningAvailabilityReminder>     $reminders               newest first
     */
    public function __construct(
        public MemberCollectionRow $row,
        public array $responses,
        public array $unavailabilities,
        public array $preferences,
        public array $participationPeriods,
        public array $nonParticipationPeriods,
        public array $reminders,
    ) {
    }
}
