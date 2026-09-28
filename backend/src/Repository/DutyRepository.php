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

    /**
     * Whether any Duty has been materialized for this period — once it is
     * the case, the line's demand kind is fixed for those days
     * (docs/decisions.md D162, `line_already_materialized`).
     */
    public function existsForPeriod(PlanningPeriod $planningPeriod): bool
    {
        return null !== $this->findOneBy(['planningPeriod' => $planningPeriod]);
    }

    /**
     * The duties of a period on one calendar day — how a conditional line
     * finds the coverage source of each of its days (docs/decisions.md
     * D163). A list, never "the" duty: several would be an ambiguity to
     * report, not to resolve by picking one.
     *
     * @return list<Duty>
     */
    public function findByPeriodAndLocalDate(PlanningPeriod $planningPeriod, \DateTimeImmutable $localDate): array
    {
        return $this->findBy(['planningPeriod' => $planningPeriod, 'localDate' => $localDate], ['id' => 'ASC']);
    }

    /**
     * The conditional duties whose coverage source is one of $sources — the
     * reinforcements a change on those source duties may affect
     * (docs/decisions.md D165).
     *
     * @param list<Duty> $sources
     *
     * @return list<Duty>
     */
    public function findByCoverageSources(array $sources): array
    {
        if ([] === $sources) {
            return [];
        }

        return $this->createQueryBuilder('d')
            ->where('d.coverageSource IN (:sources)')
            ->setParameter('sources', $sources)
            ->orderBy('d.localDate', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
