<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Audit metadata only (docs/allocation-algorithm.md §20) — never a second
 * computation path. Every reason here changes participationFactor through
 * the exact same mechanism (a new TeamMemberParticipationPeriod segment);
 * only the label explaining *why* differs.
 */
enum ParticipationFactorChangeReason: string
{
    /** The member's very first period, opened alongside their TeamMember. */
    case INITIAL = 'INITIAL';
    case ADMINISTRATIVE_LEAVE = 'ADMINISTRATIVE_LEAVE';
    case SUSPENSION = 'SUSPENSION';
    case CONTRACTUAL_CHANGE = 'CONTRACTUAL_CHANGE';
    case RETURN_TO_FULL_PARTICIPATION = 'RETURN_TO_FULL_PARTICIPATION';
    /** Membership closed: the timeline's open segment is capped, not a real factor change. */
    case MEMBERSHIP_ENDED = 'MEMBERSHIP_ENDED';
}
