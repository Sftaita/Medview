<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * The final revalidation of a swap refused it (docs/duty-swaps.md §6) —
 * nothing was written. $reason is a stable code (one of the constants
 * below, or an ExclusionReason value when one of the two people cannot
 * take the other's duty); $party says whose situation is at fault
 * ('requester' or 'counterpart') when it is about one person.
 */
final class DutySwapNotApplicableException extends \RuntimeException
{
    /** The period is not (or no longer) PUBLISHED — draft or archived. */
    public const PERIOD_NOT_PUBLISHED = 'period_not_published';
    /** One of the two units has already started (or ended). */
    public const DUTY_STARTED = 'duty_started';
    /** One of the two units is no longer held exactly as it was when the request/proposal was made. */
    public const DUTY_CHANGED = 'duty_changed';
    /** One of the current rows is locked: only a manager may change it. */
    public const DUTY_LOCKED = 'duty_locked';
    /** The two units are not of the same line, or are the same unit. */
    public const NOT_SWAPPABLE = 'not_swappable';
    /** A reinforcement the live demand does not require (or cannot evaluate) never takes a new holder (D165). */
    public const COVERAGE_NOT_REQUIRED = 'coverage_not_required';
    /** The swap would change which reinforcements are required on a conditional line depending on these duties. */
    public const CHANGES_REINFORCEMENTS = 'changes_reinforcements';

    public function __construct(
        public readonly string $reason,
        public readonly ?string $party = null,
        string $message = 'This swap cannot be applied.',
    ) {
        parent::__construct($message);
    }
}
