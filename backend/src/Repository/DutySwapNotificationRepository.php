<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DutySwapNotification;
use App\Entity\DutySwapNotificationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DutySwapNotification>
 */
class DutySwapNotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DutySwapNotification::class);
    }

    /**
     * What the maintenance command must (re)try — same rule as the
     * publication outbox (docs/decisions.md D172): FAILED rows, PENDING rows
     * older than $pendingBefore, SENDING rows claimed before
     * $staleClaimBefore, all under MAX_ATTEMPTS.
     *
     * @return list<DutySwapNotification> oldest first
     */
    public function findDue(\DateTimeImmutable $pendingBefore, \DateTimeImmutable $staleClaimBefore): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.attempts < :max')
            ->andWhere('n.status = :failed OR (n.status = :pending AND n.createdAt <= :pendingBefore) OR (n.status = :sending AND n.claimedAt <= :staleClaimBefore)')
            ->setParameter('max', DutySwapNotification::MAX_ATTEMPTS)
            ->setParameter('failed', DutySwapNotificationStatus::FAILED)
            ->setParameter('pending', DutySwapNotificationStatus::PENDING)
            ->setParameter('sending', DutySwapNotificationStatus::SENDING)
            ->setParameter('pendingBefore', $pendingBefore, Types::DATETIME_IMMUTABLE)
            ->setParameter('staleClaimBefore', $staleClaimBefore, Types::DATETIME_IMMUTABLE)
            ->orderBy('n.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Atomically makes the caller the only sender of this email — one
     * conditional UPDATE, exactly like
     * PlanningPublicationNotificationRepository::claim(): never a SENT,
     * CANCELLED or exhausted row, never a SENDING row whose claim is fresh.
     */
    public function claim(DutySwapNotification $notification, \DateTimeImmutable $now, \DateTimeImmutable $staleClaimBefore): bool
    {
        $claimed = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE duty_swap_notifications
                SET status = :sending, claimed_at = :now, attempts = attempts + 1
              WHERE id = :id
                AND attempts < :max
                AND (status IN (:pending, :failed) OR (status = :sending AND claimed_at <= :staleClaimBefore))',
            [
                'sending' => DutySwapNotificationStatus::SENDING->value,
                'pending' => DutySwapNotificationStatus::PENDING->value,
                'failed' => DutySwapNotificationStatus::FAILED->value,
                'now' => $now,
                'staleClaimBefore' => $staleClaimBefore,
                'id' => $notification->getId(),
                'max' => DutySwapNotification::MAX_ATTEMPTS,
            ],
            ['now' => Types::DATETIME_IMMUTABLE, 'staleClaimBefore' => Types::DATETIME_IMMUTABLE],
        );

        $this->getEntityManager()->refresh($notification);

        return 1 === $claimed;
    }
}
