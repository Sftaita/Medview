<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Planning;
use App\Entity\PlanningTeam;
use App\Entity\PlanningTeamMember;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PlanningTeamMember>
 */
class PlanningTeamMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningTeamMember::class);
    }

    /**
     * The at-most-one open membership for this (team, user) pair.
     */
    public function findOpenMembership(PlanningTeam $planningTeam, User $user): ?PlanningTeamMember
    {
        return $this->findOneBy([
            'planningTeam' => $planningTeam,
            'user' => $user,
            'membershipEnd' => null,
        ]);
    }

    /**
     * The at-most-one open membership for this User within this Planning,
     * across whichever of its PlanningTeams they belong to
     * (docs/decisions.md D080) — the same invariant the database's partial
     * unique index on (planning_id, user_id) WHERE membership_end IS NULL
     * enforces. Replaces the abandoned app-wide findOpenMembershipForUser()
     * (docs/decisions.md D072, replaced): the same User may simultaneously
     * hold an open membership in a *different* Planning.
     */
    public function findOpenMembershipForUserInPlanning(Planning $planning, User $user): ?PlanningTeamMember
    {
        return $this->findOneBy([
            'planning' => $planning,
            'user' => $user,
            'membershipEnd' => null,
        ]);
    }

    /**
     * @return list<PlanningTeamMember>
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user]);
    }

    public function findOneByStableId(string $stableId): ?PlanningTeamMember
    {
        try {
            $uuid = Uuid::fromString($stableId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->findOneBy(['stableId' => $uuid]);
    }

    /**
     * @return list<PlanningTeamMember>
     */
    public function findByTeam(PlanningTeam $planningTeam): array
    {
        return $this->findBy(['planningTeam' => $planningTeam]);
    }

    /**
     * Members of any PlanningTeam of $planning whose membership stint
     * intersects [$from, $to) — who is expected to answer an availability
     * collection over that window (docs/availability-collection.md §2).
     *
     * @return list<PlanningTeamMember>
     */
    public function findIntersectingForPlanning(Planning $planning, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('u')
            ->join('m.user', 'u')
            ->andWhere('m.planning = :planning')
            ->andWhere('m.membershipStart < :to')
            ->andWhere('m.membershipEnd IS NULL OR m.membershipEnd > :from')
            ->setParameter('planning', $planning)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('m.membershipStart', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Members of $planningTeam whose membership stint intersects
     * [$from, $to) — the "relevant members" a PlanningSnapshot must capture
     * (docs/planning-generation.md §Membres). A member who joined and left
     * entirely outside the window is not relevant to this generation.
     *
     * @return list<PlanningTeamMember>
     */
    public function findIntersecting(PlanningTeam $planningTeam, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.planningTeam = :team')
            ->andWhere('m.membershipStart < :to')
            ->andWhere('m.membershipEnd IS NULL OR m.membershipEnd > :from')
            ->setParameter('team', $planningTeam)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('m.membershipStart', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
