<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningGeneration;
use App\Entity\PlanningPeriod;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates PlanningGeneration attempts. Deliberately thin — status
 * transitions past DRAFT belong to PlanningSnapshotService (the only real
 * transition this lot implements), not here (docs/planning-generation.md).
 */
final class PlanningGenerationService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function create(PlanningPeriod $planningPeriod, ?User $createdBy): PlanningGeneration
    {
        $generation = new PlanningGeneration($planningPeriod, $createdBy);
        $this->entityManager->persist($generation);
        $this->entityManager->flush();

        return $generation;
    }
}
