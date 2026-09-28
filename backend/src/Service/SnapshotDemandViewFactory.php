<?php

declare(strict_types=1);

namespace App\Service;

use App\Demand\DemandCalculator;
use App\Demand\FrozenDemandDecision;
use App\Demand\SnapshotDemandView;
use App\Entity\PlanningSnapshot;

/**
 * Builds the SNAPSHOT DemandView of one generation (docs/decisions.md
 * D164) from its frozen demand policy and decisions only.
 */
final class SnapshotDemandViewFactory
{
    public function __construct(private readonly DemandCalculator $calculator)
    {
    }

    public function forSnapshot(PlanningSnapshot $snapshot): SnapshotDemandView
    {
        $decisions = [];
        foreach ($snapshot->getDemandDecisions() as $decision) {
            $decisions[(int) $decision->getDuty()->getId()] = new FrozenDemandDecision(
                $decision->getWeekday(),
                null !== $decision->getSourceUserStableId() ? (string) $decision->getSourceUserStableId() : null,
                null !== $decision->getTriggerStableId() ? (string) $decision->getTriggerStableId() : null,
                $decision->getDayReason(),
            );
        }

        return new SnapshotDemandView($this->calculator, $snapshot->getDemandPolicy()?->toRules(), $decisions);
    }
}
