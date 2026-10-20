<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\LiveCoverageState;
use App\Demand\LiveDemandView;
use App\Eligibility\ExclusionReason;
use App\Entity\Duty;
use App\Entity\DutyAssignment;
use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningPeriodStatus;
use App\Entity\PlanningTeamMember;
use App\Repository\DutyAssignmentRepository;
use App\Repository\DutyRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineDemandPolicyRepository;
use App\Repository\PlanningLineRepository;

/**
 * Whether the current calendar (docs/decisions.md D133) — never the
 * solver's original historical result — can really be published. An
 * independent defense, not a re-implementation: block coherence reuses the
 * same block-resolution `ReassignmentCandidateService` already uses for a
 * single reassignment, and member validity/conflicts reuse its
 * `firstBlockingReason()` verbatim — never a second, parallel constraint
 * implementation (§6 of the spec). Conflicts and rest are read against every
 * period of the planning (PersonCommitmentReader, lot L3): a boundary between
 * two periods is checked like any other day.
 *
 * Since docs/decisions.md D196 (lot L4c), one period at a time, with a
 * verdict per line: published or not, publishable for the first time on its
 * own, and why not. A line not yet published never plays a part in
 * whether the published ones can be republished.
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
        private readonly LineApplicabilityService $applicability,
        private readonly PlanningLineDemandPolicyRepository $policyRepository,
    ) {
    }

    /**
     * $cycle: the period checked. Omitted: the planning's only period
     * (refused when it has several, MultiplePeriodsNotSupportedException).
     */
    public function check(Planning $planning, ?PlanningCycle $cycle = null): PublicationPreflight
    {
        $cycle ??= $planning->getOnlyCycle();
        $lineReadiness = [];
        $uncoveredDuties = [];
        $inconsistentGroups = [];
        $invalidAssignments = [];
        $conflicts = [];
        $undeterminedDuties = [];
        $superfluousCoverages = [];
        $neutralLines = [];
        $demand = $this->demandViewFactory->forPlanning($planning);

        foreach ($this->lineRepository->findByPlanning($planning) as $line) {
            // A line without period in this cycle (docs/planning-cycles.md §5.5) has nothing to publish in it.
            $period = $line->isActive() ? $line->periodIn($cycle) : null;
            if (null === $period) {
                continue;
            }

            // docs/decisions.md D194: a line with nothing demanded in the period is neutral — listed apart, never a
            // reason to block the others (Trauma Delta: an empty DRAFT secondary line blocked every republication).
            // A single duty of the period, ponctual or conditional, makes it count again.
            $applicability = $this->applicability->forLine($line, $cycle);
            if (!$applicability->counts()) {
                $neutralLines[] = new PublicationNeutralLine($line, $period->getStatus(), $applicability);
                continue;
            }

            $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($period);
            $basic = new PublicationLineReadiness($line, $period->getStatus(), null !== $generation);
            $blockers = [];
            if (LineApplicability::INCOMPLETE === $applicability->state) {
                $blockers[] = PublicationLineReadiness::INCOMPLETE;
            }
            if (!$basic->hasReadyStatus() || null === $generation) {
                $blockers[] = PublicationLineReadiness::NOT_GENERATED;
            } else {
                $issues = ['uncovered' => [], 'inconsistent' => [], 'invalid' => [], 'conflicts' => [], 'undetermined' => [], 'superfluous' => []];
                $this->checkLine($line, $period, $generation, $demand, $issues['uncovered'], $issues['inconsistent'], $issues['invalid'], $issues['conflicts'], $issues['undetermined'], $issues['superfluous']);
                array_push($uncoveredDuties, ...$issues['uncovered']);
                array_push($inconsistentGroups, ...$issues['inconsistent']);
                array_push($invalidAssignments, ...$issues['invalid']);
                array_push($conflicts, ...$issues['conflicts']);
                array_push($undeterminedDuties, ...$issues['undetermined']);
                array_push($superfluousCoverages, ...$issues['superfluous']);

                // Republication (docs/decisions.md D143): a duty deliberately left uncovered after the line went out
                // ("Retirer l'affectation") is itself news worth announcing — so an uncovered duty only blocks a line
                // never published yet (its first PUBLISHED transition requires full coverage,
                // PlanningPeriodLifecycleService).
                if ([] !== $issues['uncovered'] && !$basic->isPublished()) {
                    $blockers[] = PublicationLineReadiness::UNCOVERED_DUTIES;
                }
                foreach (['inconsistent' => PublicationLineReadiness::INCONSISTENT_GROUPS, 'invalid' => PublicationLineReadiness::INVALID_ASSIGNMENTS, 'conflicts' => PublicationLineReadiness::CONFLICTS, 'undetermined' => PublicationLineReadiness::UNDETERMINED_REINFORCEMENTS] as $key => $code) {
                    if ([] !== $issues[$key]) {
                        $blockers[] = $code;
                    }
                }
            }

            $lineReadiness[] = new PublicationLineReadiness($line, $period->getStatus(), null !== $generation, $blockers, $this->conditionalSourceOf($line, $cycle), $applicability);
        }

        $published = array_filter($lineReadiness, static fn (PublicationLineReadiness $line): bool => $line->isPublished());
        // All or nothing (historical meaning of "Publier"): nothing published in the period yet, every line ready.
        $publishable = [] !== $lineReadiness
            && [] === $published
            && [] === array_filter($lineReadiness, static fn (PublicationLineReadiness $line): bool => !$line->isFirstPublishable());
        $republishable = [] !== $published
            && [] === array_filter($published, static fn (PublicationLineReadiness $line): bool => [] !== $line->blockers);

        return new PublicationPreflight(
            $publishable,
            $lineReadiness,
            $uncoveredDuties,
            $inconsistentGroups,
            $invalidAssignments,
            $conflicts,
            $republishable,
            $undeterminedDuties,
            $superfluousCoverages,
            $neutralLines,
            $cycle,
            $this->earlierUnpublished($planning, $cycle),
        );
    }

    /**
     * docs/decisions.md D196: the earlier periods holding a line that counts and is not published — publishing this
     * one first is allowed (EARLIER_PERIOD_UNPUBLISHED, a warning).
     *
     * @return list<int>
     */
    private function earlierUnpublished(Planning $planning, PlanningCycle $cycle): array
    {
        $numbers = [];
        foreach ($planning->getCycles() as $earlier) {
            if ($earlier->getNumber() >= $cycle->getNumber()) {
                continue;
            }
            foreach ($this->lineRepository->findByPlanning($planning) as $line) {
                $period = $line->isActive() ? $line->periodIn($earlier) : null;
                if (null !== $period && PlanningPeriodStatus::PUBLISHED !== $period->getStatus() && PlanningPeriodStatus::ARCHIVED !== $period->getStatus()
                    && $this->applicability->forLine($line, $earlier)->counts()) {
                    $numbers[] = $earlier->getNumber();
                    break;
                }
            }
        }

        return $numbers;
    }

    /** The line $line reinforces in $cycle (its demand policy in force is conditional, D162), null otherwise. */
    private function conditionalSourceOf(PlanningLine $line, PlanningCycle $cycle): ?PlanningLine
    {
        $policy = $this->policyRepository->findActiveForLine($line);
        $source = null !== $policy && $policy->getMode()->isConditional() ? $policy->getSourceLine() : null;

        return null !== $source && null !== $source->periodIn($cycle) ? $source : null;
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
        PlanningPeriod $period,
        PlanningGeneration $generation,
        LiveDemandView $demand,
        array &$uncoveredDuties,
        array &$inconsistentGroups,
        array &$invalidAssignments,
        array &$conflicts,
        array &$undeterminedDuties,
        array &$superfluousCoverages,
    ): void {
        $duties = $this->dutyRepository->findByPlanningPeriod($period);

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
