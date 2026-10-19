<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\DutyGroupInstance;
use App\Entity\PlanningPeriod;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes line periods that have no history (docs/planning-cycles.md §5.3,
 * §5.5) — the last period of a planning, or the periods of a deleted line —
 * together with the duties merely materialized in them.
 *
 * The callers prove there is no history first (no generation, hence no
 * assignment, event or swap; no publication). The database stays the last
 * net: every table that records history references these duties and
 * periods with ON DELETE RESTRICT, so a deletion that would lose any of it
 * fails and rolls the whole operation back.
 */
final class PlanningPeriodRemover
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<PlanningPeriod> $periods removed together: a conditional duty may cover a duty of another of them
     */
    public function remove(array $periods): void
    {
        if ([] === $periods) {
            return;
        }

        // Conditional duties first: they point at their source duty (coverage_source).
        $this->entityManager->createQueryBuilder()
            ->delete(Duty::class, 'd')
            ->where('d.planningPeriod IN (:periods)')
            ->andWhere('d.coverageSource IS NOT NULL')
            ->setParameter('periods', $periods)
            ->getQuery()
            ->execute();
        $this->entityManager->createQueryBuilder()
            ->delete(Duty::class, 'd')
            ->where('d.planningPeriod IN (:periods)')
            ->setParameter('periods', $periods)
            ->getQuery()
            ->execute();
        $this->entityManager->createQueryBuilder()
            ->delete(DutyGroupInstance::class, 'g')
            ->where('g.planningPeriod IN (:periods)')
            ->setParameter('periods', $periods)
            ->getQuery()
            ->execute();

        foreach ($periods as $period) {
            $period->getLine()?->unregisterPeriod($period);
            $this->entityManager->remove($period);
        }
    }
}
