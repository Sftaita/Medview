<?php

declare(strict_types=1);

namespace App\Service;

use App\Eligibility\EligibilityExclusion;
use App\Fairness\CandidateExclusionDiagnostic;
use App\Fairness\DiagnosticRelaxation;
use App\Fairness\StructuralDiagnostic;
use App\Fairness\UnassignedDutyDiagnostic;
use App\Fairness\UnsatDiagnostics;
use App\Fairness\UnsatReport;

/**
 * The one place `UnsatReport` (App\Fairness — value objects throughout,
 * never a generic array/JSON blob, docs/planning-solver.md) is turned into
 * a plain array — extracted from `PlanningGenerationController` so the same
 * shape is used both for the transient `POST .../solve` HTTP response and
 * for what `PlanningGenerationService::generate()` persists on
 * `PlanningGeneration::$diagnostics` (docs/decisions.md D130). One
 * serializer, never two array shapes drifting apart.
 */
final class UnsatReportPresenter
{
    /**
     * @return array<string, mixed>|null null whenever there is nothing to report (COMPLETE coverage, or no real diagnostic run)
     */
    public function toArray(?UnsatDiagnostics $diagnostics): ?array
    {
        if (!$diagnostics instanceof UnsatReport) {
            return null;
        }

        return [
            'strictSolverStatus' => $diagnostics->strictSolverStatus->value,
            'partialSolverStatus' => $diagnostics->partialSolverStatus?->value,
            'requiredDutyCount' => $diagnostics->requiredDutyCount,
            'assignedDutyCount' => $diagnostics->assignedDutyCount,
            'unassignedDuties' => array_map(
                static fn (UnassignedDutyDiagnostic $d): array => [
                    'dutyUnitStableKey' => $d->dutyUnitStableKey,
                    'critical' => $d->critical,
                    'candidateExclusions' => array_map(
                        static fn (CandidateExclusionDiagnostic $c): array => [
                            'candidateId' => $c->candidateId,
                            'exclusions' => array_map(
                                static fn (EligibilityExclusion $e): array => ['reason' => $e->reason->value, 'context' => $e->context],
                                $c->exclusions,
                            ),
                        ],
                        $d->candidateExclusions,
                    ),
                ],
                $diagnostics->unassignedDuties,
            ),
            'structuralDiagnostics' => array_map(
                static fn (StructuralDiagnostic $d): array => ['code' => $d->code->value, 'dutyUnitStableKey' => $d->dutyUnitStableKey],
                $diagnostics->structuralDiagnostics,
            ),
            'solverAnalysis' => ['available' => $diagnostics->solverAnalysis->available],
            'diagnosticRelaxations' => array_map(
                static fn (DiagnosticRelaxation $r): array => ['ruleCode' => $r->ruleCode->value, 'tier' => $r->tier->value, 'phrasing' => $r->phrasing, 'disclaimer' => $r->disclaimer],
                $diagnostics->diagnosticRelaxations,
            ),
            'existingDataConflict' => null === $diagnostics->existingDataConflict ? null : [
                'type' => $diagnostics->existingDataConflict->type->value,
                'message' => $diagnostics->existingDataConflict->message,
            ],
        ];
    }
}
