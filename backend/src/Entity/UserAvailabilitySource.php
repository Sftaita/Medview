<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Where a UserAvailabilityPeriod comes from (docs/surgicalhub-integration.md §6.1).
 *
 * MANUAL periods are the person's own entries: editable, and the only ones
 * bound by the no-overlap/no-touch rule. SURGICAL_HUB periods mirror leave
 * imported from SurgicalHub: read-only in MedVue, changed only by the
 * synchronisation, and free to overlap anything (the person is unavailable
 * on the union). The synchronisation never reads, changes or deletes a
 * MANUAL period.
 */
enum UserAvailabilitySource: string
{
    case MANUAL = 'MANUAL';
    case SURGICAL_HUB = 'SURGICAL_HUB';
}
