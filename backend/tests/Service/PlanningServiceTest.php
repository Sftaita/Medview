<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PlanningLineType;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRepository;
use App\Repository\PlanningTeamRepository;
use App\Service\PlanningService;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningTestHelpers;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlanningServiceTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;
    use PlanningTestHelpers;

    public function testCreatingAPlanningAlsoCreatesItsPrimaryLineAndItsOwnFreshTeam(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $planningService = self::getContainer()->get(PlanningService::class);
        $lineRepository = self::getContainer()->get(PlanningLineRepository::class);

        $creator = $this->createUser($em);

        $planning = $this->createPlanning($planningService, $creator, 'Première ligne');

        $lines = $lineRepository->findByPlanning($planning);
        self::assertCount(1, $lines);
        self::assertSame(PlanningLineType::PRIMARY, $lines[0]->getType());
        self::assertSame(1, $lines[0]->getPosition());
        self::assertSame('Première ligne', $lines[0]->getPlanningTeam()->getName());
        // docs/decisions.md D079: the line's team is never a pre-existing,
        // client-supplied Team — it is always created inline, bound to
        // this exact Planning.
        self::assertSame($planning, $lines[0]->getPlanningTeam()->getPlanning());
        self::assertSame($planning->getStartsAt(), $lines[0]->getPlanningPeriod()->getStartsAt());
        self::assertSame($planning->getEndsAt(), $lines[0]->getPlanningPeriod()->getEndsAt());
    }

    /**
     * New scenario 10: Planning + its primary PlanningTeam + PlanningLine +
     * FairnessPeriod + PlanningPeriod are created atomically. Under the new
     * model a fresh PlanningTeam can never collide with an existing
     * FairnessPeriod (docs/decisions.md D079 — a client can never reuse an
     * existing team), so the failure this test forces is a genuine
     * database-level one instead: a team name over the `VARCHAR(150)`
     * column limit, which Postgres itself rejects when the PlanningTeam
     * row is inserted, partway through the same transaction that already
     * inserted the Planning row.
     */
    public function testCreationIsAtomicWhenThePrimaryTeamCannotBePersisted(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $planningService = self::getContainer()->get(PlanningService::class);
        $planningRepository = self::getContainer()->get(PlanningRepository::class);
        $teamRepository = self::getContainer()->get(PlanningTeamRepository::class);

        $creator = $this->createUser($em);
        $tooLongName = str_repeat('x', 200);

        $planningCountBefore = \count($planningRepository->findAll());
        $teamCountBefore = \count($teamRepository->findAll());

        $this->expectException(DbalException::class);

        try {
            $this->createPlanning($planningService, $creator, $tooLongName);
        } finally {
            self::assertCount($planningCountBefore, $planningRepository->findAll(), 'a failed primary-team persist must roll back the Planning row too — no orphan Planning with zero lines');
            self::assertCount($teamCountBefore, $teamRepository->findAll());
        }
    }

    public function testRenameOnlyChangesTheName(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $planningService = self::getContainer()->get(PlanningService::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator);
        $startsAt = $planning->getStartsAt();
        $endsAt = $planning->getEndsAt();

        $planningService->rename($planning, 'Nouveau nom');

        self::assertSame('Nouveau nom', $planning->getName());
        self::assertEquals($startsAt, $planning->getStartsAt());
        self::assertEquals($endsAt, $planning->getEndsAt());
    }
}
