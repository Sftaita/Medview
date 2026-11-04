<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\DemandReason;
use App\Demand\LiveCoverageState;
use App\Demand\LiveDemandView;
use App\Demand\Weekday;
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
use App\Repository\PlanningTeamMemberRepository;

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
        private readonly InheritedReinforcementService $inheritedReinforcements,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
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
        $uncoveredUnits = [];
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
                // docs/decisions.md D232: what these lines still need, said with its dates, source holders and reasons,
                // including a reinforcement required since a reassignment, whose duty does not exist yet (D224).
                // On a published line they never block (D143): they are told before the republication.
                $source = $this->conditionalSourceOf($line, $cycle);
                array_push($uncoveredUnits, ...$this->uncoveredUnits($line, $source, $generation, $demand, $issues['uncovered'], $basic->isPublished()));
                $missing = $this->missingReinforcements($line, $source, $period, $basic->isPublished());
                if ([] !== $missing) {
                    if (!$basic->isPublished()) {
                        $blockers[] = PublicationLineReadiness::MISSING_REINFORCEMENTS;
                    }
                    array_push($uncoveredUnits, ...$missing);
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
            $uncoveredUnits,
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

    /**
     * The uncovered duties of a line, one unit per block, each with why it is required and why it is empty.
     *
     * @param list<UncoveredPublicationDuty> $uncovered
     *
     * @return list<PublicationUncoveredUnit>
     */
    private function uncoveredUnits(PlanningLine $line, ?PlanningLine $source, PlanningGeneration $generation, LiveDemandView $demand, array $uncovered, bool $linePublished): array
    {
        if ([] === $uncovered) {
            return [];
        }

        $currentByDutyId = [];
        foreach ($this->assignmentRepository->findForGenerations([$generation]) as $assignment) {
            $currentByDutyId[(int) $assignment->getDuty()->getId()] = $assignment;
        }

        $byUnit = [];
        foreach ($uncovered as $item) {
            $byUnit[(string) ($item->duty->getGroupInstance()?->getStableId() ?? $item->duty->getStableId())][] = $item->duty;
        }

        $units = [];
        foreach ($byUnit as $duties) {
            $days = array_map(static function (Duty $duty) use ($demand): PublicationUncoveredDay {
                $dutyDemand = $demand->forDuty($duty);

                return new PublicationUncoveredDay(
                    $duty->getLocalDate(),
                    Weekday::ofDate($duty->getLocalDate()),
                    $dutyDemand->reason,
                    $duty,
                    $dutyDemand->ownDay?->sourceDuty,
                    $dutyDemand->ownDay?->sourceHolderUserStableId,
                );
            }, $duties);

            $units[] = new PublicationUncoveredUnit($line, $source, $duties[0]->isConditional(), true, $days, $this->vacancyOf($generation, $duties, $currentByDutyId), $linePublished);
        }

        return $units;
    }

    /**
     * The same reinforcement days as the completion would create (docs/decisions.md D224), with the same rules as the
     * live demand (LineDemandRulesReader): a post required by a source holder of today, with no duty yet, is required
     * all the same.
     *
     * @return list<PublicationUncoveredUnit>
     */
    private function missingReinforcements(PlanningLine $line, ?PlanningLine $source, PlanningPeriod $period, bool $linePublished): array
    {
        $units = [];
        foreach ($this->inheritedReinforcements->plan($period)->units as $unit) {
            $holders = $this->inheritedReinforcements->currentHolders($unit->sourceDuties[0]->getPlanningPeriod()) ?? [];
            $days = array_map(static fn (Duty $sourceDuty): PublicationUncoveredDay => new PublicationUncoveredDay(
                $sourceDuty->getLocalDate(),
                Weekday::ofDate($sourceDuty->getLocalDate()),
                DemandReason::TRIGGERED,
                null,
                $sourceDuty,
                $holders[(int) $sourceDuty->getId()] ?? null,
            ), $unit->sourceDuties);
            $units[] = new PublicationUncoveredUnit($line, $source, true, false, $days, new PublicationVacancy(PublicationVacancy::NOT_CREATED), $linePublished);
        }

        return $units;
    }

    /**
     * Why these duties have nobody, with the same check as the candidate list and the save.
     *
     * @param non-empty-list<Duty>       $duties
     * @param array<int, DutyAssignment> $currentByDutyId
     */
    private function vacancyOf(PlanningGeneration $generation, array $duties, array $currentByDutyId): PublicationVacancy
    {
        $block = $this->candidateService->blockDuties($duties[0]);
        $members = $this->teamMemberRepository->findByTeam($duties[0]->getTeam());

        $onDates = array_values(array_filter($members, static function (PlanningTeamMember $member) use ($duties): bool {
            foreach ($duties as $duty) {
                if (!$member->isActiveAt($duty->getLocalDate())) {
                    return false;
                }
            }

            return true;
        }));

        if ([] === $onDates) {
            $lastDay = max(array_map(static fn (Duty $duty): \DateTimeImmutable => $duty->getLocalDate(), $duties));
            $later = array_values(array_filter(
                array_map(static fn (PlanningTeamMember $member): \DateTimeImmutable => $member->getMembershipStart(), $members),
                static fn (\DateTimeImmutable $start): bool => $start > $lastDay,
            ));

            return new PublicationVacancy(PublicationVacancy::NO_MEMBER_ON_DATE, \count($members), 0, [] !== $later ? min($later) : null);
        }

        $assignable = 0;
        $reasons = [];
        foreach ($onDates as $member) {
            $error = $this->candidateService->assignabilityError($generation, $block, $member, [], ReassignmentCandidateService::heldBy($block, $currentByDutyId, $member));
            if (null === $error) {
                ++$assignable;
                continue;
            }
            $reasons[$error] = ($reasons[$error] ?? 0) + 1;
        }

        return new PublicationVacancy(
            $assignable > 0 ? PublicationVacancy::ASSIGNABLE_MEMBERS : PublicationVacancy::NO_ELIGIBLE_MEMBER,
            \count($members),
            $assignable,
            null,
            array_map(fn (string $code, int $count): array => ['code' => $code, 'label' => $this->vacancyLabel($code), 'count' => $count], array_keys($reasons), array_values($reasons)),
        );
    }

    private function vacancyLabel(string $code): string
    {
        $reason = ExclusionReason::tryFrom($code);

        return null !== $reason ? $this->reasonLabeler->label($reason) : 'ne fait pas partie de cette génération';
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
