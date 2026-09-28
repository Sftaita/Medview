<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\LiveCoverageState;
use App\Demand\LiveDemandView;
use App\Entity\Duty;
use App\Entity\Planning;
use App\Entity\PlanningTeamMember;
use App\Exception\CoverageNotRequiredException;
use App\Exception\CoverageUndeterminedException;
use App\Repository\DutyAssignmentRepository;
use App\Repository\DutyRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineRepository;

/**
 * The live calendar of conditional lines after generation
 * (docs/decisions.md D165): the rules frozen by each line's current
 * generation, the holders of DutyAssignment.current (LiveDemandView). It
 * answers three questions, and writes nothing itself:
 *
 * - may a NEW assignment be written on this block? (assertCanBeNewlyCovered:
 *   only when the live demand says "required" — never on a reinforcement
 *   nobody needs, never on one whose demand is unknown);
 * - where does a conditional block stand? (stateOf: LiveCoverageState);
 * - what did a change on a source line do to the reinforcements depending
 *   on it? (captureDependents before the write, impactsSince after it).
 *
 * A change on a source line never writes anything on the conditional line:
 * a reinforcement that becomes required stays unassigned until somebody
 * (or "Compléter automatiquement") covers it; one that becomes superfluous
 * stays with its holder until "Retirer l'affectation".
 */
final class ConditionalCoverageService
{
    public function __construct(
        private readonly LiveDemandViewFactory $demandViewFactory,
        private readonly DutyRepository $dutyRepository,
        private readonly DutyAssignmentRepository $assignmentRepository,
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly PlanningLineRepository $lineRepository,
        private readonly ReassignmentCandidateService $candidateService,
    ) {
    }

    /**
     * @param list<Duty> $block
     *
     * @throws CoverageNotRequiredException
     * @throws CoverageUndeterminedException
     */
    public function assertCanBeNewlyCovered(array $block): void
    {
        if (!$block[0]->isConditional()) {
            return;
        }

        $demand = $this->demandViewFactory->forPlanning($this->planningOf($block[0]))->forDuty($block[0]);
        if (!$demand->determined) {
            throw new CoverageUndeterminedException();
        }
        if (!$demand->required) {
            throw new CoverageNotRequiredException();
        }
    }

    /**
     * @param list<Duty> $block a conditional block
     */
    public function stateOf(LiveDemandView $view, array $block, bool $assigned): LiveCoverageState
    {
        return LiveCoverageState::of($view->forDuty($block[0]), $assigned);
    }

    /**
     * The state of every conditional block whose source is one of
     * $sourceBlock's duties — taken BEFORE a write on $sourceBlock.
     *
     * @param list<Duty> $sourceBlock
     *
     * @return list<array{block: list<Duty>, state: LiveCoverageState}>
     */
    public function captureDependents(array $sourceBlock): array
    {
        $blocks = $this->dependentBlocks($sourceBlock);
        if ([] === $blocks) {
            return [];
        }

        $view = $this->demandViewFactory->forPlanning($this->planningOf($sourceBlock[0]));
        $captured = [];
        foreach ($blocks as $block) {
            $captured[] = ['block' => $block, 'state' => $this->stateOf($view, $block, null !== $this->holderOf($block))];
        }

        return $captured;
    }

    /**
     * What those blocks look like now — to be called AFTER the write, inside
     * the same transaction (the view is loaded afresh and sees it).
     *
     * @param list<array{block: list<Duty>, state: LiveCoverageState}> $captured
     *
     * @return list<DependentImpact>
     */
    public function impactsSince(array $captured): array
    {
        if ([] === $captured) {
            return [];
        }

        $view = $this->demandViewFactory->forPlanning($this->planningOf($captured[0]['block'][0]));
        $impacts = [];
        foreach ($captured as ['block' => $block, 'state' => $previous]) {
            $holder = $this->holderOf($block);
            $line = $this->lineRepository->findOneByPlanningPeriod($block[0]->getPlanningPeriod())
                ?? throw new \LogicException('A duty always belongs to a line.');
            $impacts[] = new DependentImpact(
                $line,
                $block,
                $previous,
                $this->stateOf($view, $block, null !== $holder),
                $view->forUnitOf($block[0]),
                $holder,
            );
        }

        return $impacts;
    }

    /**
     * Who currently holds $block on its line's current generation — null
     * when nobody (or an incoherent block, never "picked").
     *
     * @param list<Duty> $block
     */
    public function holderOf(array $block): ?PlanningTeamMember
    {
        $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($block[0]->getPlanningPeriod());
        if (null === $generation) {
            return null;
        }

        $current = $this->assignmentRepository->findCurrentByGenerationAndDuties($generation, $block);
        if ([] === $current) {
            return null;
        }

        return $this->candidateService->currentBlockTeamMember($block, $current);
    }

    /**
     * @param list<Duty> $sourceBlock
     *
     * @return list<list<Duty>> each dependent conditional block once, whole
     */
    private function dependentBlocks(array $sourceBlock): array
    {
        $blocks = [];
        foreach ($this->dutyRepository->findByCoverageSources($sourceBlock) as $dependent) {
            $key = (string) ($dependent->getGroupInstance()?->getStableId() ?? $dependent->getStableId());
            $blocks[$key] ??= $this->candidateService->blockDuties($dependent);
        }

        return array_values($blocks);
    }

    private function planningOf(Duty $duty): Planning
    {
        return $duty->getPlanningPeriod()->getTeam()->getPlanning();
    }
}
