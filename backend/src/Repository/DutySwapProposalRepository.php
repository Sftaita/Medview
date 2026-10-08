<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DutyAssignment;
use App\Entity\DutySwapProposal;
use App\Entity\DutySwapProposalStatus;
use App\Entity\DutySwapRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<DutySwapProposal>
 */
class DutySwapProposalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DutySwapProposal::class);
    }

    public function findOneByStableId(string $stableId): ?DutySwapProposal
    {
        if (!Uuid::isValid($stableId)) {
            return null;
        }

        return $this->findOneBy(['stableId' => Uuid::fromString($stableId)]);
    }

    /**
     * The PENDING proposals (of any request) offering one of these rows as
     * their counterpart — made obsolete when a swap supersedes them.
     *
     * @param list<DutyAssignment> $assignments
     *
     * @return list<DutySwapProposal>
     */
    public function findPendingWithCounterpartAny(array $assignments): array
    {
        if ([] === $assignments) {
            return [];
        }

        return $this->createQueryBuilder('p')
            ->andWhere('p.status = :pending')
            ->andWhere('p.counterpartAssignment IN (:assignments)')
            ->setParameter('pending', DutySwapProposalStatus::PENDING)
            ->setParameter('assignments', $assignments)
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every request (other than $user's own) on which $user authored a proposal — so a request
     * they answered stays in their "Demandes de l'équipe" history after it closes.
     *
     * @return list<DutySwapRequest>
     */
    public function findRequestsProposedOnBy(User $user): array
    {
        $proposals = $this->createQueryBuilder('p')
            ->innerJoin('p.request', 'r')
            ->addSelect('r')
            ->andWhere('p.author = :user')
            ->andWhere('r.requester <> :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $requests = [];
        foreach ($proposals as $proposal) {
            $requests[(int) $proposal->getRequest()->getId()] = $proposal->getRequest();
        }

        return array_values($requests);
    }
}
