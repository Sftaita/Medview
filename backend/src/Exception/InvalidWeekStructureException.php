<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown by WeekStructureService::replace() when a submitted week
 * structure violates one of its invariants (docs/decisions.md D136 §15):
 * a day claimed by two entries, a day marked "no duty" also appearing in a
 * block/solo entry, an empty block, or an unknown/malformed day code —
 * caught by the controller and returned as 422, never silently corrected.
 */
final class InvalidWeekStructureException extends \InvalidArgumentException
{
}
