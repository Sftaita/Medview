<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanningCycle;
use App\Entity\PlanningPublication;
use App\Entity\PlanningPublicationLine;
use App\Entity\PlanningPublicationLineKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlanningPublicationLine>
 */
class PlanningPublicationLineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningPublicationLine::class);
    }

    /**
     * @return list<PlanningPublicationLine>
     */
    public function findByPublication(PlanningPublication $publication): array
    {
        return $this->findBy(['publication' => $publication], ['id' => 'ASC']);
    }

    /**
     * The first publication of every line of the cycle up to $publication
     * included (docs/decisions.md D196): the lines that publication's
     * calendar, PDF and audience are made of — read from what was recorded,
     * never from the lines' current status.
     *
     * @return list<PlanningPublicationLine> in order of first publication
     */
    public function findFirstPublishedThrough(PlanningCycle $cycle, PlanningPublication $publication): array
    {
        return $this->createQueryBuilder('l')
            ->join('l.publication', 'p')
            ->andWhere('l.cycle = :cycle')
            ->andWhere('l.kind = :first')
            ->andWhere('p.publishedAt < :at OR (p.publishedAt = :at AND p.id <= :id)')
            ->setParameter('cycle', $cycle)
            ->setParameter('first', PlanningPublicationLineKind::FIRST_FOR_LINE)
            ->setParameter('at', $publication->getPublishedAt())
            ->setParameter('id', $publication->getId())
            ->orderBy('p.publishedAt', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
