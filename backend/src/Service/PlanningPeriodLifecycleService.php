<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FairnessPeriod;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningPeriodStatus;
use App\Entity\Team;
use App\Exception\InvalidPlanningPeriodTransitionException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Centralizes PlanningPeriod creation and lifecycle transitions
 * (docs/allocation-algorithm.md §18) so the legal transition graph is
 * enforced in exactly one place, never scattered across future
 * controllers.
 *
 * Known gap (see docs/planning-domain.md "Dette / points ouverts"): the
 * spec requires PUBLISHED to also need coverageStatus = COMPLETE, but
 * PlanningGeneration (which would carry that status) is out of scope for
 * this lot — only the state-machine shape is enforced here today.
 */
final class PlanningPeriodLifecycleService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function create(
        Team $team,
        FairnessPeriod $fairnessPeriod,
        string $name,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
    ): PlanningPeriod {
        $planningPeriod = new PlanningPeriod($team, $fairnessPeriod, $name, $startsAt, $endsAt);
        $this->entityManager->persist($planningPeriod);
        $this->entityManager->flush();

        return $planningPeriod;
    }

    /**
     * @throws InvalidPlanningPeriodTransitionException
     */
    public function transition(PlanningPeriod $planningPeriod, PlanningPeriodStatus $target): void
    {
        $planningPeriod->transitionTo($target);
        $this->entityManager->flush();
    }
}
