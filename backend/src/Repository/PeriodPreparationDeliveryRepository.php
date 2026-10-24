<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PeriodPreparation;
use App\Entity\PeriodPreparationDelivery;
use App\Entity\PeriodPreparationReminder;
use App\Entity\PlanningCycle;
use App\Entity\PreparationDeliveryKind;
use App\Entity\PreparationDeliveryStatus;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PeriodPreparationDelivery>
 */
class PeriodPreparationDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PeriodPreparationDelivery::class);
    }

    /**
     * Queues one email unless this person already has one for the same
     * announcement or reminder, whatever its state: a second run, a restart
     * or a concurrent runner never queues it twice — the unique indexes
     * decide (ON CONFLICT DO NOTHING). Returns whether a row was added.
     */
    public function queue(PeriodPreparation $preparation, PreparationDeliveryKind $kind, ?PeriodPreparationReminder $reminder, User $recipient, \DateTimeImmutable $now): bool
    {
        $conflict = PreparationDeliveryKind::ANNOUNCEMENT === $kind
            ? "(preparation_id, recipient_id) WHERE kind = 'ANNOUNCEMENT'"
            : '(reminder_id, recipient_id) WHERE reminder_id IS NOT NULL';

        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            "INSERT INTO period_preparation_deliveries (preparation_id, planning_cycle_id, kind, reminder_id, recipient_id, status, attempts, created_at)
             VALUES (:preparation, :cycle, :kind, :reminder, :recipient, :pending, 0, :now)
             ON CONFLICT {$conflict} DO NOTHING",
            [
                'preparation' => $preparation->getId(),
                'cycle' => $preparation->getCycle()->getId(),
                'kind' => $kind->value,
                'reminder' => $reminder?->getId(),
                'recipient' => $recipient->getId(),
                'pending' => PreparationDeliveryStatus::PENDING->value,
                'now' => $now,
            ],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * What a sender should try now: PENDING and FAILED rows under
     * MAX_ATTEMPTS, and SENDING rows claimed before $staleClaimBefore (a
     * sender that died mid-send) — oldest first.
     *
     * @return list<PeriodPreparationDelivery>
     */
    public function findDue(\DateTimeImmutable $staleClaimBefore): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.attempts < :max')
            ->andWhere('d.status IN (:waiting) OR (d.status = :sending AND d.claimedAt <= :stale)')
            ->setParameter('max', PeriodPreparationDelivery::MAX_ATTEMPTS)
            ->setParameter('waiting', [PreparationDeliveryStatus::PENDING->value, PreparationDeliveryStatus::FAILED->value])
            ->setParameter('sending', PreparationDeliveryStatus::SENDING->value)
            ->setParameter('stale', $staleClaimBefore, Types::DATETIME_IMMUTABLE)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Atomically makes the caller the only sender of this email — one
     * conditional UPDATE, as PlanningPublicationNotificationRepository::claim:
     * two runners never both win, a SENT, CANCELLED or exhausted row is never
     * claimed, nor a SENDING row whose claim is still fresh.
     */
    public function claim(PeriodPreparationDelivery $delivery, \DateTimeImmutable $now, \DateTimeImmutable $staleClaimBefore): bool
    {
        $claimed = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE period_preparation_deliveries
                SET status = :sending, claimed_at = :now, attempts = attempts + 1
              WHERE id = :id
                AND attempts < :max
                AND (status IN (:pending, :failed) OR (status = :sending AND claimed_at <= :stale))',
            [
                'sending' => PreparationDeliveryStatus::SENDING->value,
                'pending' => PreparationDeliveryStatus::PENDING->value,
                'failed' => PreparationDeliveryStatus::FAILED->value,
                'now' => $now,
                'stale' => $staleClaimBefore,
                'id' => $delivery->getId(),
                'max' => PeriodPreparationDelivery::MAX_ATTEMPTS,
            ],
            ['now' => Types::DATETIME_IMMUTABLE, 'stale' => Types::DATETIME_IMMUTABLE],
        );
        $this->getEntityManager()->refresh($delivery);

        return 1 === $claimed;
    }

    /**
     * Cancels the emails of $cycle not sent yet (PENDING or FAILED) — the
     * generation was launched. A SENDING row is left to its sender, which
     * checks the period again before sending.
     */
    public function cancelUnsent(PlanningCycle $cycle, string $reason): int
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE period_preparation_deliveries SET status = :cancelled, cancel_reason = :reason
              WHERE planning_cycle_id = :cycle AND status IN (:pending, :failed)',
            [
                'cancelled' => PreparationDeliveryStatus::CANCELLED->value,
                'reason' => $reason,
                'cycle' => $cycle->getId(),
                'pending' => PreparationDeliveryStatus::PENDING->value,
                'failed' => PreparationDeliveryStatus::FAILED->value,
            ],
        );
    }

    /**
     * Per announcement / reminder of $preparation (key 'announcement' or the
     * reminder id): emails queued, sent, given up after MAX_ATTEMPTS,
     * cancelled, and still on their way.
     *
     * @return array<string|int, array{queued: int, sent: int, failed: int, cancelled: int, pending: int}>
     */
    public function countsByAction(PeriodPreparation $preparation): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT COALESCE(reminder_id::text, 'announcement') AS action, status, attempts >= :max AS exhausted, COUNT(*) AS n
               FROM period_preparation_deliveries WHERE preparation_id = :preparation GROUP BY 1, 2, 3",
            ['preparation' => $preparation->getId(), 'max' => PeriodPreparationDelivery::MAX_ATTEMPTS],
        );
        $counts = [];
        foreach ($rows as $row) {
            $key = 'announcement' === $row['action'] ? 'announcement' : (int) $row['action'];
            $counts[$key] ??= ['queued' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0, 'pending' => 0];
            $n = (int) $row['n'];
            $counts[$key]['queued'] += $n;
            $bucket = match ($row['status']) {
                PreparationDeliveryStatus::SENT->value => 'sent',
                PreparationDeliveryStatus::CANCELLED->value => 'cancelled',
                PreparationDeliveryStatus::FAILED->value => $row['exhausted'] ? 'failed' : 'pending',
                default => 'pending',
            };
            $counts[$key][$bucket] += $n;
        }

        return $counts;
    }
}
