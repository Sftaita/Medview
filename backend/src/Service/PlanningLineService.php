<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningLine;
use App\Entity\PlanningLineType;
use App\Entity\PlanningTeam;
use App\Exception\PrimaryPlanningLineNotDeletableException;
use App\Repository\PlanningLineRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Owns PlanningLine creation/deletion (docs/planning.md §2-§3). A line's
 * PlanningTeam is never a pre-existing entity a client references — this
 * service always creates a brand new PlanningTeam together with its
 * dedicated FairnessPeriod + PlanningPeriod, spanning exactly the
 * Planning's date range (docs/planning.md §5), atomically with the
 * PlanningLine itself (docs/decisions.md D079). A PlanningTeam is thus
 * never shared between two PlanningLines or two Plannings — the "team
 * already in use" conflict this service used to guard against
 * (docs/decisions.md D073) is now structurally impossible, since a client
 * can never supply an existing team's identifier. Never touches
 * EligibilityService/PlanningSnapshotService: once a PlanningPeriod
 * exists, every mono-team engine underneath it runs exactly as it did
 * before Planning existed (docs/planning.md §4).
 */
final class PlanningLineService
{
    public function __construct(
        private readonly PlanningLineRepository $planningLineRepository,
        private readonly FairnessPeriodService $fairnessPeriodService,
        private readonly PlanningPeriodLifecycleService $planningPeriodLifecycleService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * $name is used both as the new PlanningTeam's name and as the
     * PlanningLine/FairnessPeriod/PlanningPeriod's name — there is no
     * separate "team name" a client can set in v1 (docs/decisions.md D079).
     *
     * @throws \App\Exception\OverlappingFairnessPeriodException should never
     *                                                           actually trigger here since the PlanningTeam is always freshly
     *                                                           created, kept only because FairnessPeriodService::create() can
     *                                                           throw it
     */
    public function addLine(Planning $planning, string $name, PlanningLineType $type): PlanningLine
    {
        return $this->entityManager->wrapInTransaction(function () use ($planning, $name, $type): PlanningLine {
            $team = new PlanningTeam($planning, $name);
            $this->entityManager->persist($team);
            // Flushed immediately: FairnessPeriodService::create() below
            // queries for overlaps using $team as a bound parameter, which
            // Doctrine can only do once it has an assigned identifier.
            $this->entityManager->flush();

            $fairnessPeriod = $this->fairnessPeriodService->create($team, $name, $planning->getStartsAt(), $planning->getEndsAt());
            $planningPeriod = $this->planningPeriodLifecycleService->create($team, $fairnessPeriod, $name, $planning->getStartsAt(), $planning->getEndsAt());

            $position = $this->planningLineRepository->countByPlanning($planning) + 1;
            $line = new PlanningLine($planning, $team, $planningPeriod, $name, $type, $position);
            $this->entityManager->persist($line);
            $this->entityManager->flush();

            return $line;
        });
    }

    /**
     * @throws PrimaryPlanningLineNotDeletableException
     */
    public function deleteLine(PlanningLine $line): void
    {
        if ($line->isPrimary()) {
            throw new PrimaryPlanningLineNotDeletableException();
        }

        // Deliberately does not delete the line's PlanningTeam/PlanningPeriod/
        // FairnessPeriod — no deletion path exists for any of them
        // (docs/planning-domain.md §14: "aucun service de suppression
        // fourni", unchanged by this lot). They remain in the database,
        // simply no longer referenced by any line.
        $this->entityManager->remove($line);
        $this->entityManager->flush();
    }

    public function rename(PlanningLine $line, string $name): void
    {
        $line->rename($name);
        $this->entityManager->flush();
    }
}
