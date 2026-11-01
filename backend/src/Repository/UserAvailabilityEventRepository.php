<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserAvailabilityEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserAvailabilityEvent>
 */
class UserAvailabilityEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserAvailabilityEvent::class);
    }

    /**
     * The events of these people whose previous or new dates meet [$from, $to), oldest first.
     *
     * @param list<User> $users
     *
     * @return list<UserAvailabilityEvent>
     */
    public function findTouching(array $users, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ([] === $users) {
            return [];
        }

        /** @var list<UserAvailabilityEvent> $events */
        $events = $this->createQueryBuilder('e')
            ->andWhere('e.user IN (:users)')
            ->andWhere('(e.previousStartsAt < :to AND e.previousEndsAt > :from) OR (e.newStartsAt < :to AND e.newEndsAt > :from)')
            ->setParameter('users', $users)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.occurredAt', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $events;
    }

    /**
     * @return list<UserAvailabilityEvent>
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['occurredAt' => 'ASC', 'id' => 'ASC']);
    }
}
