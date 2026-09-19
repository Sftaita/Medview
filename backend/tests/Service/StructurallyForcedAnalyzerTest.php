<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Eligibility\EligibilityExclusion;
use App\Eligibility\EligibilityMatrix;
use App\Eligibility\EligibilityResult;
use App\Eligibility\ExclusionReason;
use App\Eligibility\SingleDutyUnit;
use App\Entity\Duty;
use App\Entity\DutyType;
use App\Entity\FairnessPeriod;
use App\Entity\Planning;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningSnapshot;
use App\Entity\PlanningSnapshotMember;
use App\Entity\PlanningTeam;
use App\Entity\TeamMemberRole;
use App\Entity\User;
use App\Fairness\FairnessDimensionKey;
use App\Repository\PlanningLineRepository;
use App\Service\DimensionMembershipCalculator;
use App\Service\DutyMaterializationService;
use App\Service\EligibilityMatrixBuilder;
use App\Service\PlanningRuleSetService;
use App\Service\PlanningService;
use App\Service\PlanningSnapshotService;
use App\Service\PlanningTeamMembershipService;
use App\Service\StructurallyForcedAnalyzer;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use App\Tests\PlanningTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class StructurallyForcedAnalyzerTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;
    use PlanningTestHelpers;

    public function testNoCandidateMeansNoForcedUnit(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $analyzer = $c->get(StructurallyForcedAnalyzer::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        // The lone member joins only after the Duty's date -> MEMBERSHIP_OUT_OF_RANGE (HARD), never eligible.
        $user = $this->createUser($em);
        $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-03-01', 1.0);

        $dutyType = $this->createDutyType($em, $team);
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');

        $generation = new PlanningGeneration($planningPeriod);
        $em->persist($generation);
        $em->flush();
        $snapshot = $snapshotService->createSnapshot($generation);
        $matrix = $matrixBuilder->build($snapshot);

        self::assertSame([], $analyzer->analyze($matrix));
    }

    public function testExactlyOneHardEligibleCandidateIsStructurallyForced(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $analyzer = $c->get(StructurallyForcedAnalyzer::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        $user = $this->createUser($em);
        $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        $dutyType = $this->createDutyType($em, $team);
        $duty = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');

        $generation = new PlanningGeneration($planningPeriod);
        $em->persist($generation);
        $em->flush();
        $snapshot = $snapshotService->createSnapshot($generation);
        $matrix = $matrixBuilder->build($snapshot);

        $forced = $analyzer->analyze($matrix);

        self::assertCount(1, $forced);
        self::assertSame((string) $duty->getStableId(), $forced[0]->dutyUnit->getStableKey());
        self::assertSame((string) $user->getStableId(), $forced[0]->sourceUserStableId);
    }

    public function testTwoHardEligibleCandidatesMeansNoForcedUnit(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $analyzer = $c->get(StructurallyForcedAnalyzer::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        $userA = $this->createUser($em);
        $userB = $this->createUser($em);
        $this->addMember($membershipService, $team, $userA, TeamMemberRole::MEMBER, '2027-01-01', 1.0);
        $this->addMember($membershipService, $team, $userB, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        $dutyType = $this->createDutyType($em, $team);
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');

        $generation = new PlanningGeneration($planningPeriod);
        $em->persist($generation);
        $em->flush();
        $snapshot = $snapshotService->createSnapshot($generation);
        $matrix = $matrixBuilder->build($snapshot);

        self::assertSame([], $analyzer->analyze($matrix));
    }

    public function testForcedDutyGroupContributesThroughAllOfItsDimensionsToForcedLoad(): void
    {
        self::bootKernel();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $planningService = $c->get(PlanningService::class);
        $lineRepository = $c->get(PlanningLineRepository::class);
        $membershipService = $c->get(PlanningTeamMembershipService::class);
        $ruleSetService = $c->get(PlanningRuleSetService::class);
        $dutyMaterialization = $c->get(DutyMaterializationService::class);
        $snapshotService = $c->get(PlanningSnapshotService::class);
        $matrixBuilder = $c->get(EligibilityMatrixBuilder::class);
        $analyzer = $c->get(StructurallyForcedAnalyzer::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();
        $this->activateRuleSet($ruleSetService, $team);

        $user = $this->createUser($em);
        $this->addMember($membershipService, $team, $user, TeamMemberRole::MEMBER, '2027-01-01', 1.0);

        // Friday (offset 0) + Saturday (offset 1).
        $this->createTwoDutyGroup(
            $em,
            $dutyMaterialization,
            $team,
            $planningPeriod,
            '2027-03-12',
            '2027-03-12 08:00',
            '2027-03-12 20:00',
            '2027-03-13 08:00',
            '2027-03-13 20:00',
        );

        $generation = new PlanningGeneration($planningPeriod);
        $em->persist($generation);
        $em->flush();
        $snapshot = $snapshotService->createSnapshot($generation);
        $matrix = $matrixBuilder->build($snapshot);

        $forced = $analyzer->analyze($matrix);
        self::assertCount(1, $forced);

        $forcedLoad = $analyzer->buildForcedLoad($forced);
        $userLoad = $forcedLoad[(string) $user->getStableId()];

        self::assertSame(2.0, $userLoad->get(FairnessDimensionKey::totalDuties()), 'a forced 2-Duty group must contribute 2, never a flat +1');
        self::assertSame(1.0, $userLoad->get(FairnessDimensionKey::friday()));
        self::assertSame(1.0, $userLoad->get(FairnessDimensionKey::saturday()));
    }

    /**
     * A future POLICY_HARD-only exclusion (MAX_DUTIES, TEAM_MIN_REST, ...)
     * must never manufacture a STRUCTURALLY_FORCED that would not exist
     * under HARD constraints alone (docs/allocation-algorithm.md §4.2,
     * docs/fairness.md). EligibilityService does not produce any
     * POLICY_HARD exclusion yet (docs/eligibility.md §3), so this test
     * hand-builds an EligibilityMatrix to exercise the taxonomy directly,
     * independent of what EligibilityService currently computes.
     */
    public function testAPolicyHardExclusionNeverShrinksTheHardEligibleSet(): void
    {
        $planning = new Planning('P', new User('creator@example.com', 'C', 'U', 'h'), new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2028-01-01'), 'Europe/Brussels');
        $team = new PlanningTeam($planning, 'Team');
        $fairnessPeriod = new FairnessPeriod($team, '2027', new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2028-01-01'));
        $planningPeriod = new PlanningPeriod($team, $fairnessPeriod, 'P1', new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-05-01'));
        $dutyType = new DutyType($team, 'DAY', 'Day');
        $duty = new Duty($planningPeriod, $dutyType, new \DateTimeImmutable('2027-02-01 08:00', new \DateTimeZone('Europe/Brussels')), new \DateTimeImmutable('2027-02-01 20:00', new \DateTimeZone('Europe/Brussels')), 'Europe/Brussels');

        $generation = new PlanningGeneration($planningPeriod);
        $snapshot = new PlanningSnapshot($generation);

        $memberA = new PlanningSnapshotMember($snapshot, Uuid::v7(), Uuid::v7(), new \DateTimeImmutable('2027-01-01'), null, TeamMemberRole::MEMBER, true);
        $memberB = new PlanningSnapshotMember($snapshot, Uuid::v7(), Uuid::v7(), new \DateTimeImmutable('2027-01-01'), null, TeamMemberRole::MEMBER, true);

        $unit = new SingleDutyUnit($duty);

        // Both candidates are HARD-eligible; B additionally carries a
        // POLICY_HARD-only exclusion (not yet ever produced by
        // EligibilityService, but the taxonomy must still be respected).
        $resultA = new EligibilityResult([], true);
        $resultB = new EligibilityResult([new EligibilityExclusion(ExclusionReason::MAX_DUTIES)], true);

        $matrix = new EligibilityMatrix(
            [$unit],
            [$memberA, $memberB],
            [
                $unit->getStableKey() => [
                    (string) $memberA->getSourceTeamMemberStableId() => $resultA,
                    (string) $memberB->getSourceTeamMemberStableId() => $resultB,
                ],
            ],
        );

        $analyzer = new StructurallyForcedAnalyzer(new DimensionMembershipCalculator());

        self::assertSame([], $analyzer->analyze($matrix), 'two HARD-eligible candidates (B\'s POLICY_HARD exclusion does not count) must never be reported as forced');
    }
}
