<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Planning;
use App\Entity\PlanningAvailabilityReminder;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningAvailabilityReminder>
 */
class PlanningAvailabilityReminderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningAvailabilityReminder::class);
    }

    /**
     * The reminder history of one person for one planning, newest first.
     *
     * @return list<PlanningAvailabilityReminder>
     */
    public function findByRecipient(Planning $planning, User $recipient, int $limit = 20): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('s')
            ->join('r.sentBy', 's')
            ->andWhere('r.planning = :planning')
            ->andWhere('r.recipient = :recipient')
            ->setParameter('planning', $planning)
            ->setParameter('recipient', $recipient)
            ->orderBy('r.sentAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findLastForRecipient(Planning $planning, User $recipient): ?PlanningAvailabilityReminder
    {
        return $this->findByRecipient($planning, $recipient, 1)[0] ?? null;
    }

    /**
     * The most recent reminder instant of every recipient of the planning
     * that has one, keyed by the recipient's user id — the "Dernier rappel"
     * column, in one query.
     *
     * @return array<int, \DateTimeImmutable>
     */
    public function lastSentAtByRecipient(Planning $planning): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.recipient) AS recipientId', 'MAX(r.sentAt) AS lastSentAt')
            ->andWhere('r.planning = :planning')
            ->setParameter('planning', $planning)
            ->groupBy('r.recipient')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['recipientId']] = new \DateTimeImmutable((string) $row['lastSentAt']);
        }

        return $result;
    }
}
