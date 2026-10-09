<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PlanningAbsenceExportData;
use App\Service\PlanningAbsenceExportMember;
use App\Service\PlanningAbsenceExportPdfRenderer;
use App\Service\PlanningAbsenceExportService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The absence PDF (docs/decisions.md D181) from a hand-built
 * PlanningAbsenceExportData: what the template receives, French labels,
 * and the real PDF's pagination.
 */
final class PlanningAbsenceExportRendererTest extends KernelTestCase
{
    private const LONG_NAME_FIRST = 'Marie-Élisabeth';
    private const LONG_NAME_LAST = 'de La Rochefoucauld-Montmorency';

    public function testFrenchPeriodLabels(): void
    {
        self::assertSame('15 janvier', PlanningAbsenceExportPdfRenderer::periodLabel('2027-01-15', '2027-01-15', false));
        self::assertSame('15–18 janvier', PlanningAbsenceExportPdfRenderer::periodLabel('2027-01-15', '2027-01-18', false));
        self::assertSame('1er–3 février', PlanningAbsenceExportPdfRenderer::periodLabel('2027-02-01', '2027-02-03', false));
        self::assertSame('28 janvier – 3 février', PlanningAbsenceExportPdfRenderer::periodLabel('2027-01-28', '2027-02-03', false));
        self::assertSame('15–18 janvier 2027', PlanningAbsenceExportPdfRenderer::periodLabel('2027-01-15', '2027-01-18', true));
        self::assertSame('28 décembre 2026 – 3 janvier 2027', PlanningAbsenceExportPdfRenderer::periodLabel('2026-12-28', '2027-01-03', false), 'Across two years, both years are always given.');
        self::assertSame('0 jour', PlanningAbsenceExportPdfRenderer::dayCount(0));
        self::assertSame('1 jour', PlanningAbsenceExportPdfRenderer::dayCount(1));
        self::assertSame('9 jours', PlanningAbsenceExportPdfRenderer::dayCount(9));
    }

    public function testTheCalendarShowsEveryoneAbsentEachDayOnlyInsideThePeriod(): void
    {
        $view = $this->renderer()->view($this->data());

        self::assertSame(['Décembre 2026', 'Janvier 2027'], array_column($view['months'], 'label'));
        self::assertSame('Du 20 décembre 2026 au 10 janvier 2027', $view['periodLabel']);
        self::assertSame('Gardes Orthopédie', $view['title']);

        // December: only the weeks holding days of the period (14-20 Dec, 21-27, 28 Dec-3 Jan).
        $december = $view['months'][0];
        self::assertCount(3, $december['weeks']);
        $firstWeek = $december['weeks'][0];
        self::assertSame('14', $firstWeek[0]['number']);
        self::assertFalse($firstWeek[0]['inRange'], 'Before the planning: disabled, never active.');
        self::assertSame([], $firstWeek[0]['absent']);
        self::assertTrue($firstWeek[6]['inRange'], 'Sunday 20 December is the first day.');
        self::assertTrue($firstWeek[6]['weekend']);
        self::assertSame('décembre', $firstWeek[0]['monthTag']);

        // 30 December: three people absent at once — all listed, in the document order.
        $thirtieth = $december['weeks'][2][2];
        self::assertSame('30', $thirtieth['number']);
        self::assertSame(['Alice Martin', 'Jean Dupont', self::LONG_NAME_FIRST.' '.self::LONG_NAME_LAST], array_column($thirtieth['absent'], 'name'));
        // The last December week: 1-3 January belong to the next month block.
        self::assertNull($december['weeks'][2][4]['number']);
        self::assertSame([], $december['weeks'][2][4]['absent']);

        $january = $view['months'][1];
        self::assertSame('1', $january['weeks'][0][4]['number']);
        self::assertSame('janvier', $january['weeks'][0][4]['monthTag'], 'The 1st carries its month.');
        self::assertSame(['Alice Martin', 'Jean Dupont'], array_column($january['weeks'][0][4]['absent'], 'name'));
        $lastWeek = $january['weeks'][\count($january['weeks']) - 1];
        self::assertSame('10', $lastWeek[6]['number']);
        self::assertTrue($lastWeek[6]['inRange'], 'Sunday 10 January is the last day.');
        self::assertCount(2, $january['weeks'], 'No week after the period: 11 January onwards is not exported.');

        // One colour per person, the same in the calendar, the legend and the summary.
        $colours = array_column($view['members'], 'color', 'name');
        foreach ($thirtieth['absent'] as $person) {
            self::assertSame($colours[$person['name']], $person['color']);
        }
        self::assertSame(['Alice Martin', 'Jean Dupont', self::LONG_NAME_FIRST.' '.self::LONG_NAME_LAST], array_column($december['legend'], 'name'));
        self::assertSame('2 jours', $december['legend'][0]['daysLabel']);
        self::assertSame($colours['Alice Martin'], $december['legend'][0]['color']);
        self::assertCount(4, array_unique(array_values($colours)), 'One distinct colour per person while there are enough.');
    }

