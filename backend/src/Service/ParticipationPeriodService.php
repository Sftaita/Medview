<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ParticipationFactorChangeReason;
use App\Entity\PlanningTeamMember;
use App\Entity\TeamMemberParticipationPeriod;
use App\Repository\TeamMemberParticipationPeriodRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The only legal way to change a TeamMember's participationFactor
 * (docs/allocation-algorithm.md §20, D035) — closes the currently open
 * segment and opens a new one, so participationFactorAt() always reads
 * whichever value was actually in force at a given date, never a value
 * silently rewritten after the fact.
 */
final class ParticipationPeriodService
{
    public function __construct(
        private readonly TeamMemberParticipationPeriodRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws \LogicException if $teamMember has no open period to close
     *                         (its membership is likely already closed —
     *                         use PlanningTeamMembershipService::endMembership()
     *                         for that case instead)
     */
    public function changeFactor(
        PlanningTeamMember $teamMember,
        \DateTimeImmutable $effectiveFrom,
        float $factor,
        ParticipationFactorChangeReason $reason,
    ): TeamMemberParticipationPeriod {
        $openPeriod = $this->repository->findOpenPeriod($teamMember);

        if (null === $openPeriod) {
            throw new \LogicException('This TeamMember has no open participation period to change.');
        }

        $openPeriod->close($effectiveFrom);

        $newPeriod = new TeamMemberParticipationPeriod($teamMember, $effectiveFrom, $factor, $reason);
        $this->entityManager->persist($newPeriod);
        $this->entityManager->flush();

        // The overlap exclusion constraint is DEFERRABLE INITIALLY DEFERRED
        // (see migrations) precisely so the UPDATE that closes $openPeriod
        // and the INSERT of $newPeriod above can coexist mid-transaction.
        // Forcing an immediate check here — rather than waiting for an
        // eventual COMMIT that a long-lived request transaction might not
        // reach for a while — surfaces a genuine violation (a bug
        // elsewhere bypassing this service) right away instead of letting
        // it surface confusingly far from its cause.
        $this->entityManager->getConnection()->executeStatement(
            'SET CONSTRAINTS excl_participation_periods_no_overlap IMMEDIATE',
        );

        return $newPeriod;
    }

    /**
     * participationFactorAt(teamMember, date) — docs/allocation-algorithm.md
     * §4/§20. Null means no period covers that date (before membership
     * start, or after an unclosed gap that should never legally exist).
     */
    public function factorAt(PlanningTeamMember $teamMember, \DateTimeImmutable $date): ?float
    {
        return $this->repository->findEffectiveAt($teamMember, $date)?->toFloat();
    }
}
