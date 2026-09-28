<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\LiveCoverageState;
use App\Demand\LiveDemandView;
use App\Eligibility\ExclusionReason;
use App\Entity\Duty;
use App\Entity\DutyAssignment;
use App\Entity\Planning;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningLine;
use App\Repository\DutyAssignmentRepository;
use App\Repository\DutyRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningTeamMemberRepository;

/**
 * "Consultation du planning généré" (docs/decisions.md D130): for each
 * line independently, the D125 rule ("most recent COMPLETED generation") —
 * exactly `PlanningAssignmentViewService::currentGenerations()`, reused
 * here rather than re-derived — plus, unlike the plain assignments list,
 * every REQUIRED Duty of the period whether or not it ended up covered,
 * and the real, already-persisted reasons for an uncovered one
 * (`PlanningGeneration::$diagnostics`). Never recomputes eligibility,
 * never invents a reason: a Duty this generation's diagnostic says nothing
 * about simply carries no reasons.
 */
final class PlanningResultService
{
    public function __construct(
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly DutyRepository $dutyRepository,
        private readonly DutyAssignmentRepository $assignmentRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly ExclusionReasonLabeler $reasonLabeler,
        private readonly LiveDemandViewFactory $demandViewFactory,
    ) {
    }

    /**
     * $from/$toExclusive only restrict which Duty rows are *returned*
     * (same half-open convention as everywhere else) — the coverage counts
     * always cover the whole line's period, exactly like
     * `PlanningAssignmentViewService::summarize()`'s own "whatever month is
     * on screen" rule.
     */
    public function forPlanning(Planning $planning, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $toExclusive = null): PlanningResultView
    {
        // docs/decisions.md D164: whether a duty must be covered is the LIVE demand — intrinsic for an independent
        // line, decided by the source line's current holders for a conditional one — never Duty::isRequired() alone.
        $demand = $this->demandViewFactory->forPlanning($planning);
        $lines = [];
        foreach ($this->lineRepository->findByPlanning($planning) as $line) {
            $lines[] = $this->forLine($line, $from, $toExclusive, $demand);
        }

        return new PlanningResultView($planning, $lines);
    }

    private function forLine(PlanningLine $line, ?\DateTimeImmutable $from, ?\DateTimeImmutable $toExclusive, LiveDemandView $demand): PlanningResultLine
    {
        $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($line->getPlanningPeriod());
        if (null === $generation) {
            return new PlanningResultLine($line, null, null, 0, 0, 0, []);
        }

        /** @var array<int, DutyAssignment> $assignmentByDutyId */
        $assignmentByDutyId = [];
        foreach ($this->assignmentRepository->findForGenerations([$generation]) as $assignment) {
            $assignmentByDutyId[(int) $assignment->getDuty()->getId()] = $assignment;
        }

        $diagnosticsByUnitKey = $this->indexDiagnostics($generation->getDiagnostics());

        $requiredCount = 0;
        $coveredCount = 0;
        $resultDuties = [];

        $undeterminedCount = 0;
        $superfluousCount = 0;
        $notRequiredCount = 0;
        foreach ($this->dutyRepository->findByPlanningPeriod($line->getPlanningPeriod()) as $duty) {
            $dutyDemand = $demand->forDuty($duty);
            $required = $dutyDemand->required;
            $assignment = $assignmentByDutyId[(int) $duty->getId()] ?? null;
            $covered = null !== $assignment;

            // docs/decisions.md D165: a conditional duty carries its live state and explanation, computed here once.
            $unitDemand = null;
            $coverageState = null;
            if ($duty->isConditional()) {
                $unitDemand = $demand->forUnitOf($duty);
                $coverageState = LiveCoverageState::of($dutyDemand, $covered);
                if (LiveCoverageState::UNDETERMINED === $coverageState) {
                    ++$undeterminedCount;
                } elseif (!$coverageState->isRequired()) {
                    ++$notRequiredCount;
                    if ($coverageState->isSuperfluous()) {
                        ++$superfluousCount;
                    }
                }
            }

            if ($required) {
                ++$requiredCount;
                if ($covered) {
                    ++$coveredCount;
                }
            }

            $reasons = $required && !$covered ? $this->reasonsFor($duty, $diagnosticsByUnitKey) : [];

            if (null !== $from && $duty->getLocalDate() < $from) {
                continue;
            }
            if (null !== $toExclusive && $duty->getLocalDate() >= $toExclusive) {
                continue;
            }

            $resultDuties[] = new PlanningResultDuty($duty, $assignment, $covered, $reasons, $required, $unitDemand, $duty->isConditional() ? $dutyDemand : null, $coverageState);
        }

        return new PlanningResultLine(
            $line,
            $generation,
            $generation->getCoverageStatus(),
            $requiredCount,
            $coveredCount,
            $requiredCount - $coveredCount,
            $resultDuties,
            $undeterminedCount,
            $superfluousCount,
            $notRequiredCount,
        );
    }

    /**
     * @param array<string, mixed>|null $diagnostics `PlanningGeneration::$diagnostics`, the exact shape `UnsatReportPresenter::toArray()` produces
     *
     * @return array<string, array<string, mixed>> keyed by dutyUnitStableKey
     */
    private function indexDiagnostics(?array $diagnostics): array
    {
        $byUnitKey = [];
        foreach ($diagnostics['unassignedDuties'] ?? [] as $unassigned) {
            $byUnitKey[(string) $unassigned['dutyUnitStableKey']] = $unassigned;
        }

        return $byUnitKey;
    }

    /**
     * A group Duty is diagnosed under its DutyGroupInstance's stable key
     * (the atomic unit the solver actually decided over, D052) — never the
     * individual Duty's own, so every day of an uncovered group carries the
     * same real reasons, not a partial or inconsistent subset.
     *
     * @param array<string, array<string, mixed>> $diagnosticsByUnitKey
     *
     * @return list<PlanningResultCandidateReason>
     */
    private function reasonsFor(Duty $duty, array $diagnosticsByUnitKey): array
    {
        $unitKey = (string) ($duty->getGroupInstance()?->getStableId() ?? $duty->getStableId());
        $unassigned = $diagnosticsByUnitKey[$unitKey] ?? null;
        if (null === $unassigned) {
            return [];
        }

        $reasons = [];
        foreach ($unassigned['candidateExclusions'] as $candidateExclusion) {
            $member = $this->teamMemberRepository->findOneByStableId((string) $candidateExclusion['candidateId']);
            if (null === $member) {
                // The member row itself is never deleted (docs/planning.md), so this should not
                // happen in practice — skipped rather than fabricating a name.
                continue;
            }

            $labels = array_values(array_unique(array_map(
                fn (array $exclusion): string => $this->reasonLabeler->label(ExclusionReason::from((string) $exclusion['reason'])),
                $candidateExclusion['exclusions'],
            )));

            $reasons[] = new PlanningResultCandidateReason($member->getUser()->getFirstName(), $member->getUser()->getLastName(), $labels);
        }

        return $reasons;
    }
}
