<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\WeekStructureBlockInput;
use App\Dto\WeekStructureUpdateRequest;
use App\Entity\PlanningLine;
use App\Exception\InvalidWeekStructureException;
use App\Repository\AllocationFamilyRepository;
use App\Repository\DutyPatternRepository;
use App\Repository\PlanningLineRepository;
use App\Service\PlanningLineService;
use App\Service\PlanningService;
use App\Service\WeekStructureService;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * docs/decisions.md D136 — persistence side of the weekly structure editor.
 */
final class WeekStructureServiceTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;
    use PlanningTestHelpers;

    private function newLine(EntityManagerInterface $em, PlanningLineService $lineService, PlanningService $planningService): PlanningLine
    {
        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator);

        return self::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning)[0];
    }

    public function testAFreshLineHasNoStructureAtAll(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $line = $this->newLine($em, self::getContainer()->get(PlanningLineService::class), self::getContainer()->get(PlanningService::class));

        $view = self::getContainer()->get(WeekStructureService::class)->read($line);

        self::assertSame([], $view->blocks);
        self::assertSame([], $view->solo);
        self::assertNull($view->soloFamily);
        self::assertSame(['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'], $view->excluded);
    }

    public function testReplaceCreatesBlockAndSoloPatternsWithFamiliesAndReadsThemBack(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $line = $this->newLine($em, self::getContainer()->get(PlanningLineService::class), self::getContainer()->get(PlanningService::class));
        $service = self::getContainer()->get(WeekStructureService::class);

        $request = new WeekStructureUpdateRequest(
            [new WeekStructureBlockInput('Week-end', ['VEN', 'SAM', 'DIM'], 'Week-end')],
            ['LUN', 'MAR', 'MER', 'JEU'],
            'Semaine',
            [],
        );

        $view = $service->replace($line, $request);

        self::assertCount(1, $view->blocks);
        self::assertSame('Week-end', $view->blocks[0]->name);
        self::assertSame(['VEN', 'SAM', 'DIM'], $view->blocks[0]->days);
        self::assertSame('Week-end', $view->blocks[0]->family);
        self::assertSame(['LUN', 'MAR', 'MER', 'JEU'], $view->solo);
        self::assertSame('Semaine', $view->soloFamily);
        self::assertSame([], $view->excluded);

        // Independently re-read: the view is derived purely from persisted
        // active patterns, never a cached/second representation.
        $reread = $service->read($line);
        self::assertEquals($view->blocks[0]->days, $reread->blocks[0]->days);
    }

    public function testExcludedDayNeverMaterializesAnything(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $line = $this->newLine($em, self::getContainer()->get(PlanningLineService::class), self::getContainer()->get(PlanningService::class));
        $service = self::getContainer()->get(WeekStructureService::class);

        $view = $service->replace($line, new WeekStructureUpdateRequest(
            [],
            ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM'],
            '',
            ['DIM'],
        ));

        self::assertSame(['DIM'], $view->excluded);
    }

    public function testRejectsADayClaimedByTwoEntries(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $line = $this->newLine($em, self::getContainer()->get(PlanningLineService::class), self::getContainer()->get(PlanningService::class));
        $service = self::getContainer()->get(WeekStructureService::class);

        $this->expectException(InvalidWeekStructureException::class);
        $service->replace($line, new WeekStructureUpdateRequest(
            [new WeekStructureBlockInput('Week-end', ['VEN', 'SAM', 'DIM'], '')],
            ['LUN', 'MAR', 'MER', 'JEU', 'DIM'], // DIM also solo — claimed twice
            '',
            [],
        ));
    }

    public function testRejectsABlockOfASingleDay(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $line = $this->newLine($em, self::getContainer()->get(PlanningLineService::class), self::getContainer()->get(PlanningService::class));
        $service = self::getContainer()->get(WeekStructureService::class);

        $this->expectException(InvalidWeekStructureException::class);
        $service->replace($line, new WeekStructureUpdateRequest(
            [new WeekStructureBlockInput('Solo', ['VEN'], '')],
            ['LUN', 'MAR', 'MER', 'JEU', 'SAM', 'DIM'],
            '',
            [],
        ));
    }

    public function testRejectsAnIncompleteStructureMissingADay(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $line = $this->newLine($em, self::getContainer()->get(PlanningLineService::class), self::getContainer()->get(PlanningService::class));
        $service = self::getContainer()->get(WeekStructureService::class);

        $this->expectException(InvalidWeekStructureException::class);
        $service->replace($line, new WeekStructureUpdateRequest(
            [],
            ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM'], // DIM unclassified
            '',
            [],
        ));
    }

    public function testAnInvalidReplaceNeverTouchesThePreviousStructure(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $line = $this->newLine($em, self::getContainer()->get(PlanningLineService::class), self::getContainer()->get(PlanningService::class));
        $service = self::getContainer()->get(WeekStructureService::class);

        $service->replace($line, new WeekStructureUpdateRequest(
            [new WeekStructureBlockInput('Week-end', ['VEN', 'SAM', 'DIM'], 'Week-end')],
            ['LUN', 'MAR', 'MER', 'JEU'],
            'Semaine',
            [],
        ));

        try {
            $service->replace($line, new WeekStructureUpdateRequest(
                [new WeekStructureBlockInput('X', ['VEN'], '')], // invalid: 1-day block
                ['LUN', 'MAR', 'MER', 'JEU', 'SAM', 'DIM'],
                '',
                [],
            ));
            self::fail('Expected InvalidWeekStructureException.');
        } catch (InvalidWeekStructureException) {
            // expected
        }

        $view = $service->read($line);
        self::assertCount(1, $view->blocks);
        self::assertSame('Week-end', $view->blocks[0]->name);
    }

    public function testReplaceNeverMutatesOrDeletesThePreviousPatternsOnlyDeactivatesThem(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $line = $this->newLine($em, self::getContainer()->get(PlanningLineService::class), self::getContainer()->get(PlanningService::class));
        $service = self::getContainer()->get(WeekStructureService::class);
        $patternRepository = self::getContainer()->get(DutyPatternRepository::class);

        $service->replace($line, new WeekStructureUpdateRequest(
            [new WeekStructureBlockInput('Week-end', ['VEN', 'SAM', 'DIM'], 'Week-end')],
            ['LUN', 'MAR', 'MER', 'JEU'],
            'Semaine',
            [],
        ));
        $firstPatternIds = array_map(static fn ($p) => $p->getId(), $patternRepository->findBy(['team' => $line->getPlanningTeam()]));

        $service->replace($line, new WeekStructureUpdateRequest(
            [],
            ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM'],
            'Semaine',
            ['DIM'],
        ));

        // Every pattern from the first replace() still exists in the
        // database — deactivated, never deleted (docs/planning-domain.md
        // §14) — so any Duty already materialized from it keeps a valid
        // reference forever.
        foreach ($firstPatternIds as $id) {
            $pattern = $patternRepository->find($id);
            self::assertNotNull($pattern, 'A pattern from a previous structure must never be deleted.');
            self::assertFalse($pattern->isActive());
        }
    }

    public function testTwoLinesOfTheSamePlanningNeverShareOrContaminateEachOthersStructure(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $lineService = self::getContainer()->get(PlanningLineService::class);
        $planningService = self::getContainer()->get(PlanningService::class);
        $service = self::getContainer()->get(WeekStructureService::class);

        $creator = $this->createUser($em);
        $planning = $this->createPlanning($planningService, $creator);
        $primary = self::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning)[0];
        $secondary = $this->addLine($lineService, $planning, 'Renfort');

        $service->replace($primary, new WeekStructureUpdateRequest(
            [new WeekStructureBlockInput('Week-end', ['VEN', 'SAM', 'DIM'], 'Week-end')],
            ['LUN', 'MAR', 'MER', 'JEU'],
            'Semaine',
            [],
        ));

        $secondaryView = $service->read($secondary);
        self::assertSame([], $secondaryView->blocks);
        self::assertSame(['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'], $secondaryView->excluded);
    }

    public function testTheSameFamilyLabelIsReusedAcrossSeveralSoloPatternsOfTheSameLine(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $line = $this->newLine($em, self::getContainer()->get(PlanningLineService::class), self::getContainer()->get(PlanningService::class));
        $service = self::getContainer()->get(WeekStructureService::class);

        $service->replace($line, new WeekStructureUpdateRequest(
            [],
            ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'],
            'Semaine',
            [],
        ));

        $families = self::getContainer()->get(AllocationFamilyRepository::class)->findActiveByTeam($line->getPlanningTeam());
        self::assertCount(1, $families, 'Every solo pattern must share the exact same AllocationFamily row, never one per day.');
    }
}
