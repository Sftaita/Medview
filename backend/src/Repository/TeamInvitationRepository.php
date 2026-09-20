<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PlanningTeam;
use App\Entity\TeamInvitation;
use App\Entity\TeamInvitationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<TeamInvitation>
 */
class TeamInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeamInvitation::class);
    }

    public function findOneByStableId(string $stableId): ?TeamInvitation
    {
        try {
            $uuid = Uuid::fromString($stableId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $this->findOneBy(['stableId' => $uuid]);
    }

    public function findOneByTokenHash(string $tokenHash): ?TeamInvitation
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    /**
     * Same as findOneByTokenHash() but takes a row lock (SELECT … FOR
     * UPDATE) — must run inside a transaction. Two simultaneous
     * submissions of the same invitation link serialize on it: the second
     * one only proceeds once the first has committed, and then sees a
     * non-PENDING row.
     */
    public function findOneByTokenHashForUpdate(string $tokenHash): ?TeamInvitation
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.tokenHash = :hash')
            ->setParameter('hash', $tokenHash)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /**
     * Every PENDING invitation addressed to $email (already normalized),
     * locked for update — must run inside a transaction. Ordered so the
     * outcome is deterministic when several invitations conflict.
     *
     * @return list<TeamInvitation>
     */
    public function findPendingByEmailForUpdate(string $email): array
    {
        /** @var list<TeamInvitation> $result */
        $result = $this->createQueryBuilder('i')
            ->andWhere('i.email = :email')
            ->andWhere('i.status = :pending')
            ->setParameter('email', $email)
            ->setParameter('pending', TeamInvitationStatus::PENDING)
            ->orderBy('i.createdAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        return $result;
    }

    public function findPendingByTeamAndEmail(PlanningTeam $team, string $email): ?TeamInvitation
    {
        return $this->findOneBy([
            'planningTeam' => $team,
            'email' => $email,
            'status' => TeamInvitationStatus::PENDING,
        ]);
    }

    /**
     * Invitations still shown to team managers: PENDING rows (including
     * lazily-not-yet-flipped expired ones, which the caller displays via
     * effectiveStatusAt()), newest first.
     *
     * @return list<TeamInvitation>
     */
    public function findPendingByTeam(PlanningTeam $team): array
    {
        return $this->findBy(
            ['planningTeam' => $team, 'status' => TeamInvitationStatus::PENDING],
            ['createdAt' => 'DESC', 'id' => 'DESC'],
        );
    }
}
