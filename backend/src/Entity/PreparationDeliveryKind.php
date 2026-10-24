<?php

declare(strict_types=1);

namespace App\Entity;

enum PreparationDeliveryKind: string
{
    /** The invitation sent when the collection is announced. At most one per person and period. */
    case ANNOUNCEMENT = 'ANNOUNCEMENT';
    /** One scheduled reminder, to the people who have not confirmed. */
    case REMINDER = 'REMINDER';
}
