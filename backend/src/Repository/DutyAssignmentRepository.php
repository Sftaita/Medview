<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Duty;
use App\Entity\DutyAssignment;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningTeamMember;
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
     * Always `current = true` only (docs/decisions.md D131) — a superseded
     * row must never resurface as if it were still in effect.
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
            ->andWhere('a.current = true')
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

    /**
     * The single current assignment of one Duty within one generation, or
     * null when it is not currently covered (docs/decisions.md D131).
     */
    public function findCurrentByGenerationAndDuty(PlanningGeneration $generation, Duty $duty): ?DutyAssignment
    {
        return $this->findOneBy(['generation' => $generation, 'duty' => $duty, 'current' => true]);
    }

    /**
     * The current assignments of several Duties within one generation, in
     * one query, keyed by duty id — used to resolve a whole
     * DutyGroupInstance's current state at once.
     *
     * @param list<Duty> $duties
     *
     * @return array<int, DutyAssignment>
     */
    public function findCurrentByGenerationAndDuties(PlanningGeneration $generation, array $duties): array
    {
        if ([] === $duties) {
            return [];
        }

        $rows = $this->createQueryBuilder('a')
            ->andWhere('a.generation = :generation')
            ->andWhere('a.duty IN (:duties)')
            ->andWhere('a.current = true')
            ->setParameter('generation', $generation)
            ->setParameter('duties', $duties)
            ->getQuery()
            ->getResult();

        $byDutyId = [];
        foreach ($rows as $row) {
            $byDutyId[(int) $row->getDuty()->getId()] = $row;
        }

        return $byDutyId;
    }

    /**
     * A candidate's other current assignments within the same generation
     * (i.e. the same PlanningPeriod/line) — the live equivalent of what
     * AssignmentConflictAnalyzer computes from the frozen snapshot, but
     * read from the actual current calendar (docs/decisions.md D131).
     * Excludes the duties currently being reassigned themselves, so a
     * candidate is never reported as conflicting with the very duty they
     * are being considered for.
     *
     * @param list<Duty> $excludingDuties
     *
     * @return list<DutyAssignment>
     */
    public function findCurrentForTeamMemberInGeneration(PlanningGeneration $generation, PlanningTeamMember $teamMember, array $excludingDuties = []): array
    {
        $qb = $this->createQueryBuilder('a')
            ->addSelect('d')
            ->join('a.duty', 'd')
            ->andWhere('a.generation = :generation')
            ->andWhere('a.teamMember = :teamMember')
            ->andWhere('a.current = true')
            ->setParameter('generation', $generation)
            ->setParameter('teamMember', $teamMember);

        if ([] !== $excludingDuties) {
            $qb->andWhere('a.duty NOT IN (:excluding)')->setParameter('excluding', $excludingDuties);
        }

        return $qb->getQuery()->getResult();
    }
}
