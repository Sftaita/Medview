<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningLine;
use App\Fairness\SolverStatus;

/**
 * What "Compléter automatiquement" did on one line (docs/decisions.md
 * D145). `$status`:
 * - `completed`: the solve ran, `$filledUnitCount` holes were filled
 *   (possibly 0 when nobody could take any of them);
 * - `nothing_to_complete`: no uncovered unit on this line;
 * - `not_generated`: no COMPLETED generation yet — "Générer" first;
 * - `solver_failed`: the solver produced no usable result, nothing written.
 */
final readonly class CompletionLineResult
{
    public function __construct(
        public PlanningLine $line,
        public string $status,
        public int $holeCount = 0,
        public int $filledUnitCount = 0,
        public int $remainingUncoveredRequiredUnitCount = 0,
        public ?SolverStatus $strictSolverStatus = null,
        public ?SolverStatus $partialSolverStatus = null,
    ) {
    }

    /**
     * @return array<string, mixed> the API/outcome shape (docs/decisions.md D145/D149)
     */
    public function toArray(): array
    {
        return [
            'lineStableId' => (string) $this->line->getStableId(),
            'lineName' => $this->line->getName(),
            'status' => $this->status,
            'holeCount' => $this->holeCount,
            'filledUnitCount' => $this->filledUnitCount,
            'remainingUncoveredRequiredUnitCount' => $this->remainingUncoveredRequiredUnitCount,
            'strictSolverStatus' => $this->strictSolverStatus?->value,
            'partialSolverStatus' => $this->partialSolverStatus?->value,
        ];
    }
}
