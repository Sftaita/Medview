<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PeriodPreparation;
use App\Entity\PeriodPreparationReminder;
use App\Entity\PreparationActionState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PeriodPreparationReminder>
 */
class PeriodPreparationReminderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PeriodPreparationReminder::class);
    }

    /**
     * @return list<PeriodPreparationReminder> earliest first
     */
    public function findByPreparation(PeriodPreparation $preparation): array
    {
        return $this->findBy(['preparation' => $preparation], ['dueOn' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * @return list<PeriodPreparationReminder> earliest first
     */
    public function findScheduled(PeriodPreparation $preparation): array
    {
        return $this->findBy(['preparation' => $preparation, 'state' => PreparationActionState::SCHEDULED], ['dueOn' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * The ids of the preparations that have at least one reminder due at $now.
     *
     * @return list<int>
     */
    public function findPreparationIdsWithDueReminders(\DateTimeImmutable $now): array
    {
        return array_map('intval', $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT preparation_id FROM period_preparation_reminders WHERE state = :scheduled AND due_at <= :now GROUP BY preparation_id ORDER BY MIN(due_at), preparation_id',
            ['scheduled' => PreparationActionState::SCHEDULED->value, 'now' => $now],
            ['now' => Types::DATETIME_IMMUTABLE],
        ));
    }
}
