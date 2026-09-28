<?php

declare(strict_types=1);

namespace App\Service;

/**
 * A configuration a manager may keep but should understand
 * (docs/decisions.md D162) — never a refusal. A stable `code` the frontend
 * can switch on, the structured `details` it needs (never to be parsed out
 * of the text), and a French `message` ready to show.
 *
 * Codes:
 * - TARGET_HAS_NO_WEEK_STRUCTURE: the conditional line has no weekly
 *   structure yet, so no duty can ever exist to be triggered;
 * - TRIGGER_PERSON_NOT_IN_SOURCE_LINE {userStableId}: the person does not
 *   (or no longer) belong to the source line — the trigger is inert while
 *   they do not hold it;
 * - TRIGGER_DAY_EXCLUDED_FROM_TARGET {userStableId, weekday}: the target's
 *   weekly structure has no duty that day — a reinforcement is impossible
 *   then, whatever the trigger says;
 * - TRIGGER_PARTIALLY_COVERS_TARGET_BLOCK {userStableId, blockName,
 *   blockWeekdays, triggeredWeekdays}: the trigger covers only some days of
 *   a block of the target — a block is atomic, so triggering one of its
 *   days will require the WHOLE block, for one person.
 */
final readonly class DemandPolicyWarning
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $code,
        public array $details,
        public string $message,
    ) {
    }
}
