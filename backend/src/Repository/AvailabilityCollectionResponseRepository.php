<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AvailabilityCollection;
use App\Entity\AvailabilityCollectionResponse;
use App\Entity\AvailabilityCollectionStatus;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AvailabilityCollectionResponse>
 */
class AvailabilityCollectionResponseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AvailabilityCollectionResponse::class);
    }

    public function findOneForUser(AvailabilityCollection $collection, User $user): ?AvailabilityCollectionResponse
    {
        return $this->findOneBy(['collection' => $collection, 'user' => $user]);
    }

    /**
     * Every response of a collection with its respondent, ordered by name
     * so a list reads the same way on every call.
     *
     * @return list<AvailabilityCollectionResponse>
     */
    public function findByCollection(AvailabilityCollection $collection): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('u')
            ->join('r.user', 'u')
            ->andWhere('r.collection = :collection')
            ->setParameter('collection', $collection)
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->addOrderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The responses of OPEN collections that still count for $user: what
     * the availability-change hook and "my pending collections" both read.
     * Closed collections are frozen history and never appear here.
     *
     * @return list<AvailabilityCollectionResponse>
     */
    public function findActiveInOpenCollectionsForUser(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('c', 'p')
            ->join('r.collection', 'c')
            ->join('c.planning', 'p')
            ->andWhere('r.user = :user')
            ->andWhere('c.status = :open')
            ->andWhere('r.withdrawnAt IS NULL OR r.acknowledgedAt IS NOT NULL')
            ->setParameter('user', $user)
            ->setParameter('open', AvailabilityCollectionStatus::OPEN)
            ->orderBy('c.startsAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
