<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Planning;
use App\Entity\PlanningLine;
use App\Entity\PlanningLineType;
use App\Repository\PlanningLineRepository;
use App\Service\PlanningLineOrder;
use App\Service\PlanningLineService;
use App\Service\PlanningService;
use App\Tests\PlanningDomainTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The order the active lines of a Planning are solved in (docs/decisions.md
 * D161): deterministic, explicit — `position` until conditional lines add
 * "sources first".
 */
final class PlanningLineOrderTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;

    /**
     * @return array{0: Planning, 1: PlanningLine, 2: PlanningLine, 3: PlanningLine}
     */
    private function planningWithThreeLines(): array
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $planning = self::getContainer()->get(PlanningService::class)->create('Gardes', $this->createUser($em), $this->date('2027-01-01'), $this->date('2027-05-01'), 'Europe/Brussels', 'Principale');
        $lineService = self::getContainer()->get(PlanningLineService::class);
        $lineService->addLine($planning, 'Renfort A', PlanningLineType::SECONDARY);
        $lineService->addLine($planning, 'Renfort B', PlanningLineType::SECONDARY);

        return [$planning, ...self::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning)];
    }

    public function testActiveLinesAreSolvedInPositionOrderPrimaryFirst(): void
    {
        [$planning, $primary, $a, $b] = $this->planningWithThreeLines();
        $order = self::getContainer()->get(PlanningLineOrder::class);

        self::assertTrue($primary->isPrimary());
        self::assertSame([$primary, $a, $b], $order->activeInResolutionOrder($planning));
        self::assertSame([], $order->precedingActiveLines($primary));
        self::assertSame([$primary], $order->precedingActiveLines($a));
        self::assertSame([$primary, $a], $order->precedingActiveLines($b));
    }

    public function testAnInactiveLineIsNeitherSolvedNorAnyonesPredecessor(): void
    {
        [$planning, $primary, $a, $b] = $this->planningWithThreeLines();
        $order = self::getContainer()->get(PlanningLineOrder::class);

        $a->setActive(false);

        self::assertSame([$primary, $b], $order->activeInResolutionOrder($planning));
        self::assertSame([$primary], $order->precedingActiveLines($b));
        self::assertSame([], $order->precedingActiveLines($a));
    }
}
