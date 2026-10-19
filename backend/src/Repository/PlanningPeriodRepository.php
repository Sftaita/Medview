<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanningPeriod;
use App\Entity\PlanningPeriodStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PlanningPeriod>
 */
class PlanningPeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningPeriod::class);
    }

    public function findOneByStableId(string $stableId): ?PlanningPeriod
    {
        try {
            $uuid = Uuid::fromString($stableId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->findOneBy(['stableId' => $uuid]);
    }

    /**
     * Whether $period is published in the database right now (docs/decisions.md
     * D197) — never the status of the entity in memory, which may have been
     * loaded before a publication committed. Meant to be read under the
     * CalendarWriteLock, which every publication also takes: the answer then
     * holds until the caller's transaction ends.
     */
    public function isPublishedNow(PlanningPeriod $period): bool
    {
        $status = $this->getEntityManager()->getConnection()->fetchOne('SELECT status FROM planning_periods WHERE id = ?', [$period->getId()]);

        return PlanningPeriodStatus::PUBLISHED->value === $status;
    }
}
