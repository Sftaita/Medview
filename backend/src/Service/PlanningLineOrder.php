<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\PlanningLine;
use App\Repository\PlanningLineDemandPolicyRepository;
use App\Repository\PlanningLineRepository;

/**
 * The order in which the active lines of a Planning are solved, one after
 * another (docs/decisions.md D161): a line solved earlier creates fixed
 * commitments for the people it assigns, which the lines solved after it
 * must respect. No global optimum across lines is sought — an earlier line
 * has priority over a later one, deliberately.
 *
 * Rule: sources first, then `position`, then id (never ambiguous) — a
 * topological order: among the lines whose source (if any) is already
 * placed, the smallest position comes next. A conditional line
 * (docs/decisions.md D162/D163) therefore always comes after its source
 * line, whose assignments decide its demand. A policy's source is always an
 * active line (D162); were it ever inactive, it would not be solved and the
 * conditional line would simply follow the order of positions. V1 depth is
 * 1, so no cycle can exist (D162).
 *
 * The single place this order is decided — generation, snapshot
 * commitments and completion all read it here, so they can never
 * disagree.
 */
final class PlanningLineOrder
{
    public function __construct(
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningLineDemandPolicyRepository $policyRepository,
    ) {
    }

    /**
     * Only for a Planning with a single cycle — the flows reading it are not
     * period-aware yet (lots L3/L4, docs/decisions.md D187); a line without
     * period (added while no period could take it, docs/planning-cycles.md
     * §5.5) has nothing to solve and is left out.
     *
     * @return list<PlanningLine> the Planning's active lines, in resolution order
     *
     * @throws \App\Exception\MultiplePeriodsNotSupportedException
     */
    public function activeInResolutionOrder(Planning $planning): array
    {
        $planning->assertSingleCycle();

        return $this->inResolutionOrder(array_values(array_filter(
            $this->lineRepository->findByPlanning($planning),
            static fn (PlanningLine $line): bool => $line->isActive() && null !== $line->getSingleCyclePeriod(),
        )));
    }

    /**
     * The active lines that have a period in $cycle, in resolution order
     * (docs/planning-cycles.md §5.4, lot L3): what a generation or a
     * completion of that period solves. A conditional line's source counts
     * only when it takes part in the same cycle (§5.6).
     *
     * @return list<PlanningLine>
     */
    public function activeInCycle(PlanningCycle $cycle): array
    {
        return $this->inResolutionOrder(array_values(array_filter(
            $this->lineRepository->findByPlanning($cycle->getPlanning()),
            static fn (PlanningLine $line): bool => $line->isActive() && null !== $line->periodIn($cycle),
        )));
    }

    /**
     * @return list<PlanningLine> the active lines of $cycle solved before $line (empty for the first one, or for a
     *                            line that takes no part in $cycle)
     */
    public function precedingActiveLinesInCycle(PlanningLine $line, PlanningCycle $cycle): array
    {
        $preceding = [];
        foreach ($this->activeInCycle($cycle) as $candidate) {
            if ($candidate === $line) {
                return $preceding;
            }
            $preceding[] = $candidate;
        }

        return [];
    }

    /**
     * @param list<PlanningLine> $lines
     *
     * @return list<PlanningLine>
     */
    private function inResolutionOrder(array $lines): array
    {
        usort($lines, static fn (PlanningLine $a, PlanningLine $b): int => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

        /** @var array<int, PlanningLine> $sourceOf active source line, keyed by the conditional line's id */
        $sourceOf = [];
        foreach ($lines as $line) {
            $source = $this->policyRepository->findActiveForLine($line)?->getSourceLine();
            if (null !== $source && \in_array($source, $lines, true)) {
                $sourceOf[(int) $line->getId()] = $source;
            }
        }

        $ordered = [];
        $remaining = $lines;
        while ([] !== $remaining) {
            foreach ($remaining as $index => $line) {
                $source = $sourceOf[(int) $line->getId()] ?? null;
                if (null === $source || \in_array($source, $ordered, true)) {
                    $ordered[] = $line;
                    unset($remaining[$index]);
                    continue 2;
                }
            }

            throw new \LogicException('Circular demand dependency between lines — impossible with V1 depth 1 (docs/decisions.md D162).');
        }

        return $ordered;
    }

    /**
     * @return list<PlanningLine> the active lines solved before $line (empty for the first one, or for an inactive line)
     */
    public function precedingActiveLines(PlanningLine $line): array
    {
        $preceding = [];
        foreach ($this->activeInResolutionOrder($line->getPlanning()) as $candidate) {
            if ($candidate === $line) {
                return $preceding;
            }
            $preceding[] = $candidate;
        }

        return [];
    }
}
