<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\DutyAssignment;

/**
 * One Duty of a line's current generation, covered or not — the read
 * model behind "consultation du planning généré" (docs/decisions.md D130).
 * A REQUIRED Duty always appears here, whether or not it ended up
 * assigned: unlike the plain assignments list
 * (`PlanningAssignmentViewService`), an uncovered Duty is never silently
 * absent.
 */
final readonly class PlanningResultDuty
{
    /**
     * @param list<PlanningResultCandidateReason> $reasons only when uncovered and required, and only real, already-computed reasons — empty when none are known
     */
    public function __construct(
        public Duty $duty,
        public ?DutyAssignment $assignment,
        public bool $covered,
        public array $reasons = [],
    ) {
    }
}
