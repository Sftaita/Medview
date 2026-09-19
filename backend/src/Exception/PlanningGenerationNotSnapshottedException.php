<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * A DutyAssignment always carries a PlanningSnapshotMember (D062,
 * docs/planning-generation.md "Assignment et snapshot") so it stays
 * historically interpretable even if the TeamMember later leaves — which
 * means no assignment can be created before the generation has a snapshot
 * to resolve that snapshot member from.
 */
final class PlanningGenerationNotSnapshottedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This PlanningGeneration has no snapshot yet; snapshot it before creating assignments.');
    }
}
