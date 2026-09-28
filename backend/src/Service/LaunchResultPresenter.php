<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\UserAvailabilityType;
use App\Fairness\CoverageStatus;

/**
 * The per-line summary of a planning-level generation (docs/decisions.md
 * D129/D137) — computed once by the worker and stored as the PlanningJob's
 * outcome (D149), so the API returns exactly what the synchronous launch
 * used to return, whenever it is read.
 */
final class LaunchResultPresenter
{
    public function __construct(
        private readonly UnsatReportPresenter $unsatReportPresenter,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function lineToArray(LaunchLineResult $line): array
    {
        $generation = $line->generation;
        $result = $line->result;

        $snapshot = null;
        if (null !== $line->snapshot) {
            $unavailable = 0;
            foreach ($line->snapshot->getMembers() as $member) {
                foreach ($member->getAvailabilityPeriods() as $period) {
                    if (UserAvailabilityType::UNAVAILABLE === $period->getType()) {
                        ++$unavailable;
                    }
                }
            }
            $snapshot = [
                'capturedAt' => $line->snapshot->getCreatedAt()->format(\DATE_ATOM),
                'memberCount' => \count($line->snapshot->getMembers()),
                'unavailableCount' => $unavailable,
            ];
        }

        return [
            'lineStableId' => (string) $line->line->getStableId(),
            'lineName' => $line->line->getName(),
            // Null only for a conditional line that was not resolved because its source line failed (D164).
            'generationStableId' => null !== $generation ? (string) $generation->getStableId() : null,
            'status' => $generation?->getStatus()->value,
            'error' => $line->error,
            'coverageStatus' => $result?->coverageStatus->value,
            'strictSolverStatus' => $result?->strictSolverStatus->value,
            'partialSolverStatus' => $result?->partialSolverStatus?->value,
            'assignmentCount' => null !== $result ? \count($result->assignments) : null,
            'unassignedDutyCount' => null !== $result ? \count($result->unassignedDuties) : null,
            // docs/decisions.md D137: OPTIMAL vs FEASIBLE must never be
            // conflated in the UI (§20 of the spec) — the planning-level
            // façade previously dropped this, forcing a caller to the
            // per-period endpoint just to know whether optimality was
            // actually proven.
            'optimality' => $result?->optimality,
            'diagnostics' => null !== $result && CoverageStatus::INCOMPLETE === $result->coverageStatus
                ? $this->unsatReportPresenter->toArray($result->diagnostics)
                : null,
            'snapshot' => $snapshot,
            // docs/decisions.md D164: a conditional line's demand, per unit — null for an independent line.
            'demand' => null === $line->demand ? null : [
                'requiredUnitCount' => $line->demand->requiredUnitCount,
                'notRequiredUnitCount' => $line->demand->notRequiredUnitCount,
                'undeterminedUnitCount' => $line->demand->undeterminedUnitCount,
            ],
        ];
    }
}
