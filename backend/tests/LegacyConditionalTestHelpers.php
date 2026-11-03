<?php

declare(strict_types=1);

namespace App\Tests;

use App\Demand\DemandMode;
use App\Demand\StructureSource;
use App\Demand\Weekday;
use App\Entity\DemandTrigger;
use App\Entity\PlanningLineDemandPolicy;
use App\Repository\PlanningLineDemandPolicyRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Repository\UserRepository;
use App\Service\PlanningGenerationLauncher;
use Doctrine\ORM\EntityManagerInterface;

/**
 * docs/decisions.md D224 — two seams for the tests written before it:
 *
 * - a conditional configuration with its OWN weekly structure is recorded
 *   directly (no API writes one any more: it is historical data, which D224
 *   keeps reading exactly as before);
 * - the duties are materialized the way a real launch does
 *   (PlanningGenerationLauncher::preflight(materialize: true)) — a GET of
 *   the preflight only projects them now, and writes nothing.
 */
trait LegacyConditionalTestHelpers
{
    /**
     * @param list<array{0: string, 1: list<string>}> $triggers [email, weekdays]
     */
    private function recordOwnStructurePolicy(string $targetLineStableId, string $sourceLineStableId, array $triggers): void
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $policies = $container->get(PlanningLineDemandPolicyRepository::class);
        $lines = $container->get(PlanningLineRepository::class);
        $target = $lines->findOneByStableId($targetLineStableId);
        $current = $policies->findActiveForLine($target);
        if (null !== $current) {
            $current->retire();
            $em->flush();
        }
        $policy = new PlanningLineDemandPolicy($target, $policies->findNextVersionNumber($target), DemandMode::CONDITIONAL_ON_SOURCE_ASSIGNMENT, $lines->findOneByStableId($sourceLineStableId), null, StructureSource::OWN);
        $em->persist($policy);
        foreach ($triggers as [$email, $days]) {
            $em->persist(new DemandTrigger($policy, $container->get(UserRepository::class)->findOneByEmail($email), array_map(Weekday::from(...), $days), 1));
        }
        $em->flush();
        $em->clear();
    }

    /** What a real launch does before solving: every line of the planning's only period materialized, sources first. */
    private function materializeLikeALaunch(string $planningStableId, ?string $periodStableId = null): void
    {
        $container = static::getContainer();
        $container->get(EntityManagerInterface::class)->clear();
        $planning = $container->get(PlanningRepository::class)->findOneByStableId($planningStableId);
        $cycle = null !== $periodStableId ? $container->get(\App\Repository\PlanningCycleRepository::class)->findOneForPlanning($planning, $periodStableId) : null;
        $container->get(PlanningGenerationLauncher::class)->preflight($planning, $cycle, materialize: true);
    }
}
