<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DutyAssignment;
use App\Entity\PlanningGeneration;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<DutyAssignment>
 */
class DutyAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DutyAssignment::class);
    }

    public function findOneByStableId(string $stableId): ?DutyAssignment
    {
        try {
            $uuid = Uuid::fromString($stableId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->findOneBy(['stableId' => $uuid]);
    }

    /**
     * @return list<DutyAssignment>
     */
    public function findByGeneration(PlanningGeneration $generation): array
    {
        return $this->findBy(['generation' => $generation]);
    }

    /**
     * Assignments of the given generations, optionally restricted to one
     * person and/or to duties whose local date is in [$from, $toExclusive) —
     * the read model behind "a person's planning" (docs/planning.md §14).
     * Duty, member, user and duty type are fetched in the same query.
     *
     * @param list<PlanningGeneration> $generations
     *
     * @return list<DutyAssignment>
     */
    public function findForGenerations(
        array $generations,
        ?User $user = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $toExclusive = null,
    ): array {
        if ([] === $generations) {
            return [];
        }

        $qb = $this->createQueryBuilder('a')
            ->addSelect('d', 'dt', 'tm', 'u')
            ->join('a.duty', 'd')
            ->join('d.dutyType', 'dt')
            ->join('a.teamMember', 'tm')
            ->join('tm.user', 'u')
            ->andWhere('a.generation IN (:generations)')
            ->setParameter('generations', $generations)
            ->orderBy('d.startsAt', 'ASC')
            ->addOrderBy('a.id', 'ASC');

        if (null !== $user) {
            $qb->andWhere('tm.user = :user')->setParameter('user', $user);
        }
        if (null !== $from) {
            $qb->andWhere('d.localDate >= :from')->setParameter('from', $from, 'date_immutable');
        }
        if (null !== $toExclusive) {
            $qb->andWhere('d.localDate < :to')->setParameter('to', $toExclusive, 'date_immutable');
        }

        return $qb->getQuery()->getResult();
    }
}
