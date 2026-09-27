<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanningPublication;
use App\Entity\PlanningPublicationEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningPublicationEntry>
 */
class PlanningPublicationEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningPublicationEntry::class);
    }

    /**
     * Every entry of one publication, with its Duty (type, group, pattern)
     * and published member/user fetched in the same query.
     *
     * @return list<PlanningPublicationEntry> in chronological duty order
     */
    public function findByPublication(PlanningPublication $publication): array
    {
        return $this->createQueryBuilder('e')
            ->addSelect('d', 'dt', 'g', 'p', 'tm', 'u')
            ->join('e.duty', 'd')
            ->join('d.dutyType', 'dt')
            ->leftJoin('d.groupInstance', 'g')
            ->leftJoin('g.pattern', 'p')
            ->leftJoin('e.teamMember', 'tm')
            ->leftJoin('tm.user', 'u')
            ->andWhere('e.publication = :publication')
            ->setParameter('publication', $publication)
            ->orderBy('d.startsAt', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
