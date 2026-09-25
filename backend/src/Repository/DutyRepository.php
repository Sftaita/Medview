<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Duty;
use App\Entity\PlanningPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Duty>
 */
class DutyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Duty::class);
    }

    public function findOneByStableId(string $stableId): ?Duty
    {
        try {
            $uuid = Uuid::fromString($stableId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->findOneBy(['stableId' => $uuid]);
    }

    /**
     * @return list<Duty>
     */
    public function findByPlanningPeriod(PlanningPeriod $planningPeriod): array
    {
        return $this->findBy(['planningPeriod' => $planningPeriod], ['localDate' => 'ASC']);
    }

    /**
     * Whether *any* Duty already exists for this exact calendar date —
     * regardless of which DutyPattern produced it (docs/decisions.md
     * D136) — the real idempotency guard `WeeklyDutyCalendarService`
     * materializes against: once a calendar day has been decided by *some*
     * structure version, a later structure change (before any real
     * generation ever solved over it) must never add a second, conflicting
     * Duty for that same day — "s'applique qu'aux prochaines générations"
     * means *future* calendar days, never a day already materialized under
     * a retired pattern.
     */
    public function existsForPeriodAndLocalDate(PlanningPeriod $planningPeriod, \DateTimeImmutable $localDate): bool
    {
        return null !== $this->findOneBy([
            'planningPeriod' => $planningPeriod,
            'localDate' => $localDate,
        ]);
    }
}
