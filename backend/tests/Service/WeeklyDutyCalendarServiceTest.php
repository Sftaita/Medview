<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\WeekStructureBlockInput;
use App\Dto\WeekStructureUpdateRequest;
use App\Entity\PlanningLine;
use App\Repository\DutyRepository;
use App\Repository\PlanningLineRepository;
use App\Service\DutyMaterializationService;
use App\Service\PlanningLineService;
use App\Service\PlanningService;
use App\Service\WeeklyDutyCalendarService;
use App\Service\WeekStructureService;
use App\Tests\PlanningDomainTestHelpers;
use App\Tests\PlanningGenerationTestHelpers;
use App\Tests\PlanningTestHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * docs/decisions.md D136 — the weekly-structure-driven materialization
 * pipeline that never existed in production before this lot.
 */
final class WeeklyDutyCalendarServiceTest extends KernelTestCase
{
    use PlanningDomainTestHelpers;
    use PlanningGenerationTestHelpers;
    use PlanningTestHelpers;

    private function newLine(string $startsAt = '2027-01-04', string $endsAt = '2027-02-01'): PlanningLine
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $creator = $this->createUser($em);
        $planning = $this->createPlanning(
            self::getContainer()->get(PlanningService::class),
            $creator,
            startsAt: $startsAt,
            endsAt: $endsAt,
        );

