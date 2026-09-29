<?php

declare(strict_types=1);

namespace App\Tests\Calendar;

use App\Calendar\DutyCalendarEvents;
use PHPUnit\Framework\TestCase;

/**
 * "Mes gardes" units as calendar events (docs/decisions.md D170).
 */
final class DutyCalendarEventsTest extends TestCase
{
    public function testAStandaloneDuty(): void
    {
        [$event] = DutyCalendarEvents::fromDuties([self::duty(['2027-01-05'])], 'https://medvue.be/');

        self::assertSame('k1@medvue', $event->uid);
        self::assertSame('2027-01-05', $event->firstDay->format('Y-m-d'));
        self::assertSame('2027-01-05', $event->lastDay->format('Y-m-d'));
        self::assertSame('Garde Seniors', $event->summary);
        self::assertSame("Planning : Urgences\nLigne : Seniors\nType : Garde\nAgenda en lecture seule : les changements se font dans MedVue.", $event->description);
        self::assertFalse($event->tentative);
        self::assertSame('https://medvue.be/plannings/p1', $event->url, 'No double slash from a trailing one.');
    }

    public function testABlockIsOneEventCarryingItsName(): void
    {
        $events = DutyCalendarEvents::fromDuties([self::duty(['2027-01-09', '2027-01-10'], ['blockName' => 'Week-end'])], 'https://medvue.be');

        self::assertCount(1, $events);
        self::assertSame('2027-01-09', $events[0]->firstDay->format('Y-m-d'));
        self::assertSame('2027-01-10', $events[0]->lastDay->format('Y-m-d'));
        self::assertSame('Garde Seniors · Week-end', $events[0]->summary);
        self::assertStringContainsString("Bloc : Week-end\n", $events[0]->description);
    }

    public function testANonContiguousUnitIsSplitWithDistinctStableUids(): void
    {
        $events = DutyCalendarEvents::fromDuties([self::duty(['2027-01-08', '2027-01-09', '2027-01-11'])], 'https://medvue.be');

        self::assertCount(2, $events);
        self::assertSame(['2027-01-08', '2027-01-09'], [$events[0]->firstDay->format('Y-m-d'), $events[0]->lastDay->format('Y-m-d')]);
        self::assertSame(['2027-01-11', '2027-01-11'], [$events[1]->firstDay->format('Y-m-d'), $events[1]->lastDay->format('Y-m-d')]);
        self::assertSame(['k1@medvue', 'k1-2027-01-11@medvue'], [$events[0]->uid, $events[1]->uid]);
    }

    public function testAMonthBoundaryStaysContiguous(): void
    {
        self::assertCount(1, DutyCalendarEvents::fromDuties([self::duty(['2027-01-31', '2027-02-01'])], 'https://medvue.be'));
    }

    public function testARequiredReinforcementIsConfirmed(): void
    {
        [$event] = DutyCalendarEvents::fromDuties([self::duty(['2027-01-05'], ['conditional' => true, 'coverageState' => 'REQUIRED_ASSIGNED'])], 'https://medvue.be');

        self::assertSame('Renfort Seniors', $event->summary);
        self::assertStringContainsString('Renfort requis.', $event->description);
        self::assertFalse($event->tentative);
    }

    public function testAnUndeterminedReinforcementIsTentative(): void
    {
        [$event] = DutyCalendarEvents::fromDuties([self::duty(['2027-01-05'], ['conditional' => true, 'coverageState' => 'UNDETERMINED'])], 'https://medvue.be');

        self::assertSame('Renfort Seniors (à confirmer)', $event->summary);
        self::assertStringContainsString('Renfort à confirmer', $event->description);
        self::assertTrue($event->tentative);
    }

    public function testANotRequiredReinforcementIsTentative(): void
    {
        [$event] = DutyCalendarEvents::fromDuties([self::duty(['2027-01-05'], ['conditional' => true, 'coverageState' => 'NOT_REQUIRED_ASSIGNED'])], 'https://medvue.be');

        self::assertSame('Renfort Seniors (non requis actuellement)', $event->summary);
        self::assertTrue($event->tentative);
    }

    public function testNoDutiesNoEvents(): void
    {
        self::assertSame([], DutyCalendarEvents::fromDuties([], 'https://medvue.be'));
    }

    /**
     * @param non-empty-list<string> $dates
     * @param array<string, mixed>   $overrides
     *
     * @return array{key: string, planningStableId: string, planningName: string, lineName: string, dutyTypeName: string, blockName: string|null, dates: non-empty-list<string>, conditional: bool, coverageState: string|null}
     */
    private static function duty(array $dates, array $overrides = []): array
    {
        return array_merge([
            'key' => 'k1',
            'planningStableId' => 'p1',
            'planningName' => 'Urgences',
            'lineName' => 'Seniors',
            'dutyTypeName' => 'Garde',
            'blockName' => null,
            'dates' => $dates,
            'conditional' => false,
            'coverageState' => null,
        ], $overrides);
    }
}
