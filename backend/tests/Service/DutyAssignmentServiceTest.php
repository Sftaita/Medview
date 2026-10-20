<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\DutyAssignmentSource;
use App\Entity\FairnessPeriod;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningSnapshot;
use App\Entity\TeamMemberRole;
use App\Exception\InvalidDutyAssignmentException;
use App\Repository\PlanningSnapshotRepository;
use App\Service\DutyAssignmentService;
use App\Service\DutyMaterializationService;
use App\Service\PlanningRuleSetService;
use App\Service\PlanningSnapshotService;
use App\Service\PlanningTeamMembershipService;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The structural invariants every controlled write path relies on
 * (docs/planning-generation.md §16, docs/decisions.md D197) — formerly
 * exercised through the technical endpoint, now closed.
 */
final class DutyAssignmentServiceTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function service(): DutyAssignmentService
    {
        return static::getContainer()->get(DutyAssignmentService::class);
    }

    private function snapshotted(PlanningPeriod $period): array
    {
        $generation = new PlanningGeneration($period);
        $this->em()->persist($generation);
        $this->em()->flush();
        static::getContainer()->get(PlanningSnapshotService::class)->createSnapshot($generation);

        return [$generation, static::getContainer()->get(PlanningSnapshotRepository::class)->findOneByGeneration($generation) ?? self::fail('no snapshot')];
    }

    public function testAManualRowIsAlwaysManualAndNeverFlushedByTheService(): void
    {
        self::bootKernel();
        $team = $this->createTeam($this->em());
        $period = $this->createPlanningPeriod($this->em(), $team, '2027-01-01', '2027-05-01');
        $this->activateRuleSet(static::getContainer()->get(PlanningRuleSetService::class), $team);
        $member = $this->addMember(static::getContainer()->get(PlanningTeamMembershipService::class), $team, $this->createUser($this->em()), TeamMemberRole::MEMBER);
        $duty = $this->createDuty(static::getContainer()->get(DutyMaterializationService::class), $period, $this->createDutyType($this->em(), $team), '2027-02-01 08:00', '2027-02-01 20:00');
        [$generation, $snapshot] = $this->snapshotted($period);

        $assignment = $this->service()->createManualBatchItem($generation, $snapshot, $duty, $member);

        self::assertSame(DutyAssignmentSource::MANUAL, $assignment->getSource());
        self::assertNull($assignment->getId(), 'persisted, not flushed: the caller owns the statement order');
    }

    public function testADutyOfAnotherPeriodIsRefused(): void
    {
        self::bootKernel();
        $team = $this->createTeam($this->em());
        // Two periods of the same team sharing one FairnessPeriod (a team's FairnessPeriods never overlap, D049).
        $fairness = new FairnessPeriod($team, '2027', $this->date('2027-01-01'), $this->date('2028-01-01'));
        $this->em()->persist($fairness);
        $periodA = new PlanningPeriod($team, $fairness, 'Q1', $this->date('2027-01-01'), $this->date('2027-05-01'));
        $periodB = new PlanningPeriod($team, $fairness, 'Q3', $this->date('2027-06-01'), $this->date('2027-09-01'));
        $this->em()->persist($periodA);
        $this->em()->persist($periodB);
        $this->em()->flush();
        $this->activateRuleSet(static::getContainer()->get(PlanningRuleSetService::class), $team);
        $member = $this->addMember(static::getContainer()->get(PlanningTeamMembershipService::class), $team, $this->createUser($this->em()), TeamMemberRole::OWNER);
        $dutyOfB = $this->createDuty(static::getContainer()->get(DutyMaterializationService::class), $periodB, $this->createDutyType($this->em(), $team), '2027-07-01 08:00', '2027-07-01 20:00');
        [$generationOfA, $snapshot] = $this->snapshotted($periodA);

        $this->expectException(InvalidDutyAssignmentException::class);
        $this->service()->createManualBatchItem($generationOfA, $snapshot, $dutyOfB, $member);
    }

    public function testAMemberOfAnotherTeamIsRefused(): void
    {
        self::bootKernel();
        $teamA = $this->createTeam($this->em(), 'Cardiology');
        $teamB = $this->createTeam($this->em(), 'Radiology');
        $period = $this->createPlanningPeriod($this->em(), $teamA, '2027-01-01', '2027-05-01');
        $this->activateRuleSet(static::getContainer()->get(PlanningRuleSetService::class), $teamA);
        $membership = static::getContainer()->get(PlanningTeamMembershipService::class);
        $this->addMember($membership, $teamA, $this->createUser($this->em()), TeamMemberRole::OWNER);
        $memberOfB = $this->addMember($membership, $teamB, $this->createUser($this->em()), TeamMemberRole::MEMBER);
        $duty = $this->createDuty(static::getContainer()->get(DutyMaterializationService::class), $period, $this->createDutyType($this->em(), $teamA), '2027-02-01 08:00', '2027-02-01 20:00');
        [$generation, $snapshot] = $this->snapshotted($period);

        $this->expectException(InvalidDutyAssignmentException::class);
        $this->service()->createManualBatchItem($generation, $snapshot, $duty, $memberOfB);
    }

    public function testAMemberWhoJoinedAfterTheSnapshotIsRefused(): void
    {
        self::bootKernel();
        $team = $this->createTeam($this->em());
        $period = $this->createPlanningPeriod($this->em(), $team, '2027-01-01', '2027-05-01');
        $this->activateRuleSet(static::getContainer()->get(PlanningRuleSetService::class), $team);
        $membership = static::getContainer()->get(PlanningTeamMembershipService::class);
        $this->addMember($membership, $team, $this->createUser($this->em()), TeamMemberRole::OWNER);
        $duty = $this->createDuty(static::getContainer()->get(DutyMaterializationService::class), $period, $this->createDutyType($this->em(), $team), '2027-02-01 08:00', '2027-02-01 20:00');
        [$generation, $snapshot] = $this->snapshotted($period);
        $latecomer = $this->addMember($membership, $team, $this->createUser($this->em()), TeamMemberRole::MEMBER);

        $this->expectException(InvalidDutyAssignmentException::class);
        $this->service()->createManualBatchItem($generation, $snapshot, $duty, $latecomer);
    }

    public function testTwoCurrentRowsForOneDutyOfAGenerationNeverReachTheDatabase(): void
    {
        self::bootKernel();
        $team = $this->createTeam($this->em());
        $period = $this->createPlanningPeriod($this->em(), $team, '2027-01-01', '2027-05-01');
        $this->activateRuleSet(static::getContainer()->get(PlanningRuleSetService::class), $team);
        $member = $this->addMember(static::getContainer()->get(PlanningTeamMembershipService::class), $team, $this->createUser($this->em()), TeamMemberRole::OWNER);
        $duty = $this->createDuty(static::getContainer()->get(DutyMaterializationService::class), $period, $this->createDutyType($this->em(), $team), '2027-02-01 08:00', '2027-02-01 20:00');
        /** @var PlanningSnapshot $snapshot */
        [$generation, $snapshot] = $this->snapshotted($period);
        $this->service()->createManualBatchItem($generation, $snapshot, $duty, $member);
        $this->em()->flush();

        $this->service()->createManualBatchItem($generation, $snapshot, $duty, $member);
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em()->flush();
    }
}
