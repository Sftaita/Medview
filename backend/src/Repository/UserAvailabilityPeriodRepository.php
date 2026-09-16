<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserAvailabilityPeriod;
use App\Entity\UserAvailabilityType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<UserAvailabilityPeriod>
 */
class UserAvailabilityPeriodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserAvailabilityPeriod::class);
    }

    /**
     * Never trusts the auto-increment $id in a public URL — see
     * docs/planning-domain.md "Identifiants stables".
     */
    public function findOneByStableId(string $stableId): ?UserAvailabilityPeriod
    {
        try {
            $uuid = Uuid::fromString($stableId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->findOneBy(['stableId' => $uuid]);
    }

    /**
     * @return list<UserAvailabilityPeriod>
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('p.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Existing periods of this User and $type whose [startsAt, endsAt]
     * range overlaps or *touches* the given one (docs/availability.md
     * §Chevauchement — stricter than Duty's plain overlap: two periods
     * that merely touch are still rejected). Only compares periods of the
     * same $type: UNAVAILABLE and PREFER_DUTY may legitimately coexist on
     * the same dates.
     *
     * @return list<UserAvailabilityPeriod>
     */
    public function findOverlappingOrTouchingForUser(
        User $user,
        UserAvailabilityType $type,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
        ?UserAvailabilityPeriod $excluding = null,
    ): array {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->andWhere('p.type = :type')
            ->andWhere('p.startsAt <= :endsAt')
            ->andWhere('p.endsAt >= :startsAt')
            ->setParameter('user', $user)
            ->setParameter('type', $type)
            ->setParameter('startsAt', $startsAt)
            ->setParameter('endsAt', $endsAt);

        if (null !== $excluding) {
            $qb->andWhere('p.id != :excludingId')->setParameter('excludingId', $excluding->getId());
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Every period of $user (both UNAVAILABLE and PREFER_DUTY — unlike
     * findOverlappingOrTouchingForUser(), a snapshot captures both) whose
     * [startsAt, endsAt] intersects [$from, $to] — what
     * PlanningSnapshotService copies into PlanningSnapshotAvailabilityPeriod
     * rows (docs/planning-generation.md §Indisponibilités et préférences).
     *
     * @return list<UserAvailabilityPeriod>
     */
    public function findIntersecting(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->andWhere('p.startsAt < :to')
            ->andWhere('p.endsAt > :from')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('p.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
