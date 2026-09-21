<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AvailabilityCollection;
use App\Entity\AvailabilityCollectionStatus;
use App\Entity\Planning;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AvailabilityCollection>
 */
class AvailabilityCollectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AvailabilityCollection::class);
    }

    public function findOneByStableId(string $stableId): ?AvailabilityCollection
    {
        try {
            $uuid = Uuid::fromString($stableId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->findOneBy(['stableId' => $uuid]);
    }

    /**
     * Newest window first: the natural reading order of the history.
     *
     * @return list<AvailabilityCollection>
     */
    public function findByPlanning(Planning $planning): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.planning = :planning')
            ->setParameter('planning', $planning)
            ->orderBy('c.startsAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<AvailabilityCollection>
     */
    public function findOpenByPlanning(Planning $planning): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.planning = :planning')
            ->andWhere('c.status = :status')
            ->setParameter('planning', $planning)
            ->setParameter('status', AvailabilityCollectionStatus::OPEN)
            ->orderBy('c.startsAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Any collection of $planning whose [startsAt, endsAt) date window
     * intersects the given one — the service-level twin of the database's
     * exclusion constraint, so the caller gets a friendly error first.
     *
     * @return list<AvailabilityCollection>
     */
    public function findOverlapping(Planning $planning, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.planning = :planning')
            ->andWhere('c.startsAt < :endsAt')
            ->andWhere('c.endsAt > :startsAt')
            ->setParameter('planning', $planning)
            ->setParameter('startsAt', $startsAt)
            ->setParameter('endsAt', $endsAt)
            ->getQuery()
            ->getResult();
    }
}
