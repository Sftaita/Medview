<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningLineType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates the Planning aggregate together with its mandatory PRIMARY
 * PlanningLine — and, inline with it, that line's own fresh PlanningTeam
 * (docs/decisions.md D079) — atomically via EntityManager::wrapInTransaction()
 * (docs/decisions.md D074): a Planning must always have exactly one
 * PRIMARY line, so one ever observably existing with zero lines — e.g.
 * because the primary line's FairnessPeriod collided with another
 * Planning's — would be a broken intermediate state.
 */
final class PlanningService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanningLineService $planningLineService,
    ) {
    }

    /**
     * @throws \App\Exception\OverlappingFairnessPeriodException should never
     *                                                           actually trigger here since the primary team is always freshly
     *                                                           created, kept only because PlanningLineService::addLine() can
     *                                                           throw it
     */
    public function create(
        string $name,
        User $creator,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        string $timezone,
        string $primaryTeamName,
    ): Planning {
        return $this->entityManager->wrapInTransaction(function () use ($name, $creator, $startsAt, $endsAt, $timezone, $primaryTeamName): Planning {
            $planning = new Planning($name, $creator, $startsAt, $endsAt, $timezone);
            $this->entityManager->persist($planning);
            $this->entityManager->flush();

            $this->planningLineService->addLine($planning, $primaryTeamName, PlanningLineType::PRIMARY);

            return $planning;
        });
    }

    public function rename(Planning $planning, string $name): void
    {
        $planning->rename($name);
        $this->entityManager->flush();
    }
}
