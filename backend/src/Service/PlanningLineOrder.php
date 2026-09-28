<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningLine;
use App\Repository\PlanningLineRepository;

/**
 * The order in which the active lines of a Planning are solved, one after
 * another (docs/decisions.md D161): a line solved earlier creates fixed
 * commitments for the people it assigns, which the lines solved after it
 * must respect. No global optimum across lines is sought — an earlier line
 * has priority over a later one, deliberately.
 *
 * Rule: sources first, then `position`, then id (never ambiguous). Until
 * conditional lines exist (a later lot of the conditional secondary line
 * project), no line is anyone's source and the order is simply `position`.
 *
 * The single place this order is decided — generation, snapshot
 * commitments and completion all read it here, so they can never
 * disagree.
 */
final class PlanningLineOrder
{
    public function __construct(private readonly PlanningLineRepository $lineRepository)
    {
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

        return $lines;
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
