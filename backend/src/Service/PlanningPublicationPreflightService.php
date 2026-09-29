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
use App\Entity\PlanningPeriodStatus;
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
        private readonly LiveDemandViewFactory $demandViewFactory,
    ) {
    }

    public function check(Planning $planning): PublicationPreflight
    {
        $lineReadiness = [];
        $uncoveredDuties = [];
        $inconsistentGroups = [];
        $invalidAssignments = [];
        $conflicts = [];
        $undeterminedDuties = [];
        $superfluousCoverages = [];
        $allReady = true;
        $demand = $this->demandViewFactory->forPlanning($planning);

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

            $this->checkLine($line, $generation, $demand, $uncoveredDuties, $inconsistentGroups, $invalidAssignments, $conflicts, $undeterminedDuties, $superfluousCoverages);
        }

        $coherent = $allReady
            && [] === $inconsistentGroups
            && [] === $invalidAssignments
            && [] === $conflicts
            // docs/decisions.md D165: a reinforcement whose demand cannot be evaluated is never "fine" — it blocks a
            // publication and a republication alike, until its source duty has a holder again.
            && [] === $undeterminedDuties
            && [] !== $lineReadiness;

        $publishable = $coherent && [] === $uncoveredDuties;

        // Republication (docs/decisions.md D143): a duty deliberately left uncovered after the
        // planning went out ("Retirer l'affectation") is itself news worth announcing — so an
        // uncovered duty only blocks on a line that has never been published yet (its first
        // PUBLISHED transition still requires full coverage, PlanningPeriodLifecycleService).
        $republishable = $coherent && [] === array_filter(
            $uncoveredDuties,
            static fn (UncoveredPublicationDuty $item): bool => PlanningPeriodStatus::PUBLISHED !== $item->duty->getPlanningPeriod()->getStatus(),
        );

        return new PublicationPreflight($publishable, $lineReadiness, $uncoveredDuties, $inconsistentGroups, $invalidAssignments, $conflicts, $republishable, $undeterminedDuties, $superfluousCoverages);
    }

    /**
     * @param list<UncoveredPublicationDuty>     $uncoveredDuties
     * @param list<InconsistentPublicationGroup> $inconsistentGroups
     * @param list<InvalidPublicationAssignment> $invalidAssignments
     * @param list<PublicationConflict>          $conflicts
     * @param list<ConditionalPublicationDuty>   $undeterminedDuties
     * @param list<ConditionalPublicationDuty>   $superfluousCoverages
     */
    private function checkLine(
        PlanningLine $line,
        PlanningGeneration $generation,
        LiveDemandView $demand,
        array &$uncoveredDuties,
        array &$inconsistentGroups,
        array &$invalidAssignments,
        array &$conflicts,
        array &$undeterminedDuties,
        array &$superfluousCoverages,
    ): void {
        $duties = $this->dutyRepository->findByPlanningPeriod($line->getPlanningPeriod());

        /** @var array<int, DutyAssignment> $currentByDutyId */
        $currentByDutyId = [];
        foreach ($this->assignmentRepository->findForGenerations([$generation]) as $assignment) {
            $currentByDutyId[(int) $assignment->getDuty()->getId()] = $assignment;
        }

        $seenGroupIds = [];
        $seenConditionalUnits = [];
        foreach ($duties as $duty) {
            // The live demand (D164): a conditional duty only counts when its source holder triggers it.
            $dutyDemand = $demand->forDuty($duty);
            $current = $currentByDutyId[(int) $duty->getId()] ?? null;
            if ($dutyDemand->required && null === $current) {
                $uncoveredDuties[] = new UncoveredPublicationDuty($duty);
            }
            // D165: required + assigned → OK; required + unassigned → uncovered (above); not required + unassigned → OK;
            // not required + assigned → warning; undetermined → blocker.
            // D166: one structured item per unit (a block once), with its line, holder and explanation.
            $unitKey = (string) ($duty->getGroupInstance()?->getStableId() ?? $duty->getStableId());
            if ($duty->isConditional() && !isset($seenConditionalUnits[$unitKey])) {
                $seenConditionalUnits[$unitKey] = true;
                $state = LiveCoverageState::of($dutyDemand, null !== $current);
                $code = match (true) {
                    LiveCoverageState::UNDETERMINED === $state => ConditionalPublicationDuty::UNDETERMINED,
                    $state->isSuperfluous() => ConditionalPublicationDuty::SUPERFLUOUS,
                    default => null,
                };
                if (null !== $code) {
                    $item = new ConditionalPublicationDuty($code, $line, $duty, $this->candidateService->blockDuties($duty), $current?->getTeamMember(), $demand->forUnitOf($duty), $dutyDemand, $state);
                    if (ConditionalPublicationDuty::UNDETERMINED === $code) {
                        $undeterminedDuties[] = $item;
                    } else {
                        $superfluousCoverages[] = $item;
                    }
                }
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
                    $inconsistentGroups[] = new InconsistentPublicationGroup($group, $block, array_values($assignees));
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

            $this->classify($block, $member, $reason, $invalidAssignments, $conflicts);
        }
    }

    /**
     * @param list<Duty>                         $block
     * @param list<InvalidPublicationAssignment> $invalidAssignments
     * @param list<PublicationConflict>          $conflicts
     */
    private function classify(
        array $block,
        PlanningTeamMember $member,
        ExclusionReason $reason,
        array &$invalidAssignments,
        array &$conflicts,
    ): void {
        $label = $this->reasonLabeler->label($reason);
        $duty = $block[0];
        match ($reason) {
            ExclusionReason::CONFLICT, ExclusionReason::LEGAL_MIN_REST, ExclusionReason::TEAM_MIN_REST,
            ExclusionReason::CROSS_LINE_CONFLICT, ExclusionReason::CROSS_LINE_LEGAL_MIN_REST, ExclusionReason::CROSS_LINE_TEAM_MIN_REST => $conflicts[] = new PublicationConflict($duty, $member, $label, $block, $reason),
            default => $invalidAssignments[] = new InvalidPublicationAssignment($duty, $member, $label, $block, $reason),
        };
    }
}
