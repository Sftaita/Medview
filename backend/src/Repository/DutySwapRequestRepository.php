<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DutyAssignment;
use App\Entity\DutySwapAudience;
use App\Entity\DutySwapRequest;
use App\Entity\DutySwapRequestStatus;
use App\Entity\Planning;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<DutySwapRequest>
 */
class DutySwapRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DutySwapRequest::class);
    }

    public function findOneByStableId(string $stableId): ?DutySwapRequest
    {
        if (!Uuid::isValid($stableId)) {
            return null;
        }

        return $this->findOneBy(['stableId' => Uuid::fromString($stableId)]);
    }

    /**
     * "Mes demandes": every request $user made, newest first.
     *
     * @return list<DutySwapRequest>
     */
    public function findByRequester(User $user): array
    {
        return $this->findBy(['requester' => $user], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * "Propositions reçues": every request addressed to $user by name (an
     * AGREED request, or a SEARCH one sent to selected colleagues), newest first.
     *
     * @return list<DutySwapRequest>
     */
    public function findAddressedTo(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.recipients', 'rc')
            ->andWhere('rc.user = :user')
            ->setParameter('user', $user)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * "Demandes de l'équipe": the whole-line (ALL) requests of the lines
     * whose team $user belongs to (any stint, open or not — the request is
     * still filtered by DutySwapAccess), by somebody else, newest first.
     *
     * @return list<DutySwapRequest>
     */
    public function findTeamRequestsFor(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.line', 'l')
            ->andWhere('r.audience = :all')
            ->andWhere('r.requester <> :user')
            ->andWhere('EXISTS (SELECT 1 FROM App\Entity\PlanningTeamMember m WHERE m.planningTeam = l.planningTeam AND m.user = :user)')
            ->setParameter('all', DutySwapAudience::ALL)
            ->setParameter('user', $user)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every swap request of a Planning — the managers' read-only history.
     *
     * @return list<DutySwapRequest>
     */
    public function findByPlanning(Planning $planning): array
    {
        return $this->findBy(['planning' => $planning], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * @return list<DutySwapRequest>
     */
    public function findOpen(): array
    {
        return $this->findBy(['status' => DutySwapRequestStatus::OPEN], ['id' => 'ASC']);
    }

    /**
     * @return list<DutySwapRequest>
     */
    public function findOpenByRequester(User $user): array
    {
        return $this->findBy(['requester' => $user, 'status' => DutySwapRequestStatus::OPEN]);
    }

    /**
     * The OPEN requests offering one of these rows — made obsolete when a swap supersedes them.
     *
     * @param list<DutyAssignment> $assignments
     *
     * @return list<DutySwapRequest>
     */
    public function findOpenOfferingAny(array $assignments): array
    {
        if ([] === $assignments) {
            return [];
        }

        return $this->createQueryBuilder('r')
            ->andWhere('r.status = :open')
            ->andWhere('r.offeredAssignment IN (:assignments)')
            ->setParameter('open', DutySwapRequestStatus::OPEN)
            ->setParameter('assignments', $assignments)
            ->orderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