        return self::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning)[0];
    }

    /**
     * Scenario A (docs/decisions.md D136): L/Ma/Me/Je solo, V/S/D block —
     * every week materializes 4 solo Duties + one 3-Duty atomic block, the
     * block always the same DutyGroupInstance for its three days.
     */
    public function testScenarioAFourSoloWeekdaysAndAWeekendBlock(): void
    {
        self::bootKernel();
        // 2027-01-04 is a Monday.
        $line = $this->newLine('2027-01-04', '2027-01-18'); // exactly 2 full weeks
        self::getContainer()->get(WeekStructureService::class)->replace($line, new WeekStructureUpdateRequest(
            [new WeekStructureBlockInput('Week-end', ['VEN', 'SAM', 'DIM'], 'Week-end')],
            ['LUN', 'MAR', 'MER', 'JEU'],
            'Semaine',
            [],
        ));

        $period = $line->getPlanningPeriod();
        self::getContainer()->get(WeeklyDutyCalendarService::class)->ensureMaterialized($period, $period->getEndsAt());

        $duties = self::getContainer()->get(DutyRepository::class)->findByPlanningPeriod($period);
        // 2 weeks × 7 duty-days = 14.
        self::assertCount(14, $duties);

        $groupInstanceIds = array_unique(array_filter(array_map(static fn ($d) => $d->getGroupInstance()?->getId(), $duties)));
        // 2 weeks × 1 block = 2 distinct DutyGroupInstances, each with 3 Duties.
        self::assertCount(2, $groupInstanceIds);
        foreach ($groupInstanceIds as $groupId) {
            $inGroup = array_filter($duties, static fn ($d) => $d->getGroupInstance()?->getId() === $groupId);
            self::assertCount(3, $inGroup);
        }
    }

    /**
     * Scenario B: V+D block (non-contiguous), Samedi solo — no artificial
     * Saturday Duty is ever created as part of the block.
     */
    public function testScenarioBNonContiguousBlockNeverCreatesAnArtificialSaturdayDuty(): void
    {
        self::bootKernel();
        $line = $this->newLine('2027-01-04', '2027-01-11'); // exactly 1 week
        self::getContainer()->get(WeekStructureService::class)->replace($line, new WeekStructureUpdateRequest(
            [new WeekStructureBlockInput('Vendredi + dimanche', ['VEN', 'DIM'], 'Week-end')],
            ['LUN', 'MAR', 'MER', 'JEU', 'SAM'],
            'Semaine',
            [],
        ));

        $period = $line->getPlanningPeriod();
        self::getContainer()->get(WeeklyDutyCalendarService::class)->ensureMaterialized($period, $period->getEndsAt());

        $duties = self::getContainer()->get(DutyRepository::class)->findByPlanningPeriod($period);
        // 5 solo days (Lun, Mar, Mer, Jeu, Sam) + 2 block days (Ven, Dim) = 7.
        self::assertCount(7, $duties);

        $byDate = [];
        foreach ($duties as $duty) {
            $byDate[$duty->getLocalDate()->format('Y-m-d')] = $duty;
        }

        $friday = $byDate['2027-01-08'];
        $sunday = $byDate['2027-01-10'];
        $saturday = $byDate['2027-01-09'];

        self::assertNotNull($friday->getGroupInstance());
        self::assertNotNull($sunday->getGroupInstance());
        self::assertSame($friday->getGroupInstance()->getId(), $sunday->getGroupInstance()->getId());
        // Saturday is its own solo Duty, never part of the Friday+Sunday group.
        self::assertNull($saturday->getGroupInstance());
    }

    /**
     * Scenario C: Dimanche = aucune garde — no Duty, no group, nothing.
     */
    public function testScenarioCNoSundayDutyIsEverMaterializedWhenSundayIsExcluded(): void
    {
        self::bootKernel();
        $line = $this->newLine('2027-01-04', '2027-01-11');
        self::getContainer()->get(WeekStructureService::class)->replace($line, new WeekStructureUpdateRequest(
            [],
            ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM'],
            'Semaine',
            ['DIM'],
        ));

        $period = $line->getPlanningPeriod();
        self::getContainer()->get(WeeklyDutyCalendarService::class)->ensureMaterialized($period, $period->getEndsAt());

        $duties = self::getContainer()->get(DutyRepository::class)->findByPlanningPeriod($period);
        self::assertCount(6, $duties);
        foreach ($duties as $duty) {
            self::assertNotSame('2027-01-10', $duty->getLocalDate()->format('Y-m-d'));
        }
    }

    /**
     * Scenario D: two lines of the same Planning, different structures —
     * materializing one never leaks a Duty into the other.
     */
    public function testScenarioDTwoLinesNeverContaminateEachOthersCalendar(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $creator = $this->createUser($em);
        $planning = $this->createPlanning(self::getContainer()->get(PlanningService::class), $creator, startsAt: '2027-01-04', endsAt: '2027-01-11');
        $primary = self::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning)[0];
        $secondary = $this->addLine(self::getContainer()->get(PlanningLineService::class), $planning, 'Renfort');
        // addLine() spans the whole Planning range (docs/planning.md §5).

        $structureService = self::getContainer()->get(WeekStructureService::class);
        $structureService->replace($primary, new WeekStructureUpdateRequest(
            [new WeekStructureBlockInput('Week-end', ['VEN', 'SAM', 'DIM'], 'Week-end')],
            ['LUN', 'MAR', 'MER', 'JEU'],
            'Semaine',
            [],
        ));
        // Secondary line: no structure configured at all.

        $calendarService = self::getContainer()->get(WeeklyDutyCalendarService::class);
        $calendarService->ensureMaterialized($primary->getPlanningPeriod(), $primary->getPlanningPeriod()->getEndsAt());
        $calendarService->ensureMaterialized($secondary->getPlanningPeriod(), $secondary->getPlanningPeriod()->getEndsAt());

        $dutyRepository = self::getContainer()->get(DutyRepository::class);
        self::assertCount(7, $dutyRepository->findByPlanningPeriod($primary->getPlanningPeriod()));
        // Never guessed a default structure for the unconfigured line.
        self::assertCount(0, $dutyRepository->findByPlanningPeriod($secondary->getPlanningPeriod()));
    }

    /**
     * Critical idempotency audit (docs/decisions.md D136, follow-up lot):
     * `DutyRepository::existsForPeriodAndLocalDate()` is scoped by
     * `PlanningPeriod`, never globally by date — it must never encode "one
     * PlanningPeriod database-wide can have only one Duty per date" (too
     * strong; would break multi-line coverage of the same calendar day,
     * docs/planning.md §Ligne principale + secondaire). Two distinct lines
     * of the *same* Planning have two distinct `PlanningPeriod` rows
     * (D074/1:1), each independently materializing a legitimate Duty for
     * the exact same real-world date — this must never block or merge.
     */
    public function testTwoLinesLegitimatelyProduceADutyOnTheExactSameCalendarDateWithoutBlockingEachOther(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $creator = $this->createUser($em);
        $planning = $this->createPlanning(self::getContainer()->get(PlanningService::class), $creator, startsAt: '2027-01-04', endsAt: '2027-01-11');
        $primary = self::getContainer()->get(PlanningLineRepository::class)->findByPlanning($planning)[0];
        $secondary = $this->addLine(self::getContainer()->get(PlanningLineService::class), $planning, 'Renfort');

        $structureService = self::getContainer()->get(WeekStructureService::class);
        // Both lines configure Monday as a solo day — a real, legitimate
        // "garde principale" + "garde secondaire" on the same 2027-01-04.
        foreach ([$primary, $secondary] as $line) {
            $structureService->replace($line, new WeekStructureUpdateRequest(
                [],
                ['LUN'],
                '',
                ['MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM'],
            ));
        }

        $calendarService = self::getContainer()->get(WeeklyDutyCalendarService::class);
        $calendarService->ensureMaterialized($primary->getPlanningPeriod(), $primary->getPlanningPeriod()->getEndsAt());
        $calendarService->ensureMaterialized($secondary->getPlanningPeriod(), $secondary->getPlanningPeriod()->getEndsAt());

        $dutyRepository = self::getContainer()->get(DutyRepository::class);
        $primaryDuties = $dutyRepository->findByPlanningPeriod($primary->getPlanningPeriod());
        $secondaryDuties = $dutyRepository->findByPlanningPeriod($secondary->getPlanningPeriod());

        self::assertCount(1, $primaryDuties, 'The primary line got its own Monday Duty.');
        self::assertCount(1, $secondaryDuties, 'The secondary line got its own Monday Duty — never blocked by the primary line already having one on the exact same date.');
        self::assertSame('2027-01-04', $primaryDuties[0]->getLocalDate()->format('Y-m-d'));
        self::assertSame('2027-01-04', $secondaryDuties[0]->getLocalDate()->format('Y-m-d'));
        self::assertNotSame($primaryDuties[0]->getStableId(), $secondaryDuties[0]->getStableId(), 'Two real, distinct Duty rows — never merged into one.');
    }

    /**
     * Regression (docs/decisions.md D136): a manually-built, one-off
     * `active = true` `DutyPattern` (e.g. `createTwoDutyGroup()`'s fixture
     * pattern, exactly as many pre-D136 tests already build) must never be
     * picked up and re-anchored onto every Monday of the period —
     * `recurring` defaults to `false` for it, so `ensureMaterialized()`
     * ignores it entirely, however many weeks the period spans.
     */
    public function testAnAdHocOneOffPatternIsNeverTouchedByEnsureMaterialized(): void
    {
        self::bootKernel();
        $line = $this->newLine('2027-01-04', '2027-05-01'); // several months
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $this->createTwoDutyGroup(
            $em,
            self::getContainer()->get(DutyMaterializationService::class),
            $line->getPlanningTeam(),
            $line->getPlanningPeriod(),
            '2027-01-09',
            '2027-01-09',
            '2027-01-10',
            '2027-01-10',
            '2027-01-11',
        );

        $period = $line->getPlanningPeriod();
        self::getContainer()->get(WeeklyDutyCalendarService::class)->ensureMaterialized($period, $period->getEndsAt());

        // Exactly the 2 Duties the fixture itself created — never re-anchored
        // onto every other Monday of a 4-month period.
        self::assertCount(2, self::getContainer()->get(DutyRepository::class)->findByPlanningPeriod($period));
    }

    public function testEnsureMaterializedIsIdempotentAndNeverDuplicatesAWeek(): void
    {
        self::bootKernel();
        $line = $this->newLine('2027-01-04', '2027-01-11');
        self::getContainer()->get(WeekStructureService::class)->replace($line, new WeekStructureUpdateRequest(
            [new WeekStructureBlockInput('Week-end', ['VEN', 'SAM', 'DIM'], 'Week-end')],
            ['LUN', 'MAR', 'MER', 'JEU'],
            'Semaine',
            [],
        ));

        $period = $line->getPlanningPeriod();
        $service = self::getContainer()->get(WeeklyDutyCalendarService::class);
        $service->ensureMaterialized($period, $period->getEndsAt());
        $service->ensureMaterialized($period, $period->getEndsAt());
        $service->ensureMaterialized($period, $period->getEndsAt());

        self::assertCount(7, self::getContainer()->get(DutyRepository::class)->findByPlanningPeriod($period));
    }

    /**
     * Regression (docs/decisions.md D136): a structure change *before any
     * real generation ever ran* must never add a second, conflicting Duty
     * for a calendar day a now-retired pattern already materialized —
     * idempotency is scoped per calendar day, never per pattern.
     */
    public function testChangingTheStructureBeforeAnyGenerationNeverDoubleMaterializesAnAlreadyCoveredDay(): void
    {
        self::bootKernel();
        $line = $this->newLine('2027-01-04', '2027-01-11'); // exactly 1 week
        $structureService = self::getContainer()->get(WeekStructureService::class);
        $calendarService = self::getContainer()->get(WeeklyDutyCalendarService::class);
        $period = $line->getPlanningPeriod();

        $structureService->replace($line, new WeekStructureUpdateRequest(
            [new WeekStructureBlockInput('Week-end', ['VEN', 'SAM', 'DIM'], 'Week-end')],
            ['LUN', 'MAR', 'MER', 'JEU'],
            'Semaine',
            [],
        ));
        $calendarService->ensureMaterialized($period, $period->getEndsAt());
        self::assertCount(7, self::getContainer()->get(DutyRepository::class)->findByPlanningPeriod($period));

        // Structure changed before any real generation ever solved over
        // this period — Lundi (already materialized) stays solo in the new
        // structure too, but must never gain a second Duty for the same day.
        $structureService->replace($line, new WeekStructureUpdateRequest(
            [],
            ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM'],
            'Semaine',
            ['DIM'],
        ));
        $calendarService->ensureMaterialized($period, $period->getEndsAt());

        $duties = self::getContainer()->get(DutyRepository::class)->findByPlanningPeriod($period);
        // Still exactly 7: the 4 already-materialized weekdays are
        // untouched, Ven/Sam were already covered by the old block, and
        // Dim (now excluded) was already the block's own Sunday — no day
        // was ever left unmaterialized, so the new structure adds nothing.
        self::assertCount(7, $duties);
        $byDate = [];
        foreach ($duties as $duty) {
            $byDate[$duty->getLocalDate()->format('Y-m-d')] = $duty;
        }
        self::assertCount(7, $byDate, 'No calendar day was ever duplicated.');
    }
}
