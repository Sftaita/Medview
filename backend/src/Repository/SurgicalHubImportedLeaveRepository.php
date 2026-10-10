<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SurgicalHubImportedLeave;
use App\Entity\SurgicalHubLink;
use App\Entity\SurgicalHubLinkStatus;
use App\Entity\User;
use App\Entity\UserAvailabilityPeriod;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SurgicalHubImportedLeave>
 */
class SurgicalHubImportedLeaveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SurgicalHubImportedLeave::class);
    }

    public function findOneByPeriod(UserAvailabilityPeriod $period): ?SurgicalHubImportedLeave
    {
        return $this->findOneBy(['period' => $period]);
    }

    /**
     * Ids of $user's imported periods still kept up to date: those of a pair
     * of accounts that has a current (ACTIVE or SUSPENDED) association —
     * whichever association imported them, so that periods kept by a
     * revocation count as synchronised again as soon as the same pair is
     * associated anew, before the next synchronisation takes them over.
     * Every other imported period may be removed by its owner (§9.1).
     *
     * @return array<int, true>
     */
    public function findSynchronisedPeriodIds(User $user): array
    {
        $ids = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.period) AS periodId')
            ->andWhere('i.user = :user')
            ->andWhere('EXISTS (SELECT l.id FROM '.SurgicalHubLink::class.' l WHERE l.user = i.user AND l.surgicalHubUserId = i.surgicalHubUserId AND l.status IN (:current))')
            ->setParameter('user', $user)
            ->setParameter('current', [SurgicalHubLinkStatus::ACTIVE, SurgicalHubLinkStatus::SUSPENDED])
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * Every import of $link's pair of accounts (its own, and those of an
     * earlier association of the same pair), indexed by SurgicalHub absence id
     * (a numeric id becomes an int key — read getExternalAbsenceId()).
     *
     * @return array<array-key, SurgicalHubImportedLeave>
     */
    public function findForPairOf(SurgicalHubLink $link): array
    {
        $rows = $this->createQueryBuilder('i')
            ->addSelect('p')
            ->join('i.period', 'p')
            ->andWhere('i.user = :user')
            ->andWhere('i.surgicalHubUserId = :surgicalHubUserId')
            ->setParameter('user', $link->getUser())
            ->setParameter('surgicalHubUserId', $link->getSurgicalHubUserId())
            ->getQuery()
            ->getResult();

        $byAbsenceId = [];
        foreach ($rows as $row) {
            $byAbsenceId[$row->getExternalAbsenceId()] = $row;
        }

        return $byAbsenceId;
    }
}
