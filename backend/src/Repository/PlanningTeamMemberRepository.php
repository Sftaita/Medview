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
     * The at-most-one open membership for this (team, user) pair — the
     * invariant of the partial unique index on (planning_team_id, user_id)
     * WHERE membership_end IS NULL (docs/decisions.md D150).
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
     * Every open membership of this User within this Planning — one per
     * team (line) they currently belong to. Since docs/decisions.md D150
     * (relaxing D080) there can be several: never pick "the" membership of
     * a User in a Planning with findOneBy(), the result would depend on row
     * order. Ordered by id so callers iterate deterministically.
     *
     * @return list<PlanningTeamMember>
     */
    public function findOpenMembershipsForUserInPlanning(Planning $planning, User $user): array
    {
        return $this->findBy(
            ['planning' => $planning, 'user' => $user, 'membershipEnd' => null],
            ['id' => 'ASC'],
        );
    }

    /**
     * Whether this User currently belongs to at least one team of this Planning.
     */
    public function hasOpenMembershipInPlanning(Planning $planning, User $user): bool
    {
        return [] !== $this->findOpenMembershipsForUserInPlanning($planning, $user);
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