    public function testTheSummaryGroupsEachPersonsPeriods(): void
    {
        $members = $this->renderer()->view($this->data())['members'];

        self::assertSame(['Alice Martin', 'Jean Dupont', self::LONG_NAME_FIRST.' '.self::LONG_NAME_LAST, 'Pierre Zola'], array_column($members, 'name'));
        self::assertSame(['30 décembre 2026 – 2 janvier 2027'], $members[0]['periods'], 'The planning spans two years: the year is given.');
        self::assertSame('4 jours', $members[0]['totalLabel']);
        self::assertSame(['20–21 décembre 2026', '30 décembre 2026 – 1er janvier 2027'], $members[1]['periods']);
        self::assertSame('5 jours', $members[1]['totalLabel']);
        self::assertSame('Orthopédie, Urgences', $members[1]['lines']);
        self::assertSame([], $members[3]['periods']);
        self::assertSame('0 jour', $members[3]['totalLabel']);
        self::assertNull($members[0]['membershipLabel'], 'Member over the whole period: nothing to add.');
        self::assertSame('Membre 28 décembre 2026 – 10 janvier 2027', $members[2]['membershipLabel']);
    }

    public function testABusyMonthUsesDenseCellsAndColoursRepeatWithoutLosingNames(): void
    {
        $members = [];
        for ($i = 0; $i < 14; ++$i) {
            $members[] = new PlanningAbsenceExportMember('id-'.$i, 'Prénom'.$i, \sprintf('Nom%02d', $i), ['Ortho'], ['2027-01-12'], [['2027-01-01', '2027-01-31']]);
        }
        $view = $this->renderer()->view(new PlanningAbsenceExportData('Plein', '2027-01-01', '2027-01-31', new \DateTimeImmutable('2026-12-10 09:00'), $members));

        $day = $view['months'][0]['weeks'][2][1];
        self::assertSame('12', $day['number']);
        self::assertCount(14, $day['absent'], 'Fourteen people absent the same day: fourteen names.');
        self::assertTrue($view['months'][0]['dense']);
        self::assertSame($view['members'][0]['color'], $view['members'][12]['color'], 'Twelve colours, then they repeat — the name tells people apart.');
    }

    public function testTheRealPdfHasOnePagePerMonthThenTheSummaryOverAsManyPagesAsNeeded(): void
    {
        $pdf = $this->renderer()->render($this->data());
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame(3, $this->pageCount($pdf), 'December, January, the summary.');

        $members = [];
        for ($i = 0; $i < 90; ++$i) {
            $days = 0 === $i % 3 ? [] : ['2027-01-0'.(1 + $i % 9)];
            $members[] = new PlanningAbsenceExportMember('id-'.$i, 'Prénom'.$i, \sprintf('Nom%02d', $i), ['Orthopédie'], $days, [['2027-01-01', '2027-01-31']]);
        }
        $pdf = $this->renderer()->render(new PlanningAbsenceExportData('Grand service', '2027-01-01', '2027-01-31', new \DateTimeImmutable('2026-12-10 09:00'), $members));
        self::assertGreaterThan(2, $this->pageCount($pdf), 'Ninety rows do not fit one page: the summary continues.');
    }

    public function testAnEmptyPlanningStillProducesAValidPdf(): void
    {
        $data = new PlanningAbsenceExportData('Vide', '2027-02-01', '2027-02-28', new \DateTimeImmutable('2026-12-10 09:00'), []);
        $view = $this->renderer()->view($data);
        self::assertSame([], $view['months'][0]['legend']);
        self::assertSame([], $view['members']);

        $pdf = $this->renderer()->render($data);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame(2, $this->pageCount($pdf));
    }

    public function testTheFilename(): void
    {
        self::assertSame('MedVue_Absences_Gardes-Orthopedie-Ete_2027-01-15_2027-03-14.pdf', PlanningAbsenceExportService::filename('Gardes Orthopédie / Été', '2027-01-15', '2027-03-14'));
        self::assertSame('MedVue_Absences_Planning_2027-01-15_2027-03-14.pdf', PlanningAbsenceExportService::filename('???', '2027-01-15', '2027-03-14'));
        self::assertSame('MedVue_Absences_a-b-c_2027-01-15_2027-03-14.pdf', PlanningAbsenceExportService::filename('../a\\b"c', '2027-01-15', '2027-03-14'), 'Never a path, never a quote.');
    }

    private function renderer(): PlanningAbsenceExportPdfRenderer
    {
        return static::getContainer()->get(PlanningAbsenceExportPdfRenderer::class);
    }

    /** 20 December 2026 → 10 January 2027. */
    private function data(): PlanningAbsenceExportData
    {
        $whole = [['2026-12-20', '2027-01-10']];

        return new PlanningAbsenceExportData('Gardes Orthopédie', '2026-12-20', '2027-01-10', new \DateTimeImmutable('2026-12-10 09:00', new \DateTimeZone('Europe/Brussels')), [
            new PlanningAbsenceExportMember('a', 'Alice', 'Martin', ['Orthopédie'], ['2026-12-30', '2026-12-31', '2027-01-01', '2027-01-02'], $whole),
            new PlanningAbsenceExportMember('j', 'Jean', 'Dupont', ['Orthopédie', 'Urgences'], ['2026-12-20', '2026-12-21', '2026-12-30', '2026-12-31', '2027-01-01'], $whole),
            new PlanningAbsenceExportMember('m', self::LONG_NAME_FIRST, self::LONG_NAME_LAST, ['Orthopédie'], ['2026-12-30'], [['2026-12-28', '2027-01-10']]),
            new PlanningAbsenceExportMember('p', 'Pierre', 'Zola', ['Orthopédie'], [], $whole),
        ]);
    }

    private function pageCount(string $bytes): int
    {
        return (int) preg_match_all('#/Type\s*/Page\b(?!s)#', $bytes);
    }
}
