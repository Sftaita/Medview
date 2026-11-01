<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserAvailabilityPeriod;
use App\Entity\UserAvailabilitySource;
use App\Entity\UserAvailabilityType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
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
     * the same dates. Only MANUAL periods: a period imported from
     * SurgicalHub may overlap anything (docs/surgicalhub-integration.md §6.1),
     * exactly like the partial database constraint.
     *
     * The bounds are compared as instants, whatever offset they carry
     * (D219): normalised to UTC and bound as TIMESTAMPTZ. Left to Doctrine's
     * inference, a DateTimeImmutable is bound as a plain TIMESTAMP whose
     * offset is dropped ("2027-01-13 00:00:00" for 00:00+01:00) and that the
     * UTC session then reads as UTC, shifted by the offset: the check missed
     * a real overlap (the exclusion constraint then answered 500) or saw a
     * false one.
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
            ->andWhere('p.source = :manual')
            ->setParameter('manual', UserAvailabilitySource::MANUAL)
            ->andWhere('p.type = :type')
            ->andWhere('p.startsAt <= :endsAt')
            ->andWhere('p.endsAt >= :startsAt')
            ->setParameter('user', $user)
            ->setParameter('type', $type)
            ->setParameter('startsAt', self::utc($startsAt), Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('endsAt', self::utc($endsAt), Types::DATETIMETZ_IMMUTABLE);

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
     * $from/$to are instants, usually local midnights of the planning's
     * timezone: compared as such, never shifted by their offset (D220).
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
            ->setParameter('from', self::utc($from), Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('to', self::utc($to), Types::DATETIMETZ_IMMUTABLE)
            ->orderBy('p.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The $type periods of every User of $users intersecting [$from, $to[,
     * in one query — the absence export reads a whole planning's
     * participants at once instead of one query per person.
     *
     * @param list<User> $users
     *
     * @return list<UserAvailabilityPeriod>
     */
    public function findByTypeIntersectingForUsers(array $users, UserAvailabilityType $type, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ([] === $users) {
            return [];
        }

        return $this->createQueryBuilder('p')
            ->andWhere('p.user IN (:users)')
            ->andWhere('p.type = :type')
            ->andWhere('p.startsAt < :to')
            ->andWhere('p.endsAt > :from')
            ->setParameter('users', $users)
            ->setParameter('type', $type)
            ->setParameter('from', self::utc($from), Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('to', self::utc($to), Types::DATETIMETZ_IMMUTABLE)
            ->orderBy('p.startsAt', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** The same instant in UTC — no range query here depends on the offset its bounds carry (D219, D220). */
    public static function utc(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'));
    }
}
