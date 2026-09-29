<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Planning;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriodStatus;
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

    /**
     * Plannings with at least one active line whose period is PUBLISHED —
     * the only ones the weekly duty reminder concerns (docs/decisions.md D146).
     *
     * @return list<Planning>
     */
    public function findWithPublishedLine(): array
    {
        return $this->createQueryBuilder('p')
            ->distinct()
            ->join(PlanningLine::class, 'l', 'WITH', 'l.planning = p AND l.active = true')
            ->join('l.planningPeriod', 'pp')
            ->andWhere('pp.status = :published')
            ->setParameter('published', PlanningPeriodStatus::PUBLISHED)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Plannings with at least one active PUBLISHED line where $user holds or
     * has ever held a membership — ended ones included: a duty somebody did
     * before leaving a team stays theirs ("Mes gardes", docs/decisions.md D168).
     *
     * @return list<Planning>
     */
    public function findWithPublishedLineForMember(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->distinct()
            ->join(PlanningLine::class, 'l', 'WITH', 'l.planning = p AND l.active = true')
            ->join('l.planningPeriod', 'pp')
            ->join('App\Entity\PlanningTeamMember', 'tm', 'WITH', 'tm.planning = p AND tm.user = :user')
            ->andWhere('pp.status = :published')
            ->setParameter('published', PlanningPeriodStatus::PUBLISHED)
            ->setParameter('user', $user)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
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
