<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanningGeneration;
use App\Entity\PlanningGenerationStatus;
use App\Entity\PlanningPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PlanningGeneration>
 */
class PlanningGenerationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningGeneration::class);
    }

    public function findOneByStableId(string $stableId): ?PlanningGeneration
    {
        try {
            $uuid = Uuid::fromString($stableId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->findOneBy(['stableId' => $uuid]);
    }

    /**
     * Most recent first — successive generations of the same PlanningPeriod
     * are never overwritten (docs/planning-generation.md). Tie-broken by
     * $id (insertion order): $createdAt is truncated to the second in
     * Postgres (TIMESTAMP(0)), so two generations created within the same
     * second would otherwise sort arbitrarily.
     *
     * @return list<PlanningGeneration>
     */
    public function findByPlanningPeriod(PlanningPeriod $planningPeriod): array
    {
        return $this->findBy(['planningPeriod' => $planningPeriod], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * The generation `PlanningPeriodLifecycleService::transition()` checks
     * before allowing `PUBLISHED` (docs/decisions.md D106, closing the
     * `docs/planning-domain.md` §17 debt item). `COMPLETED` only — a
     * `FAILED`/`SOLVING`/`SNAPSHOTTED`/`DRAFT` generation never counts,
     * regardless of how recent it is.
     */
    public function findMostRecentCompletedByPlanningPeriod(PlanningPeriod $planningPeriod): ?PlanningGeneration
    {
        return $this->findOneBy(
            ['planningPeriod' => $planningPeriod, 'status' => PlanningGenerationStatus::COMPLETED],
            ['createdAt' => 'DESC', 'id' => 'DESC'],
        );
    }
}
