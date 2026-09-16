<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanningSnapshot;
use App\Entity\PlanningSnapshotMember;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PlanningSnapshotMember>
 */
class PlanningSnapshotMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanningSnapshotMember::class);
    }

    /**
     * The snapshot row a TeamMember was captured as, if any — the sole
     * lookup behind DutyAssignmentService's "this TeamMember is part of
     * this generation's snapshot" check.
     */
    public function findOneBySnapshotAndTeamMemberStableId(PlanningSnapshot $snapshot, Uuid $teamMemberStableId): ?PlanningSnapshotMember
    {
        return $this->findOneBy([
            'snapshot' => $snapshot,
            'sourceTeamMemberStableId' => $teamMemberStableId,
        ]);
    }
}
