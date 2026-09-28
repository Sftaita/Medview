<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
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
     * @return list<PlanningLine> the Planning's active lines, in resolution order
     */
    public function activeInResolutionOrder(Planning $planning): array
    {
        $lines = array_values(array_filter(
            $this->lineRepository->findByPlanning($planning),
            static fn (PlanningLine $line): bool => $line->isActive(),
        ));

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
