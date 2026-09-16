<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when the set of Duty being materialized for a DutyGroupInstance
 * does not match its DutyPattern's components (offsets and/or duty types)
 * — see docs/allocation-algorithm.md §9/§14.
 */
final class DutyPatternMismatchException extends \RuntimeException
{
}
