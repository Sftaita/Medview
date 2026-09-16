<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Deliberately narrow to the cycle this lot can actually implement — no
 * solver exists yet, so COMPLETED/FAILED (which would represent a finished
 * solve attempt) are not modeled here; inventing them now would be a status
 * this code can never legitimately reach. See PlanningGeneration::transitionTo()
 * for the only legal way to move between these.
 */
enum PlanningGenerationStatus: string
{
    case DRAFT = 'DRAFT';
    case SNAPSHOTTED = 'SNAPSHOTTED';

    /**
     * @return list<self>
     */
    private function allowedTargets(): array
    {
        return match ($this) {
            self::DRAFT => [self::SNAPSHOTTED],
            // Terminal in this lot: a future solver lot adds the next edge
            // (e.g. SNAPSHOTTED => COMPLETED/FAILED), not invented here.
            self::SNAPSHOTTED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return \in_array($target, $this->allowedTargets(), true);
    }
}
