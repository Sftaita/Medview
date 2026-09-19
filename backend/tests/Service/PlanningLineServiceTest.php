<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PlanningGeneration;
use App\Entity\PlanningLineType;
use App\Entity\TeamMemberRole;
use App\Exception\PrimaryPlanningLineNotDeletableException;
use App\Repository\PlanningGenerationRepository;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningSnapshotRepository;
use App\Repository\PlanningTeamRepository;
use App\Service\DutyMaterializationService;
use App\Service\EligibilityMatrixBuilder;
use App\Service\PlanningLineService;
use App\Service\PlanningRuleSetService;
use App\Service\PlanningService;
use App\Service\PlanningSnapshotService;
use App\Service\PlanningTeamMembershipService;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use App\Tests\PlanningTestHelpers;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlanningLineServiceTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;
    use PlanningTestHelpers;

    public function testAddingASecondAndThirdLine(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $planningService = self::getContainer()->get(PlanningService::class);
        $lineService = self::getContainer()->get(PlanningLineService::class);
        $lineRepository = self::getContainer()->get(PlanningLineRepository::class);

        $creator = $this->createUser($em);

        $planning = $this->createPlanning($planningService, $creator, 'Garde principale');
        $lineB = $this->addLine($lineService, $planning, 'Deuxième ligne');
        $lineC = $this->addLine($lineService, $planning, 'Renfort');

        $lines = $lineRepository->findByPlanning($planning);
        self::assertCount(3, $lines);
        self::assertSame(PlanningLineType::SECONDARY, $lineB->getType());
        self::assertSame(PlanningLineType::SECONDARY, $lineC->getType());
        self::assertSame(2, $lineB->getPosition());
        self::assertSame(3, $lineC->getPosition());
        // docs/decisions.md D079: each line owns a distinct, freshly-created
        // PlanningTeam — never shared with another line.
        self::assertNotSame($lineB->getPlanningTeam(), $lineC->getPlanningTeam());

        $primaryCount = 0;
        foreach ($lines as $line) {
            if ($line->isPrimary()) {
                ++$primaryCount;
            }
        }
        self::assertSame(1, $primaryCount, 'a Planning must always have exactly one PRIMARY line');
    }

    public function testSecondaryLineCanBeDeletedButPrimaryCannot(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $planningService = self::getContainer()->get(PlanningService::class);
        $lineService = self::getContainer()->get(PlanningLineService::class);
        $lineRepository = self::getContainer()->get(PlanningLineRepository::class);

        $creator = $this->createUser($em);

        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $secondary = $this->addLine($lineService, $planning, 'Assistants');
        $primary = $lineRepository->findByPlanning($planning)[0];
        self::assertTrue($primary->isPrimary());

        $lineService->deleteLine($secondary);
        self::assertCount(1, $lineRepository->findByPlanning($planning));

        $this->expectException(PrimaryPlanningLineNotDeletableException::class);
        $lineService->deleteLine($primary);
    }

    /**
     * New scenario 11: a secondary PlanningTeam + PlanningLine +
     * FairnessPeriod + PlanningPeriod are created atomically by
     * PlanningLineService::addLine(). As in PlanningServiceTest's
     * equivalent scenario 10, a fresh PlanningTeam can never collide with
     * an existing FairnessPeriod under the new model, so the failure
     * forced here is a genuine database-level one: a team/line name over
     * the `VARCHAR(150)` column limit.
     */
    public function testSecondaryLineCreationIsAtomicWhenTheTeamCannotBePersisted(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $planningService = self::getContainer()->get(PlanningService::class);
        $lineService = self::getContainer()->get(PlanningLineService::class);
        $lineRepository = self::getContainer()->get(PlanningLineRepository::class);
        $teamRepository = self::getContainer()->get(PlanningTeamRepository::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Garde principale');

        $lineCountBefore = \count($lineRepository->findByPlanning($planning));
        $teamCountBefore = \count($teamRepository->findAll());
        $tooLongName = str_repeat('x', 200);

        $this->expectException(DbalException::class);

        try {
            $lineService->addLine($planning, $tooLongName, PlanningLineType::SECONDARY);
        } finally {
            self::assertCount($lineCountBefore, $lineRepository->findByPlanning($planning), 'a failed team persist must roll back the PlanningLine too');
            self::assertCount($teamCountBefore, $teamRepository->findAll());
        }
    }

    public function testMemberOfTeamADoesNotAppearAsCandidateForLineB(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $planningService = self::getContainer()->get(PlanningService::class);
        $lineService = self::getContainer()->get(PlanningLineService::class);
        $membershipService = self::getContainer()->get(PlanningTeamMembershipService::class);
        $ruleSetService = self::getContainer()->get(PlanningRuleSetService::class);
        $snapshotService = self::getContainer()->get(PlanningSnapshotService::class);
        $matrixBuilder = self::getContainer()->get(EligibilityMatrixBuilder::class);
        $lineRepository = self::getContainer()->get(PlanningLineRepository::class);

        $creator = $this->createUser($em);

        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $lineB = $this->addLine($lineService, $planning, 'Assistants');
        $primaryLine = $lineRepository->findByPlanning($planning)[0];
        $teamA = $primaryLine->getPlanningTeam();
        $teamB = $lineB->getPlanningTeam();

        $this->activateRuleSet($ruleSetService, $teamA);
        $this->activateRuleSet($ruleSetService, $teamB);

        $userA = $this->createUser($em);
        $userB = $this->createUser($em);
        $this->addMember($membershipService, $teamA, $userA, TeamMemberRole::MEMBER);
        $this->addMember($membershipService, $teamB, $userB, TeamMemberRole::MEMBER);

        $generationA = new PlanningGeneration($primaryLine->getPlanningPeriod());
        $generationB = new PlanningGeneration($lineB->getPlanningPeriod());
        $em->persist($generationA);
        $em->persist($generationB);
        $em->flush();

        $snapshotA = $snapshotService->createSnapshot($generationA);
        $snapshotB = $snapshotService->createSnapshot($generationB);

        $matrixA = $matrixBuilder->build($snapshotA);
        $matrixB = $matrixBuilder->build($snapshotB);

        $candidateIdsA = array_map(static fn ($m) => (string) $m->getSourceUserStableId(), $matrixA->getCandidates());
        $candidateIdsB = array_map(static fn ($m) => (string) $m->getSourceUserStableId(), $matrixB->getCandidates());

        self::assertContains((string) $userA->getStableId(), $candidateIdsA);
        self::assertNotContains((string) $userA->getStableId(), $candidateIdsB, 'a Team A member must never appear as a candidate for line B');
        self::assertContains((string) $userB->getStableId(), $candidateIdsB);
        self::assertNotContains((string) $userB->getStableId(), $candidateIdsA, 'a Team B member must never appear as a candidate for line A');
    }

    public function testAddingASecondLineNeverAffectsThePrimaryLinesExistingGenerationOrEligibility(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $planningService = self::getContainer()->get(PlanningService::class);
        $lineService = self::getContainer()->get(PlanningLineService::class);
        $membershipService = self::getContainer()->get(PlanningTeamMembershipService::class);
        $ruleSetService = self::getContainer()->get(PlanningRuleSetService::class);
        $dutyMaterialization = self::getContainer()->get(DutyMaterializationService::class);
        $snapshotService = self::getContainer()->get(PlanningSnapshotService::class);
        $matrixBuilder = self::getContainer()->get(EligibilityMatrixBuilder::class);
        $lineRepository = self::getContainer()->get(PlanningLineRepository::class);

        $creator = $this->createUser($em);

        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $primaryLine = $lineRepository->findByPlanning($planning)[0];
        $teamA = $primaryLine->getPlanningTeam();

        $this->activateRuleSet($ruleSetService, $teamA);
        $userA = $this->createUser($em);
        $this->addMember($membershipService, $teamA, $userA, TeamMemberRole::MEMBER);

        $dutyType = $this->createDutyType($em, $teamA);
        $this->createDuty($dutyMaterialization, $primaryLine->getPlanningPeriod(), $dutyType, '2027-02-01 08:00', '2027-02-01 20:00');

        $generation = new PlanningGeneration($primaryLine->getPlanningPeriod());
        $em->persist($generation);
        $em->flush();
        $generationStableId = (string) $generation->getStableId();

        $snapshot = $snapshotService->createSnapshot($generation);
        $matrixBefore = $matrixBuilder->build($snapshot);
        $resultBefore = $matrixBefore->get($matrixBefore->getDutyUnits()[0], $snapshot->getMembers()->first());

        // Now add a second line, which creates its own completely
        // different PlanningTeam.
        $this->addLine($lineService, $planning, 'Deuxième ligne');
        $em->clear();

        $reloadedGeneration = self::getContainer()->get(PlanningGenerationRepository::class)->findOneByStableId($generationStableId);
        self::assertNotNull($reloadedGeneration, 'the primary line\'s existing generation must still exist, untouched');
        self::assertSame($generationStableId, (string) $reloadedGeneration->getStableId());

        $reloadedSnapshot = self::getContainer()->get(PlanningSnapshotRepository::class)->findOneByGeneration($reloadedGeneration);
        self::assertCount(1, $reloadedSnapshot->getMembers(), 'the historical snapshot must still contain exactly its one original member');
        self::assertTrue($reloadedSnapshot->getMembers()->first()->getSourceUserStableId()->equals($userA->getStableId()));

        $matrixAfter = $matrixBuilder->build($reloadedSnapshot);
        $resultAfter = $matrixAfter->get($matrixAfter->getDutyUnits()[0], $reloadedSnapshot->getMembers()->first());
        self::assertSame($resultBefore->eligible, $resultAfter->eligible, 'eligibility for the primary line must be identical before and after a second line is added');
        self::assertSame($resultBefore->structuralOpportunity, $resultAfter->structuralOpportunity);
    }
}
