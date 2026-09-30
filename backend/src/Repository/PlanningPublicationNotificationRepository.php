<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanningPublication;
use App\Entity\PlanningPublicationNotification;
use App\Entity\PublicationNotificationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningPublicationNotification>
 */
class PlanningPublicationNotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningPublicationNotification::class);
    }

    /**
     * @return list<PlanningPublicationNotification>
     */
    public function findByPublication(PlanningPublication $publication): array
    {
        return $this->findBy(['publication' => $publication], ['id' => 'ASC']);
    }

    /**
     * What the retry command must (re)try (docs/decisions.md D172): FAILED
     * rows, PENDING rows older than $pendingBefore (never attempted — the
     * request that recorded them died before sending), and SENDING rows
     * claimed before $staleClaimBefore (a sender that died mid-send) — all
     * under MAX_ATTEMPTS.
     *
     * @return list<PlanningPublicationNotification> oldest first
     */
    public function findDue(\DateTimeImmutable $pendingBefore, \DateTimeImmutable $staleClaimBefore): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.attempts < :max')
            ->andWhere('n.status = :failed OR (n.status = :pending AND n.createdAt <= :pendingBefore) OR (n.status = :sending AND n.claimedAt <= :staleClaimBefore)')
            ->setParameter('max', PlanningPublicationNotification::MAX_ATTEMPTS)
            ->setParameter('failed', PublicationNotificationStatus::FAILED)
            ->setParameter('pending', PublicationNotificationStatus::PENDING)
            ->setParameter('sending', PublicationNotificationStatus::SENDING)
            ->setParameter('pendingBefore', $pendingBefore, Types::DATETIME_IMMUTABLE)
            ->setParameter('staleClaimBefore', $staleClaimBefore, Types::DATETIME_IMMUTABLE)
            ->orderBy('n.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Atomically makes the caller the only sender of this email: one
     * conditional UPDATE, so two concurrent senders (a double request, the
     * publication request and the retry command) can never both win. Never
     * claims a SENT, CANCELLED or exhausted row, nor a SENDING row whose
     * claim is still fresh. The entity is refreshed either way.
     */
    public function claim(PlanningPublicationNotification $notification, \DateTimeImmutable $now, \DateTimeImmutable $staleClaimBefore): bool
    {
        $claimed = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE planning_publication_notifications
                SET status = :sending, claimed_at = :now, attempts = attempts + 1
              WHERE id = :id
                AND attempts < :max
                AND (status IN (:pending, :failed) OR (status = :sending AND claimed_at <= :staleClaimBefore))',
            [
                'sending' => PublicationNotificationStatus::SENDING->value,
                'pending' => PublicationNotificationStatus::PENDING->value,
                'failed' => PublicationNotificationStatus::FAILED->value,
                'now' => $now,
                'staleClaimBefore' => $staleClaimBefore,
                'id' => $notification->getId(),
                'max' => PlanningPublicationNotification::MAX_ATTEMPTS,
            ],
            ['now' => Types::DATETIME_IMMUTABLE, 'staleClaimBefore' => Types::DATETIME_IMMUTABLE],
        );

        $this->getEntityManager()->refresh($notification);

        return 1 === $claimed;
    }
}
