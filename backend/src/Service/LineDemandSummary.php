<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningSnapshot;

/**
 * What a conditional line's generation found about its demand
 * (docs/decisions.md D164), counted per unit (a block counts once) from
 * its frozen decisions: required (in the problem), not required (absent
 * from it), undetermined (a source duty nobody held — absent too, and
 * never counted as "not required").
 */
final readonly class LineDemandSummary
{
    public function __construct(
        public int $requiredUnitCount,
        public int $notRequiredUnitCount,
        public int $undeterminedUnitCount,
    ) {
    }

    /** Null for a snapshot without frozen demand (an independent line). */
    public static function ofSnapshot(PlanningSnapshot $snapshot): ?self
    {
        if (null === $snapshot->getDemandPolicy()) {
            return null;
        }

        $byUnit = [];
        foreach ($snapshot->getDemandDecisions() as $decision) {
            $group = $decision->getDuty()->getGroupInstance();
            $byUnit[null !== $group ? 'g'.$group->getId() : 'd'.$decision->getDuty()->getId()] = $decision->getRequired();
        }

        return new self(
            \count(array_filter($byUnit, static fn (?bool $required): bool => true === $required)),
            \count(array_filter($byUnit, static fn (?bool $required): bool => false === $required)),
            \count(array_filter($byUnit, static fn (?bool $required): bool => null === $required)),
        );
    }
}
