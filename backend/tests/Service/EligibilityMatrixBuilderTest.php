<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Eligibility\SingleDutyUnit;
use App\Entity\PlanningGeneration;
use App\Entity\TeamMemberRole;
use App\Entity\UserAvailabilityType;
use App\Repository\DutyRepository;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningSnapshotRepository;
use App\Service\DutyMaterializationService;
use App\Service\EligibilityMatrixBuilder;
use App\Service\PlanningRuleSetService;
use App\Service\PlanningSnapshotService;
use App\Service\PlanningTeamMembershipService;
use App\Service\TeamMemberNonParticipationService;
use App\Service\UserAvailabilityService;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Round-trip tests exercising the full snapshot pipeline
 * (PlanningSnapshotService) together with EligibilityMatrixBuilder — the
 * scenarios EligibilityServiceTest's direct-construction unit tests cannot
 * cover on their own: snapshot immutability, determinism, and multi-team
 * isolation.
 */
final class EligibilityMatrixBuilderTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;

    public function testEligibilityStaysBasedOnTheSnapshotAfterTheLiveAbsenceChanges(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $membershipService = self::getContainer()->get(PlanningTeamMembershipService::class);
        $ruleSetService = self::getContainer()->get(PlanningRuleSetService::class);
        $dutyMaterialization = self::getContainer()->get(DutyMaterializationService::class);
        $availabilityService = self::getContainer()->get(UserAvailabilityService::class);
        $snapshotService = self::getContainer()->get(PlanningSnapshotService::class);
        $matrixBuilder = self::getContainer()->get(EligibilityMatrixBuilder::class);

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team, '2027-01-01', '2027-05-01');
        $this->activateRuleSet($ruleSetService, $team);
        $user = $this->createUser($em);
        $member = $this->addMember($membershipService, $team, $user);
        $dutyType = $this->createDutyType($em, $team);
        $duty = $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');

        $absence = $this->addAvailability($availabilityService, $user, UserAvailabilityType::UNAVAILABLE, '2027-02-01 08:00', '2027-02-01 20:00');

        $generation = new PlanningGeneration($planningPeriod);
        $em->persist($generation);
        $em->flush();
        $snapshot = $snapshotService->createSnapshot($generation);

        $matrix = $matrixBuilder->build($snapshot);
        $snapshotMember = $snapshot->getMembers()->first();
        $result = $matrix->get(new SingleDutyUnit($duty), $snapshotMember);

        self::assertFalse($result->eligible, 'sanity check: the snapshot must have captured the absence');

        // Delete the live absence entirely.
        $availabilityService->delete($absence);
        $em->clear();

        // Re-fetch everything fresh from the database and rebuild.
        $matrixBuilder2 = self::getContainer()->get(EligibilityMatrixBuilder::class);
        $generationRepository = self::getContainer()->get(PlanningGenerationRepository::class);
        $reloadedGeneration = $generationRepository->findOneByStableId((string) $generation->getStableId());
        $snapshotRepository = self::getContainer()->get(PlanningSnapshotRepository::class);
        $reloadedSnapshot = $snapshotRepository->findOneByGeneration($reloadedGeneration);
        $dutyRepository = self::getContainer()->get(DutyRepository::class);
        $reloadedDuty = $dutyRepository->findOneByStableId((string) $duty->getStableId());

        $matrixAfter = $matrixBuilder2->build($reloadedSnapshot);
        $reloadedSnapshotMember = $reloadedSnapshot->getMembers()->first();
        $resultAfter = $matrixAfter->get(new SingleDutyUnit($reloadedDuty), $reloadedSnapshotMember);

        self::assertFalse($resultAfter->eligible, 'eligibility for this (already snapshotted) generation must still be excluded — the snapshot never changes even though the live absence was deleted');
    }

    public function testMatrixIsDeterministicAcrossRebuilds(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $membershipService = self::getContainer()->get(PlanningTeamMembershipService::class);
        $ruleSetService = self::getContainer()->get(PlanningRuleSetService::class);
        $dutyMaterialization = self::getContainer()->get(DutyMaterializationService::class);
        $availabilityService = self::getContainer()->get(UserAvailabilityService::class);
        $snapshotService = self::getContainer()->get(PlanningSnapshotService::class);
        $matrixBuilder = self::getContainer()->get(EligibilityMatrixBuilder::class);

        $team = $this->createTeam($em);
        $planningPeriod = $this->createPlanningPeriod($em, $team, '2027-01-01', '2027-05-01');
        $this->activateRuleSet($ruleSetService, $team);

        $userA = $this->createUser($em);
        $this->addMember($membershipService, $team, $userA);
        $userB = $this->createUser($em);
        $this->addMember($membershipService, $team, $userB);

        $dutyType = $this->createDutyType($em, $team);
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');
        $this->createDuty($dutyMaterialization, $planningPeriod, $dutyType, '2027-02-02 08:00', '2027-02-02 20:00');
        $this->addAvailability($availabilityService, $userA, UserAvailabilityType::UNAVAILABLE, '2027-02-01 08:00', '2027-02-01 20:00');

        $generation = new PlanningGeneration($planningPeriod);
        $em->persist($generation);
        $em->flush();
        $snapshot = $snapshotService->createSnapshot($generation);

        $matrix1 = $matrixBuilder->build($snapshot);
        $matrix2 = $matrixBuilder->build($snapshot);

        self::assertCount(2, $matrix1->getDutyUnits());
        self::assertCount(2, $matrix1->getCandidates());

        foreach ($matrix1->getDutyUnits() as $dutyUnit) {
            foreach ($matrix1->getCandidates() as $member) {
                $result1 = $matrix1->get($dutyUnit, $member);
                $result2 = $matrix2->get($dutyUnit, $member);

                self::assertSame($result1->eligible, $result2->eligible);
                self::assertSame($result1->structuralOpportunity, $result2->structuralOpportunity);
                self::assertCount(\count($result1->exclusions), $result2->exclusions);
                foreach ($result1->exclusions as $i => $exclusion) {
                    self::assertSame($exclusion->reason, $result2->exclusions[$i]->reason);
                }
            }
        }
    }

    public function testMultiTeamIsolation(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $membershipService = self::getContainer()->get(PlanningTeamMembershipService::class);
        $ruleSetService = self::getContainer()->get(PlanningRuleSetService::class);
        $dutyMaterialization = self::getContainer()->get(DutyMaterializationService::class);
        $availabilityService = self::getContainer()->get(UserAvailabilityService::class);
        $nonParticipationService = self::getContainer()->get(TeamMemberNonParticipationService::class);
        $snapshotService = self::getContainer()->get(PlanningSnapshotService::class);
        $matrixBuilder = self::getContainer()->get(EligibilityMatrixBuilder::class);

        $teamA = $this->createTeam($em, 'Cardiology');
        $teamB = $this->createTeam($em, 'Radiology');
        $planningPeriodA = $this->createPlanningPeriod($em, $teamA, '2027-01-01', '2027-05-01');
        $planningPeriodB = $this->createPlanningPeriod($em, $teamB, '2027-01-01', '2027-05-01');
        $this->activateRuleSet($ruleSetService, $teamA);
        $this->activateRuleSet($ruleSetService, $teamB);

        // Membership is now Planning-scoped (docs/decisions.md D079/D080):
        // since teamA and teamB belong to two different Plannings by
        // default (see PlanningDomainTestHelpers::createTeam()), the same
        // User can hold an open membership in both *simultaneously* — used
        // here to show "personal UNAVAILABLE is global" without any
        // sequential team-switch workaround. Team-scoped non-participation,
        // by contrast, needs two distinct Users to demonstrate isolation
        // meaningfully.
        $user = $this->createUser($em);
        $this->addAvailability($availabilityService, $user, UserAvailabilityType::UNAVAILABLE, '2027-02-01 08:00', '2027-02-01 20:00');
        $this->addMember($membershipService, $teamA, $user, TeamMemberRole::MEMBER, '2027-01-01');
        $this->addMember($membershipService, $teamB, $user, TeamMemberRole::MEMBER, '2027-01-01');

        $otherUser = $this->createUser($em);
        $memberA = $this->addMember($membershipService, $teamA, $otherUser, TeamMemberRole::MEMBER, '2027-01-01');

        $dutyTypeA = $this->createDutyType($em, $teamA, 'ONCALL-A');
        $dutyTypeB = $this->createDutyType($em, $teamB, 'ONCALL-B');
        $dutyA = $this->createDuty($dutyMaterialization, $planningPeriodA, $dutyTypeA, '2027-02-01 08:00', '2027-02-01 20:00');
        $dutyB = $this->createDuty($dutyMaterialization, $planningPeriodB, $dutyTypeB, '2027-02-01 08:00', '2027-02-01 20:00');

        // Administrative non-participation is team-scoped — Team A only,
        // and only for $otherUser (a different member than the one
        // powering Team B's snapshot). Overlaps dutyA (2027-02-01) so the
        // sanity check below actually exercises an exclusion.
        $this->addNonParticipation($nonParticipationService, $memberA, '2027-02-01 00:00', '2027-02-02 00:00');

        $generationA = new PlanningGeneration($planningPeriodA);
        $generationB = new PlanningGeneration($planningPeriodB);
        $em->persist($generationA);
        $em->persist($generationB);
        $em->flush();

        $snapshotA = $snapshotService->createSnapshot($generationA);
        $snapshotB = $snapshotService->createSnapshot($generationB);

        $matrixA = $matrixBuilder->build($snapshotA);
        $matrixB = $matrixBuilder->build($snapshotB);

        $otherUserSnapshotMember = current(array_filter(
            $snapshotA->getMembers()->toArray(),
            static fn ($m) => $m->getSourceUserStableId()->equals($otherUser->getStableId()),
        ));
        $userSnapshotMember = $snapshotB->getMembers()->first();

        self::assertFalse($matrixB->get(new SingleDutyUnit($dutyB), $userSnapshotMember)->eligible, 'personal UNAVAILABLE must exclude in Planning B\'s team too, even though it was declared once and the membership lives in a different Planning');
        self::assertFalse($matrixA->get(new SingleDutyUnit($dutyA), $otherUserSnapshotMember)->eligible, 'sanity check: otherUser must be excluded by their own Team A non-participation');

        self::assertCount(1, $otherUserSnapshotMember->getNonParticipationPeriods(), 'Team A snapshot must capture otherUser\'s Team A non-participation');
        self::assertCount(0, $userSnapshotMember->getNonParticipationPeriods(), 'Team B snapshot must never see Team A\'s administrative non-participation');
    }
}
