<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<TeamMember>
 */
class TeamMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeamMember::class);
    }

    /**
     * The at-most-one open membership for this (team, user) pair — the
     * same invariant the database's partial unique index enforces.
     */
    public function findOpenMembership(Team $team, User $user): ?TeamMember
    {
        return $this->findOneBy([
            'team' => $team,
            'user' => $user,
            'membershipEnd' => null,
        ]);
    }

    /**
     * @return list<TeamMember>
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user]);
    }

    public function findOneByStableId(string $stableId): ?TeamMember
    {
        try {
            $uuid = Uuid::fromString($stableId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->findOneBy(['stableId' => $uuid]);
    }

    /**
     * @return list<TeamMember>
     */
    public function findByTeam(Team $team): array
    {
        return $this->findBy(['team' => $team]);
    }

    /**
     * Members of $team whose membership stint intersects [$from, $to) —
     * the "relevant members" a PlanningSnapshot must capture
     * (docs/planning-generation.md §Membres). A member who joined and left
     * entirely outside the window is not relevant to this generation.
     *
     * @return list<TeamMember>
     */
    public function findIntersecting(Team $team, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.team = :team')
            ->andWhere('m.membershipStart < :to')
            ->andWhere('m.membershipEnd IS NULL OR m.membershipEnd > :from')
            ->setParameter('team', $team)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('m.membershipStart', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
