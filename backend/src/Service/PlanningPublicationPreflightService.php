<?php

declare(strict_types=1);

namespace App\Service;

use App\Eligibility\ExclusionReason;
use App\Entity\Duty;
use App\Entity\DutyAssignment;
use App\Entity\Planning;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningLine;
use App\Entity\PlanningTeamMember;
use App\Repository\DutyAssignmentRepository;
use App\Repository\DutyRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineRepository;

/**
 * Whether the current calendar (docs/decisions.md D133) — never the
 * solver's original historical result — can really be published. An
 * independent defense, not a re-implementation: block coherence reuses the
 * same block-resolution `ReassignmentCandidateService` already uses for a
 * single reassignment, and member validity/conflicts reuse its
 * `firstBlockingReason()` verbatim — never a second, parallel constraint
 * implementation (§6 of the spec).
 */
final class PlanningPublicationPreflightService
{
    public function __construct(
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly DutyRepository $dutyRepository,
        private readonly DutyAssignmentRepository $assignmentRepository,
        private readonly ReassignmentCandidateService $candidateService,
        private readonly ExclusionReasonLabeler $reasonLabeler,
    ) {
    }

    public function check(Planning $planning): PublicationPreflight
    {
        $lineReadiness = [];
        $uncoveredDuties = [];
        $inconsistentGroups = [];
        $invalidAssignments = [];
        $conflicts = [];
        $allReady = true;

        foreach ($this->lineRepository->findByPlanning($planning) as $line) {
            if (!$line->isActive()) {
                continue;
            }

            $period = $line->getPlanningPeriod();
            $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($period);
            $readiness = new PublicationLineReadiness($line, $period->getStatus(), null !== $generation);
            $lineReadiness[] = $readiness;

            if (!$readiness->hasReadyStatus() || null === $generation) {
                $allReady = false;
                continue;
            }

            $this->checkLine($line, $generation, $uncoveredDuties, $inconsistentGroups, $invalidAssignments, $conflicts);
        }

        $publishable = $allReady
            && [] === $uncoveredDuties
            && [] === $inconsistentGroups
            && [] === $invalidAssignments
            && [] === $conflicts
            && [] !== $lineReadiness;

        return new PublicationPreflight($publishable, $lineReadiness, $uncoveredDuties, $inconsistentGroups, $invalidAssignments, $conflicts);
    }

    /**
     * @param list<UncoveredPublicationDuty>     $uncoveredDuties
     * @param list<InconsistentPublicationGroup> $inconsistentGroups
     * @param list<InvalidPublicationAssignment> $invalidAssignments
     * @param list<PublicationConflict>          $conflicts
     */
    private function checkLine(
        PlanningLine $line,
        PlanningGeneration $generation,
        array &$uncoveredDuties,
        array &$inconsistentGroups,
        array &$invalidAssignments,
        array &$conflicts,
    ): void {
        $duties = $this->dutyRepository->findByPlanningPeriod($line->getPlanningPeriod());

        /** @var array<int, DutyAssignment> $currentByDutyId */
        $currentByDutyId = [];
        foreach ($this->assignmentRepository->findForGenerations([$generation]) as $assignment) {
            $currentByDutyId[(int) $assignment->getDuty()->getId()] = $assignment;
        }

        $seenGroupIds = [];
        foreach ($duties as $duty) {
            if ($duty->isRequired() && !isset($currentByDutyId[(int) $duty->getId()])) {
                $uncoveredDuties[] = new UncoveredPublicationDuty($duty);
            }

            $group = $duty->getGroupInstance();
            if (null !== $group) {
                if (isset($seenGroupIds[(int) $group->getId()])) {
                    continue;
                }
                $seenGroupIds[(int) $group->getId()] = true;
            }

            $block = $this->candidateService->blockDuties($duty);
            $assignees = [];
            foreach ($block as $blockDuty) {
                $assignment = $currentByDutyId[(int) $blockDuty->getId()] ?? null;
                if (null !== $assignment) {
                    $assignees[(int) $assignment->getTeamMember()->getId()] = $assignment->getTeamMember();
                }
            }

            if (\count($assignees) > 1) {
                if (null !== $group) {
                    $inconsistentGroups[] = new InconsistentPublicationGroup($group);
                }
                continue;
            }
            if ([] === $assignees) {
                continue;
            }

            $member = array_values($assignees)[0];
            $reason = $this->candidateService->firstBlockingReason($generation, $block, $member);
            if (null === $reason) {
                continue;
            }

            $this->classify($duty, $member, $reason, $invalidAssignments, $conflicts);
        }
    }

    /**
     * @param list<InvalidPublicationAssignment> $invalidAssignments
     * @param list<PublicationConflict>          $conflicts
     */
    private function classify(
        Duty $duty,
        PlanningTeamMember $member,
        ExclusionReason $reason,
        array &$invalidAssignments,
        array &$conflicts,
    ): void {
        $label = $this->reasonLabeler->label($reason);
        match ($reason) {
            ExclusionReason::CONFLICT, ExclusionReason::LEGAL_MIN_REST, ExclusionReason::TEAM_MIN_REST => $conflicts[] = new PublicationConflict($duty, $member, $label),
            default => $invalidAssignments[] = new InvalidPublicationAssignment($duty, $member, $label),
        };
    }
}
