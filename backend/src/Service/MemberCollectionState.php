<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Where one member stands in the availability collection of a planning,
 * derived from their AvailabilityCollectionResponse rows — never from the
 * presence or absence of unavailabilities (docs/decisions.md D120, D128):
 * someone with zero unavailabilities who never confirmed is PENDING.
 */
enum MemberCollectionState: string
{
    /** At least one relevant collection has not been answered yet ("En attente"). */
    case PENDING = 'PENDING';

    /** Every relevant collection was explicitly answered — "confirmed" in the UI (CONFIRMED / NO_UNAVAILABILITY). */
    case ACKNOWLEDGED = 'ACKNOWLEDGED';

    /** Not counted: no expected response (left before answering, or an inactive account). */
    case NOT_EXPECTED = 'NOT_EXPECTED';
}
