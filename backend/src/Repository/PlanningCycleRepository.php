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
     * What decides whether the dates of $cycle may move (docs/decisions.md
     * D207), read in one query: its history, the span of its duties and of
     * its collections, and the one collection that may follow its dates —
     * the intact collection: in a blank period, the only collection, open,
     * covering it exactly, nobody answered, changed (added, edited or deleted
     * an absence in its window), withdrew, and nobody was written to about
     * it. A message is a manual reminder — one sent before periods existed has
     * no period (planning_cycle_id NULL) and counts once sent after the
     * collection opened — or an announcement / scheduled reminder of the
     * period's preparation (D210) that was sent, or is being sent (its
     * delivery is uncertain): one only planned never counts. Otherwise
     * collectionAnswered / collectionContacted say why it keeps its window.
     *
     * @return array{generations: int, publications: int, jobs: int, duties: int, firstDutyDay: ?string, lastDutyDay: ?string, collections: int, firstCollectionStart: ?string, lastCollectionEnd: ?string, collectionAnswered: bool, collectionContacted: bool, untouchedCollectionId: ?int}
     */
    public function dateFacts(PlanningCycle $cycle): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(<<<'SQL'
            SELECT
              (SELECT COUNT(*) FROM planning_generations g JOIN planning_periods p ON p.id = g.planning_period_id WHERE p.planning_cycle_id = :cycle) AS generations,
              (SELECT COUNT(*) FROM planning_publications WHERE planning_cycle_id = :cycle) AS publications,
              (SELECT COUNT(*) FROM planning_jobs WHERE planning_cycle_id = :cycle) AS jobs,
              (SELECT COUNT(*) FROM duties d JOIN planning_periods p ON p.id = d.planning_period_id WHERE p.planning_cycle_id = :cycle) AS duties,
              (SELECT MIN(d.local_date)::text FROM duties d JOIN planning_periods p ON p.id = d.planning_period_id WHERE p.planning_cycle_id = :cycle) AS first_duty_day,
              (SELECT MAX(d.local_date)::text FROM duties d JOIN planning_periods p ON p.id = d.planning_period_id WHERE p.planning_cycle_id = :cycle) AS last_duty_day,
              (SELECT COUNT(*) FROM availability_collections WHERE planning_cycle_id = :cycle) AS collections,
              (SELECT MIN(starts_at)::text FROM availability_collections WHERE planning_cycle_id = :cycle) AS first_collection_start,
              (SELECT MAX(ends_at)::text FROM availability_collections WHERE planning_cycle_id = :cycle) AS last_collection_end,
              EXISTS (SELECT 1 FROM availability_collection_responses r JOIN availability_collections ac ON ac.id = r.collection_id
                WHERE ac.planning_cycle_id = :cycle
                  AND (r.acknowledged_at IS NOT NULL OR r.last_availability_change_at IS NOT NULL OR r.withdrawn_at IS NOT NULL)) AS collection_answered,
              (EXISTS (SELECT 1 FROM planning_availability_reminders pr JOIN planning_cycles cy ON cy.id = :cycle
                WHERE pr.planning_cycle_id = cy.id
                   OR (pr.planning_cycle_id IS NULL AND pr.planning_id = cy.planning_id
                       AND pr.sent_at >= (SELECT MIN(opened_at) FROM availability_collections WHERE planning_cycle_id = cy.id)))
               OR EXISTS (SELECT 1 FROM period_preparation_deliveries pd
                WHERE pd.planning_cycle_id = :cycle AND pd.status IN ('SENT', 'SENDING'))) AS collection_contacted,
              (SELECT ac.id FROM availability_collections ac JOIN planning_cycles cy ON cy.id = ac.planning_cycle_id
                 WHERE ac.planning_cycle_id = :cycle AND ac.status = 'OPEN' AND ac.starts_at = cy.starts_at AND ac.ends_at = cy.ends_at
                   AND (SELECT COUNT(*) FROM availability_collections other WHERE other.planning_cycle_id = :cycle) = 1) AS sole_collection_id
        SQL, ['cycle' => $cycle->getId()]);

        // Only a blank period's collection follows its dates: once generated, published, calculated or prepared,
        // every collection keeps its window (docs/planning-cycles.md §5.2).
        $blank = 0 === (int) $row['generations'] + (int) $row['publications'] + (int) $row['jobs'] + (int) $row['duties'];

        return [
            'generations' => (int) $row['generations'],
            'publications' => (int) $row['publications'],
            'jobs' => (int) $row['jobs'],
            'duties' => (int) $row['duties'],
            'firstDutyDay' => $row['first_duty_day'] ?? null,
            'lastDutyDay' => $row['last_duty_day'] ?? null,
            'collections' => (int) $row['collections'],
            'firstCollectionStart' => $row['first_collection_start'] ?? null,
            'lastCollectionEnd' => $row['last_collection_end'] ?? null,
            'collectionAnswered' => (bool) $row['collection_answered'],
            'collectionContacted' => (bool) $row['collection_contacted'],
            'untouchedCollectionId' => $blank && !$row['collection_answered'] && !$row['collection_contacted'] && null !== ($row['sole_collection_id'] ?? null)
                ? (int) $row['sole_collection_id'] : null,
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
