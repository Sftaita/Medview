<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FairnessPeriod;
use App\Entity\PlanningTeam;
use App\Exception\OverlappingFairnessPeriodException;
use App\Repository\FairnessPeriodRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A PlanningTeam's FairnessPeriods never overlap (docs/allocation-algorithm.md §4)
 * — a duty must belong to exactly one equity ledger. This performs the
 * friendly application-level check before the database's own exclusion
 * constraint would reject the same thing less legibly (see migrations).
 */
final class FairnessPeriodService
{
    public function __construct(
        private readonly FairnessPeriodRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws OverlappingFairnessPeriodException
     */
    public function create(PlanningTeam $team, string $name, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): FairnessPeriod
    {
        if ([] !== $this->repository->findOverlapping($team, $startsAt, $endsAt)) {
            throw new OverlappingFairnessPeriodException();
        }

        $fairnessPeriod = new FairnessPeriod($team, $name, $startsAt, $endsAt);
        $this->entityManager->persist($fairnessPeriod);
        $this->entityManager->flush();

        return $fairnessPeriod;
    }
}
