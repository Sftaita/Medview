<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\PlanningPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PlanningCycle>
 */
class PlanningCycleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningCycle::class);
    }

    /**
     * @return list<PlanningCycle> ordered by number
     */
    public function findByPlanning(Planning $planning): array
    {
        return $this->findBy(['planning' => $planning], ['number' => 'ASC']);
    }

    /** Null when $stableId is not a period of $planning (never one of another planning). */
    public function findOneForPlanning(Planning $planning, string $stableId): ?PlanningCycle
    {
        if (!Uuid::isValid($stableId)) {
            return null;
        }

        return $this->findOneBy(['planning' => $planning, 'stableId' => Uuid::fromString($stableId)]);
    }

    /**
     * The periods of the lines in $cycle. A period whose line was deleted
     * (orphan, kept with its history) is not one of them: it neither locks
     * nor follows the cycle (docs/decisions.md D188).
     *
     * @return list<PlanningPeriod>
     */
    public function periodsOf(PlanningCycle $cycle): array
    {
        /* @var list<PlanningPeriod> */
        return $this->getEntityManager()->createQueryBuilder()
            ->select('p')
            ->from(PlanningPeriod::class, 'p')
            ->where('p.cycle = :cycle')
            ->andWhere('p.line IS NOT NULL')
            ->setParameter('cycle', $cycle)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Generations of any status on the periods of $cycle — the history a period keeps forever. */
    public function countGenerations(PlanningCycle $cycle): int
    {
        return $this->count0('SELECT COUNT(*) FROM planning_generations g JOIN planning_periods p ON p.id = g.planning_period_id WHERE p.planning_cycle_id = :cycle', $cycle);
    }

    public function countCollections(PlanningCycle $cycle): int
    {
        return $this->count0('SELECT COUNT(*) FROM availability_collections WHERE planning_cycle_id = :cycle', $cycle);
    }

    public function countPublications(PlanningCycle $cycle): int
    {
        return $this->count0('SELECT COUNT(*) FROM planning_publications WHERE planning_cycle_id = :cycle', $cycle);
    }

    /** Jobs of any status (queued, running or finished). */
    public function countJobs(PlanningCycle $cycle): int
    {
        return $this->count0('SELECT COUNT(*) FROM planning_jobs WHERE planning_cycle_id = :cycle', $cycle);
    }

    /** Duties already materialized in $cycle on or after $day. */
    public function countDutiesFrom(PlanningCycle $cycle, \DateTimeImmutable $day): int
    {
        return $this->count0('SELECT COUNT(*) FROM duties d JOIN planning_periods p ON p.id = d.planning_period_id WHERE p.planning_cycle_id = :cycle AND d.local_date >= :day', $cycle, ['day' => $day->format('Y-m-d')]);
    }

    /** Collections of $cycle whose window ends after $day (exclusive end). */
    public function countCollectionsEndingAfter(PlanningCycle $cycle, \DateTimeImmutable $day): int
    {
        return $this->count0('SELECT COUNT(*) FROM availability_collections WHERE planning_cycle_id = :cycle AND ends_at > :day', $cycle, ['day' => $day->format('Y-m-d')]);
    }

    /**
     * The dates a period's derived facts are read from (docs/planning-cycles.md
     * §4.2): first and last publication, latest COMPLETED generation.
     *
     * @return array{publishedAt: ?string, lastPublishedAt: ?string, generatedAt: ?string}
     */
    public function milestones(PlanningCycle $cycle): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $publications = $connection->fetchAssociative(
            'SELECT MIN(published_at) AS first, MAX(published_at) AS last FROM planning_publications WHERE planning_cycle_id = :cycle',
            ['cycle' => $cycle->getId()],
        );
        $generatedAt = $connection->fetchOne(
            "SELECT MAX(COALESCE(g.generated_at, g.updated_at)) FROM planning_generations g JOIN planning_periods p ON p.id = g.planning_period_id WHERE p.planning_cycle_id = :cycle AND g.status = 'COMPLETED'",
            ['cycle' => $cycle->getId()],
        );

        return [
            'publishedAt' => self::atom($publications['first'] ?? null),
            'lastPublishedAt' => self::atom($publications['last'] ?? null),
            'generatedAt' => self::atom(false === $generatedAt ? null : $generatedAt),
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function count0(string $sql, PlanningCycle $cycle, array $parameters = []): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne($sql, ['cycle' => $cycle->getId()] + $parameters);
    }

    /** Timestamps are stored as UTC wall-clock (`datetime_immutable`). */
    private static function atom(mixed $value): ?string
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format(\DATE_ATOM);
    }
}
