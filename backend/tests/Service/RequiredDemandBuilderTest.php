<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\DutyDemandType;
use App\Entity\DutyType;
use App\Fairness\FairnessDimensionKey;
use App\Repository\PlanningLineRepository;
use App\Service\DutyMaterializationService;
use App\Service\PlanningLineService;
use App\Service\PlanningService;
use App\Service\RequiredDemandBuilder;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use App\Tests\PlanningTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RequiredDemandBuilderTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;
    use PlanningTestHelpers;

    public function testOnlyRequiredDutiesCountedPerDimension(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $planningService = self::getContainer()->get(PlanningService::class);
        $lineRepository = self::getContainer()->get(PlanningLineRepository::class);
        $dutyMaterialization = self::getContainer()->get(DutyMaterializationService::class);
        $requiredDemandBuilder = self::getContainer()->get(RequiredDemandBuilder::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();

        $typeAWeighted = new DutyType($team, 'TYPE_A_WEIGHTED', 'Type A', 1.5);
        $em->persist($typeAWeighted);
        $typeB = $this->createDutyType($em, $team, 'TYPE_B');
        $em->flush();

        // Friday, required, weighted type.
        $this->createDuty($dutyMaterialization, $planningPeriod, $typeAWeighted, '2027-03-12 08:00', '2027-03-12 20:00');
        // Saturday, required, weighted type.
        $this->createDuty($dutyMaterialization, $planningPeriod, $typeAWeighted, '2027-03-13 08:00', '2027-03-13 20:00');
        // Sunday, required, other type.
        $this->createDuty($dutyMaterialization, $planningPeriod, $typeB, '2027-03-14 08:00', '2027-03-14 20:00');
        // Monday, OPTIONAL — must never contribute to requiredDemand at all.
        $optionalDuty = $dutyMaterialization->createStandaloneDuty($planningPeriod, $typeAWeighted, $this->date('2027-03-15 08:00'), $this->date('2027-03-15 20:00'), DutyDemandType::OPTIONAL);
        self::assertFalse($optionalDuty->isRequired());

        $requiredDemand = $requiredDemandBuilder->build($planningPeriod);

        self::assertSame(3.0, $requiredDemand->get(FairnessDimensionKey::totalDuties()), 'the OPTIONAL Monday duty must never be counted');
        self::assertSame(4.0, $requiredDemand->get(FairnessDimensionKey::weightedWorkload()), '1.5 (Fri) + 1.5 (Sat) + 1.0 (Sun) — the OPTIONAL 1.5 must never be counted');
        self::assertSame(1.0, $requiredDemand->get(FairnessDimensionKey::friday()));
        self::assertSame(1.0, $requiredDemand->get(FairnessDimensionKey::saturday()));
        self::assertSame(1.0, $requiredDemand->get(FairnessDimensionKey::sunday()));
        self::assertSame(2.0, $requiredDemand->get(FairnessDimensionKey::dutyType((string) $typeAWeighted->getStableId())), 'Friday + Saturday required duties of this type — the OPTIONAL Monday one excluded');
        self::assertSame(1.0, $requiredDemand->get(FairnessDimensionKey::dutyType((string) $typeB->getStableId())));
    }

    public function testDutyGroupContributesThroughEachConstituentDutyNeverAsOneUnit(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $planningService = self::getContainer()->get(PlanningService::class);
        $lineRepository = self::getContainer()->get(PlanningLineRepository::class);
        $dutyMaterialization = self::getContainer()->get(DutyMaterializationService::class);
        $requiredDemandBuilder = self::getContainer()->get(RequiredDemandBuilder::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $line = $lineRepository->findByPlanning($planning)[0];
        $team = $line->getPlanningTeam();
        $planningPeriod = $line->getPlanningPeriod();

        // Friday (offset 0) + Saturday (offset 1), both REQUIRED by default.
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

        $requiredDemand = $requiredDemandBuilder->build($planningPeriod);

        self::assertSame(2.0, $requiredDemand->get(FairnessDimensionKey::totalDuties()), 'a 2-Duty group must count as 2, never 1 — the group is atomic for assignment, not for analytics');
        self::assertSame(1.0, $requiredDemand->get(FairnessDimensionKey::friday()));
        self::assertSame(1.0, $requiredDemand->get(FairnessDimensionKey::saturday()));
    }

    public function testRequiredDemandNeverAggregatesAcrossPlanningLines(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $planningService = self::getContainer()->get(PlanningService::class);
        $lineService = self::getContainer()->get(PlanningLineService::class);
        $lineRepository = self::getContainer()->get(PlanningLineRepository::class);
        $dutyMaterialization = self::getContainer()->get(DutyMaterializationService::class);
        $requiredDemandBuilder = self::getContainer()->get(RequiredDemandBuilder::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator, 'Seniors');
        $lineA = $lineRepository->findByPlanning($planning)[0];
        $lineB = $this->addLine($lineService, $planning, 'Assistants');

        $typeA = $this->createDutyType($em, $lineA->getPlanningTeam(), 'TYPE_A');
        $typeB = $this->createDutyType($em, $lineB->getPlanningTeam(), 'TYPE_B');

        $this->createDuty($dutyMaterialization, $lineA->getPlanningPeriod(), $typeA, '2027-03-12 08:00', '2027-03-12 20:00');
        $this->createDuty($dutyMaterialization, $lineB->getPlanningPeriod(), $typeB, '2027-03-12 08:00', '2027-03-12 20:00');
        $this->createDuty($dutyMaterialization, $lineB->getPlanningPeriod(), $typeB, '2027-03-13 08:00', '2027-03-13 20:00');

        $demandA = $requiredDemandBuilder->build($lineA->getPlanningPeriod());
        $demandB = $requiredDemandBuilder->build($lineB->getPlanningPeriod());

        self::assertSame(1.0, $demandA->get(FairnessDimensionKey::totalDuties()), 'line A must never see line B\'s duties');
        self::assertSame(2.0, $demandB->get(FairnessDimensionKey::totalDuties()));
    }
}
