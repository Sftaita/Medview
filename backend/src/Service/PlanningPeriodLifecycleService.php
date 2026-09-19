<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FairnessPeriod;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningPeriodStatus;
use App\Entity\PlanningTeam;
use App\Exception\InvalidPlanningPeriodTransitionException;
use App\Exception\PlanningPeriodNotReadyToPublishException;
use App\Fairness\CoverageStatus;
use App\Repository\PlanningGenerationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Centralizes PlanningPeriod creation and lifecycle transitions
 * (docs/allocation-algorithm.md §18) so the legal transition graph is
 * enforced in exactly one place, never scattered across future
 * controllers.
 *
 * docs/decisions.md D106 closes the previous gap (see
 * docs/planning-domain.md "Dette / points ouverts"): PUBLISHED now also
 * requires `coverageStatus = COMPLETE` on the most recent COMPLETED
 * PlanningGeneration — checked here, the one real integration point,
 * without building any publish endpoint/UI (none exists yet; this method
 * has no caller in this lot either, exactly like before — the guard is
 * ready for whichever future lot adds one).
 */
final class PlanningPeriodLifecycleService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanningGenerationRepository $generationRepository,
    ) {
    }

    public function create(
        PlanningTeam $team,
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
     * @throws PlanningPeriodNotReadyToPublishException
     */
    public function transition(PlanningPeriod $planningPeriod, PlanningPeriodStatus $target): void
    {
        // The state-machine SHAPE is checked first — an illegal transition
        // (e.g. DRAFT → PUBLISHED directly) must still fail with
        // InvalidPlanningPeriodTransitionException, never be pre-empted by
        // the coverage precondition below, which only makes sense once the
        // transition is otherwise legal.
        if (!$planningPeriod->getStatus()->canTransitionTo($target)) {
            throw new InvalidPlanningPeriodTransitionException($planningPeriod->getStatus(), $target);
        }

        if (PlanningPeriodStatus::PUBLISHED === $target) {
            $generation = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($planningPeriod);
            if (null === $generation || CoverageStatus::COMPLETE !== $generation->getCoverageStatus()) {
                throw new PlanningPeriodNotReadyToPublishException();
            }
        }

        $planningPeriod->transitionTo($target);
        $this->entityManager->flush();
    }
}
