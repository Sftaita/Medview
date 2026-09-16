<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ParticipationFactorChangeReason;
use App\Entity\TeamMemberParticipationPeriod;
use App\Entity\TeamMemberRole;
use App\Service\ParticipationPeriodService;
use App\Service\TeamMembershipService;
use App\Tests\PlanningDomainTestHelpers;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ParticipationPeriodServiceTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;

    public function testFactorAtReadsTheValueInForceAtEachDate(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $membershipService = self::getContainer()->get(TeamMembershipService::class);
        $participationService = self::getContainer()->get(ParticipationPeriodService::class);

        $team = $this->createTeam($em);
        $user = $this->createUser($em);
        $member = $membershipService->addMember($team, $user, TeamMemberRole::MEMBER, $this->date('2027-01-01'), 1.0);

        $participationService->changeFactor($member, $this->date('2027-07-01'), 0.5, ParticipationFactorChangeReason::CONTRACTUAL_CHANGE);

        self::assertSame(1.0, $participationService->factorAt($member, $this->date('2027-01-01')));
        self::assertSame(1.0, $participationService->factorAt($member, $this->date('2027-06-30')));
        self::assertSame(0.5, $participationService->factorAt($member, $this->date('2027-07-01')));
        self::assertSame(0.5, $participationService->factorAt($member, $this->date('2028-01-01')));
    }

    public function testChangingTheFactorNeverRewritesThePastSegment(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $membershipService = self::getContainer()->get(TeamMembershipService::class);
        $participationService = self::getContainer()->get(ParticipationPeriodService::class);

        $team = $this->createTeam($em);
        $user = $this->createUser($em);
        $member = $membershipService->addMember($team, $user, TeamMemberRole::MEMBER, $this->date('2027-01-01'), 1.0);
        $firstSegment = $member->getParticipationPeriods()->first();

        $participationService->changeFactor($member, $this->date('2027-07-01'), 0.5, ParticipationFactorChangeReason::CONTRACTUAL_CHANGE);

        self::assertSame(1.0, $firstSegment->toFloat(), 'the historical segment value must never change');
        self::assertEquals($this->date('2027-07-01'), $firstSegment->getValidTo());
        self::assertCount(2, $member->getParticipationPeriods());
    }

    public function testDatabaseRejectsOverlappingPeriodsEvenBypassingTheService(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $membershipService = self::getContainer()->get(TeamMembershipService::class);

        $team = $this->createTeam($em);
        $user = $this->createUser($em);
        $member = $membershipService->addMember($team, $user, TeamMemberRole::MEMBER, $this->date('2027-01-01'), 1.0);

        // Deliberately bypasses ParticipationPeriodService to prove the
        // exclusion constraint (not just application logic) is what
        // actually forbids an overlap.
        $overlapping = new TeamMemberParticipationPeriod(
            $member,
            $this->date('2027-03-01'),
            0.5,
            ParticipationFactorChangeReason::CONTRACTUAL_CHANGE,
        );
        $em->persist($overlapping);
        $em->flush();

        // The exclusion constraint is DEFERRABLE INITIALLY DEFERRED (see
        // migrations) so it does not fire on flush() alone — it fires at
        // COMMIT, which a real request's transaction eventually reaches.
        // Forcing it here reproduces that guarantee deterministically
        // inside a test whose own wrapping transaction is always rolled
        // back, never committed (dama/doctrine-test-bundle, D017).
        $this->expectException(DriverException::class);
        $em->getConnection()->executeStatement('SET CONSTRAINTS excl_participation_periods_no_overlap IMMEDIATE');
    }
}
