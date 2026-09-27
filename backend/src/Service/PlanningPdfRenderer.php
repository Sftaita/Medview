<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\PlanningLine;
use App\Entity\PlanningPublication;
use App\Entity\PlanningTeamMember;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningPublicationEntryRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * The PDF of a published planning (docs/decisions.md D143): the whole
 * period, one row per date grouped by week, one column per line, the
 * person on duty in each cell and every atomic block visibly marked as one
 * unit.
 *
 * Built exclusively from a PlanningPublication's frozen entries — exactly
 * what was diffused, never an old solver output and never the live
 * calendar (which may already hold unpublished changes). The same bytes
 * are attached to the first-publication email and served by "Télécharger
 * le PDF".
 */
final class PlanningPdfRenderer
{
    public function __construct(
        private readonly PlanningPublicationEntryRepository $entryRepository,
        private readonly PlanningLineRepository $lineRepository,
        private readonly Environment $twig,
    ) {
    }

    public function render(PlanningPublication $publication): string
    {
        $options = new Options();
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->twig->render('pdf/planning.html.twig', $this->view($publication)));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    public function filename(PlanningPublication $publication): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', $publication->getPlanning()->getName()))), '-');

        return 'planning-'.('' !== $slug ? $slug : 'medvue').'.pdf';
    }

    /**
     * The template's whole input — public so tests can check the content
     * itself, not only that some PDF bytes came out.
     *
     * @return array{planningName: string, periodLabel: string, publishedAtLabel: string, lines: list<array{name: string}>, weeks: list<array{label: string, days: list<array{label: string, weekend: bool, cells: list<list<array{name: ?string, dutyType: ?string, block: ?string, blockPart: ?string}>>}>}>}
     */
    public function view(PlanningPublication $publication): array
    {
        $planning = $publication->getPlanning();
        $entries = $this->entryRepository->findByPublication($publication);

        /** @var array<int, PlanningLine> $lineByPeriodId */
        $lineByPeriodId = [];
        /** @var array<string, array<int, list<array{duty: Duty, member: ?PlanningTeamMember}>>> $byDateAndLine */
        $byDateAndLine = [];
        foreach ($entries as $entry) {
            $duty = $entry->getDuty();
            $periodId = (int) $duty->getPlanningPeriod()->getId();
            $lineByPeriodId[$periodId] ??= $this->lineRepository->findOneByPlanningPeriod($duty->getPlanningPeriod());
            $line = $lineByPeriodId[$periodId];
            if (null === $line) {
                continue;
            }
            $byDateAndLine[$duty->getLocalDate()->format('Y-m-d')][(int) $line->getId()][] = ['duty' => $duty, 'member' => $entry->getTeamMember()];
        }

        $lines = array_values(array_filter($lineByPeriodId));
        usort($lines, static fn (PlanningLine $a, PlanningLine $b): int => $a->getPosition() <=> $b->getPosition());

        $tz = new \DateTimeZone($planning->getTimezone());
        $first = new \DateTimeImmutable($planning->getStartsAt()->format('Y-m-d'), $tz);
        $last = (new \DateTimeImmutable($planning->getEndsAt()->format('Y-m-d'), $tz))->modify('-1 day');

        $weeks = [];
        $currentWeek = null;
        for ($date = $first; $date <= $last; $date = $date->modify('+1 day')) {
            $weekKey = $date->format('o-W');
            if (null === $currentWeek || $currentWeek['key'] !== $weekKey) {
                if (null !== $currentWeek) {
                    $weeks[] = $currentWeek;
                }
                $monday = $date->modify('monday this week');
                $currentWeek = ['key' => $weekKey, 'label' => 'Semaine du '.FrenchDate::long($monday), 'days' => []];
            }

            $cells = [];
            foreach ($lines as $line) {
                $items = $byDateAndLine[$date->format('Y-m-d')][(int) $line->getId()] ?? [];
                $showType = \count($items) > 1;
                $cells[] = array_map(fn (array $item): array => $this->cellItem($item['duty'], $item['member'], $showType), $items);
            }

            $currentWeek['days'][] = [
                'label' => FrenchDate::short($date),
                'weekend' => (int) $date->format('N') >= 6,
                'cells' => $cells,
            ];
        }
        if (null !== $currentWeek) {
            $weeks[] = $currentWeek;
        }

        return [
            'planningName' => $planning->getName(),
            'periodLabel' => 'Du '.FrenchDate::long($first).' au '.FrenchDate::long($last),
            'publishedAtLabel' => FrenchDate::long($publication->getPublishedAt()->setTimezone($tz)).' à '.$publication->getPublishedAt()->setTimezone($tz)->format('H:i'),
            'lines' => array_map(static fn (PlanningLine $line): array => ['name' => $line->getName()], $lines),
            'weeks' => array_map(static fn (array $week): array => ['label' => $week['label'], 'days' => $week['days']], $weeks),
        ];
    }

    /**
     * @return array{name: ?string, dutyType: ?string, block: ?string, blockPart: ?string}
     */
    private function cellItem(Duty $duty, ?PlanningTeamMember $member, bool $showType): array
    {
        $group = $duty->getGroupInstance();
        $blockPart = null;
        $blockLabel = null;
        if (null !== $group) {
            $dates = array_map(static fn (Duty $d): string => $d->getLocalDate()->format('Y-m-d'), $group->getDuties()->toArray());
            sort($dates);
            $own = $duty->getLocalDate()->format('Y-m-d');
            $blockPart = match (true) {
                1 === \count($dates) => 'single',
                $own === $dates[0] => 'first',
                $own === $dates[\count($dates) - 1] => 'last',
                default => 'middle',
            };
            $blockLabel = $group->getPattern()->getName();
        }

        return [
            'name' => null !== $member ? $member->getUser()->getFirstName().' '.$member->getUser()->getLastName() : null,
            'dutyType' => $showType ? $duty->getDutyType()->getName() : null,
            'block' => $blockLabel,
            'blockPart' => $blockPart,
        ];
    }
}
