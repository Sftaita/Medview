<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Structural incoherence caught by DutyAssignmentService before a
 * DutyAssignment is even constructed (docs/planning-generation.md §18 —
 * "pas d'incohérences structurelles triviales", deliberately short of a
 * full EligibilityService: no availability/spacing/fairness checks here).
 * One class for the whole family of pre-construction checks (wrong
 * PlanningPeriod, wrong Team, TeamMember absent from the snapshot) since
 * they share the same 422 treatment and the message alone distinguishes
 * them for the API consumer.
 */
final class InvalidDutyAssignmentException extends \RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }
}
