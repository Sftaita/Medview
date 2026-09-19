<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Planning;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Planning>
 */
class PlanningRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Planning::class);
    }

    public function findOneByStableId(string $stableId): ?Planning
    {
        try {
            $uuid = Uuid::fromString($stableId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->findOneBy(['stableId' => $uuid]);
    }

    /**
     * Plannings $user may at least VIEW (docs/planning.md §Autorisations,
     * D076): ones they created, or ones where they currently hold an open
     * membership in a PlanningTeam powering one of its PlanningLines. Never a
     * MANAGE right by itself — see PlanningVoter for that distinction.
     *
     * @return list<Planning>
     */
    public function findVisibleTo(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('App\Entity\PlanningTeamMember', 'tm', 'WITH', 'tm.planning = p AND tm.membershipEnd IS NULL')
            ->andWhere('p.creator = :user OR tm.user = :user')
            ->setParameter('user', $user)
            ->distinct()
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
