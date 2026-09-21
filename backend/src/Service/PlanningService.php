<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningLineType;
use App\Entity\TeamMemberRole;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Creates the Planning aggregate together with its mandatory PRIMARY
 * PlanningLine — and, inline with it, that line's own fresh PlanningTeam
 * (docs/decisions.md D079) — atomically via EntityManager::wrapInTransaction()
 * (docs/decisions.md D074): a Planning must always have exactly one
 * PRIMARY line, so one ever observably existing with zero lines — e.g.
 * because the primary line's FairnessPeriod collided with another
 * Planning's — would be a broken intermediate state.
 *
 * Also opens the planning's first availability collection over its whole
 * range (docs/availability-collection.md §1), and — only when asked —
 * makes the creator a participant (docs/decisions.md D123).
 */
final class PlanningService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanningLineService $planningLineService,
        private readonly PlanningTeamMembershipService $membershipService,
        private readonly AvailabilityCollectionService $collectionService,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * $includeCreator ("M'inclure dans le planning") is independent of the
     * creator's management right over the Planning (PlanningVoter::MANAGE,
     * D071): it only decides whether they also sit in the candidate pool.
     * There is no dedicated flag — being a participant simply *is* having an
     * open PlanningTeamMember in the Planning (docs/planning.md §Adhésions),
     * so ticking the box creates that membership, on the primary line, as
     * any other member would have one: same availability, same
     * eligibility, same fairness, no special case anywhere in the engine.
     * The membership starts with the planning (or today, if it has not
     * started yet) so the creator covers the whole range they are creating.
     *
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
        bool $includeCreator = false,
    ): Planning {
        return $this->entityManager->wrapInTransaction(function () use ($name, $creator, $startsAt, $endsAt, $timezone, $primaryTeamName, $includeCreator): Planning {
            $planning = new Planning($name, $creator, $startsAt, $endsAt, $timezone);
            $this->entityManager->persist($planning);
            $this->entityManager->flush();

            $line = $this->planningLineService->addLine($planning, $primaryTeamName, PlanningLineType::PRIMARY);

            if ($includeCreator) {
                $this->membershipService->addMember($line->getPlanningTeam(), $creator, TeamMemberRole::OWNER, min($this->today(), $startsAt));
            }

            // After the creator's membership, so they are expected in it; everyone joining later is registered by the membership service.
            $this->collectionService->open($planning, new DateWindow($startsAt, $endsAt), $creator);

            return $planning;
        });
    }

    public function rename(Planning $planning, string $name): void
    {
        $planning->rename($name);
        $this->entityManager->flush();
    }

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->clock->now()->format('Y-m-d'));
    }
}
