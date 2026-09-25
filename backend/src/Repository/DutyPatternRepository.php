<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DutyPattern;
use App\Entity\PlanningTeam;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DutyPattern>
 */
class DutyPatternRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DutyPattern::class);
    }

    /**
     * @return list<DutyPattern>
     */
    public function findActiveByTeam(PlanningTeam $team): array
    {
        return $this->findBy(['team' => $team, 'active' => true]);
    }

    /**
     * Only patterns belonging to a PlanningLine's weekly recurring
     * structure (docs/decisions.md D136) — never an unrelated one-off
     * `active = true` pattern (e.g. a manually-built test fixture group).
     * The only query `WeekStructureService`/`WeeklyDutyCalendarService`
     * ever use.
     *
     * @return list<DutyPattern>
     */
    public function findActiveRecurringByTeam(PlanningTeam $team): array
    {
        return $this->findBy(['team' => $team, 'active' => true, 'recurring' => true]);
    }
}
