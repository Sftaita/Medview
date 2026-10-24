<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PeriodPreparation;
use App\Entity\PlanningCycle;
use App\Entity\PreparationActionState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PeriodPreparation>
 */
class PeriodPreparationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PeriodPreparation::class);
    }

    public function findOneByCycle(PlanningCycle $cycle): ?PeriodPreparation
    {
        return $this->findOneBy(['cycle' => $cycle]);
    }

    /**
     * The ids of the preparations whose announcement is due at $now, oldest first.
     *
     * @return list<int>
     */
    public function findDueAnnouncementIds(\DateTimeImmutable $now): array
    {
        return array_map('intval', $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT id FROM period_preparations WHERE announcement_state = :scheduled AND announce_at <= :now ORDER BY announce_at, id',
            ['scheduled' => PreparationActionState::SCHEDULED->value, 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        ));
    }

    /**
     * Deletes the preparation of a period being deleted, with its reminders.
     * Never called once anything was queued: a period whose collection opened
     * is never deleted (PlanningCycleService::deleteLast), and the foreign key
     * of the outbox would refuse it anyway.
     */
    public function forgetCycle(PlanningCycle $cycle): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement('DELETE FROM period_preparation_reminders WHERE preparation_id IN (SELECT id FROM period_preparations WHERE planning_cycle_id = :cycle)', ['cycle' => $cycle->getId()]);
        $connection->executeStatement('DELETE FROM period_preparations WHERE planning_cycle_id = :cycle', ['cycle' => $cycle->getId()]);
    }

    /**
     * Locks the row until the end of the transaction: a second runner waits, then sees what the first one did.
     */
    public function lock(int $id): void
    {
        $this->getEntityManager()->getConnection()->fetchOne('SELECT id FROM period_preparations WHERE id = :id FOR UPDATE', ['id' => $id]);
    }
}
