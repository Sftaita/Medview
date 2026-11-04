<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\DemandRules;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriod;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineDemandPolicyRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningSnapshotRepository;

/**
 * THE rules the current calendar of a period is read with (docs/decisions.md
 * D164, D233) — the one answer shared by the live demand, the completion and
 * the publication preflight, so that none of them can ever create or require
 * what another one does not.
 *
 * - A period with a current generation: the triggers that generation froze. A
 *   new policy version waits for the next generation.
 * - A period never generated: the policy in force.
 * - In both cases, the coverage start the line has today. It is not one more
 *   rule frozen with the triggers: it is an explicit, journaled decision about
 *   the active calendar (PlanningLineCoverageChange), applied at once.
 */
final class LineDemandRulesReader
{
    public function __construct(
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningLineDemandPolicyRepository $policyRepository,
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly PlanningSnapshotRepository $snapshotRepository,
    ) {
    }

    /**
     * @return array{0: DemandRules, 1: ?PlanningPeriod} the rules, and the source line's period in the same cycle
     *                                                   (null for an independent line)
     */
    public function forPeriod(PlanningLine $line, PlanningPeriod $period): array
    {
        $coverageStart = $line->getCoverageStartsOn();
        $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($period);
        $snapshot = null !== $generation ? $this->snapshotRepository->findOneByGeneration($generation) : null;
        if (null !== $snapshot) {
            $frozen = $snapshot->getDemandPolicy();
            if (null === $frozen) {
                // Generated as an independent line: its duties are intrinsic whatever policy exists since.
                return [DemandRules::independent()->withCoverageStart($coverageStart), null];
            }
            $sourceLine = $this->lineRepository->findOneByStableId((string) $frozen->getSourceLineStableId());

            return [$frozen->toRules()->withCoverageStart($coverageStart), self::sourcePeriodIn($sourceLine, $period)];
        }

        $policy = $this->policyRepository->findActiveForLine($line);
        if (null === $policy || !$policy->getMode()->isConditional()) {
            return [DemandRules::independent()->withCoverageStart($coverageStart), null];
        }

        return [$policy->toRules(), self::sourcePeriodIn($policy->getSourceLine(), $period)];
    }

    /** docs/planning-cycles.md §5.6: the source line's period in the same cycle as $period. */
    private static function sourcePeriodIn(?PlanningLine $sourceLine, PlanningPeriod $period): ?PlanningPeriod
    {
        $cycle = $period->getCycle();

        return null !== $sourceLine && null !== $cycle ? $sourceLine->periodIn($cycle) : null;
    }
}
