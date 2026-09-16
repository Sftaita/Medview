<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Lifecycle from docs/allocation-algorithm.md §18. Transitions are not
 * arbitrary — see PlanningPeriodStatus::canTransitionTo() and
 * PlanningPeriodLifecycleService, the only legal way to move between them.
 */
enum PlanningPeriodStatus: string
{
    case DRAFT = 'DRAFT';
    case GENERATED = 'GENERATED';
    case VALIDATED = 'VALIDATED';
    case PUBLISHED = 'PUBLISHED';
    case ARCHIVED = 'ARCHIVED';

    /**
     * @return list<self>
     */
    private function allowedTargets(): array
    {
        return match ($this) {
            // A generation attempt runs against the draft.
            self::DRAFT => [self::GENERATED],
            // Either validated, or regenerated again (stays GENERATED —
            // not modeled as a "transition" since it's the same status).
            self::GENERATED => [self::VALIDATED],
            // Publishing requires having been validated; regenerating
            // after validation invalidates it (must move back through
            // GENERATED and be re-validated before it can publish again).
            self::VALIDATED => [self::PUBLISHED, self::GENERATED],
            // A published period is never edited back into an earlier
            // status (docs/allocation-algorithm.md §18) — only archived
            // once superseded.
            self::PUBLISHED => [self::ARCHIVED],
            // Terminal: archived periods are never reopened.
            self::ARCHIVED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTargets(), true);
    }
}
