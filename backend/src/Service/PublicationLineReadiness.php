<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningLine;
use App\Entity\PlanningPeriodStatus;

/**
 * One line's own readiness in the period being published
 * (docs/decisions.md D133, D196) — publication is a facade over a per-line
 * lifecycle (PlanningPeriodStatus lives on the line's PlanningPeriod), the
 * same shape as generation (D129, PlanningGenerationLauncher).
 */
final readonly class PublicationLineReadiness
{
    /** Never generated, or not in a publishable status. */
    public const NOT_GENERATED = 'NOT_GENERATED';
    /** A real demand whose configuration is incomplete (LineApplicability INCOMPLETE, D194). */
    public const INCOMPLETE = 'INCOMPLETE';
    public const UNCOVERED_DUTIES = 'UNCOVERED_DUTIES';
    public const INCONSISTENT_GROUPS = 'INCONSISTENT_GROUPS';
    public const INVALID_ASSIGNMENTS = 'INVALID_ASSIGNMENTS';
    public const CONFLICTS = 'CONFLICTS';
    /** D165: a reinforcement whose demand cannot be evaluated. */
    public const UNDETERMINED_REINFORCEMENTS = 'UNDETERMINED_REINFORCEMENTS';

    /**
     * @param list<string> $blockers what keeps this line from being published as it is (codes above); for a line
     *                               already published, what keeps its changes from being republished — an
     *                               uncovered duty excepted (an announced removal, D143)
     */
    public function __construct(
        public PlanningLine $line,
        public PlanningPeriodStatus $periodStatus,
        /** False when there is no COMPLETED generation yet for this line (D125) — nothing to evaluate. */
        public bool $hasGeneration,
        public array $blockers = [],
        /** D196: the line it reinforces (conditional line), whose publication it depends on. */
        public ?PlanningLine $sourceLine = null,
        public ?LineApplicability $applicability = null,
    ) {
    }

    /** DRAFT (never generated) or ARCHIVED (already superseded) — structurally not publishable yet/anymore. */
    public function hasReadyStatus(): bool
    {
        return \in_array($this->periodStatus, [PlanningPeriodStatus::GENERATED, PlanningPeriodStatus::VALIDATED, PlanningPeriodStatus::PUBLISHED], true);
    }

    public function isPublished(): bool
    {
        return PlanningPeriodStatus::PUBLISHED === $this->periodStatus;
    }

    /** D196: never published in this period, and ready to be on its own — its source line aside (checked on the selection). */
    public function isFirstPublishable(): bool
    {
        return !$this->isPublished() && [] === $this->blockers;
    }
}
