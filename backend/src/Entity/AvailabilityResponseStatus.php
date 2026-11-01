<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Derived from AvailabilityCollectionResponse's own columns, never stored
 * (docs/decisions.md D214): PARTICIPANT while the person takes part in the
 * window, WITHDRAWN once they left the planning before it. There is no
 * "answered" state any more: nobody is asked to confirm, and a person without
 * any unavailability is simply available.
 */
enum AvailabilityResponseStatus: string
{
    case PARTICIPANT = 'PARTICIPANT';
    case WITHDRAWN = 'WITHDRAWN';
}
