<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Duty;
use App\Entity\PlanningLine;
use App\Entity\PlanningPublication;
use App\Entity\PlanningPublicationDocument;
use App\Entity\PlanningTeamMember;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningPublicationDocumentRepository;
use App\Repository\PlanningPublicationEntryRepository;
use App\Repository\PlanningPublicationLineRepository;
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
 * calendar (which may already hold unpublished changes). Rendered once,
 * when the publication is recorded, and stored (PlanningPublicationDocument,
 * D173): the same bytes are attached to every email of that publication and
 * served by "Télécharger le PDF" — see pdfOf().
 *
 * Since docs/decisions.md D196 (lot L4c): the period published — its own
 * dates, "Période N" — and exactly the lines published in it by then, read
 * from the publication history (planning_publication_lines), with the names
 * they were published under. A publication without stored PDF (before D173)
 * keeps the rendering it always had, but on the days of its own period —
 * never the planning's, which grow with every following period (D206).
 */
final class PlanningPdfRenderer
{
    public function __construct(
        private readonly PlanningPublicationEntryRepository $entryRepository,
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningPublicationDocumentRepository $documentRepository,
        private readonly PlanningPublicationLineRepository $publicationLineRepository,
        private readonly Environment $twig,
    ) {
    }

    /**
     * The PDF of a publication as it was diffused (docs/decisions.md D173):
     * the bytes stored when it was recorded — never re-rendered from data
     * that may have changed since (period, names). A publication recorded
     * before D173 has no stored PDF: rendered from its frozen entries, on the
     * days of its own period (D206), its lines under their current names.
     */
    public function pdfOf(PlanningPublication $publication): PublishedDocument
    {
        $number = $publication->getCycle()?->getNumber();
        $document = $this->documentRepository->findOneByPublication($publication);
        if (null !== $document) {
            return new PublishedDocument($document->getContent(), $document->getFilename(), $document->getPlanningName(), $document->getPeriodFirstDay(), $document->getPeriodLastDay(), $number);
        }

        [$first, $last] = self::cycleDays($publication);

        return new PublishedDocument($this->renderView($this->legacyView($publication)), $this->legacyFilename($publication), $publication->getPlanning()->getName(), $first, $last, $number);
    }

    /**
     * Renders the publication's PDF now, to be stored with it (D173) —
     * called once, when the publication is recorded, from its entries.
     */
    public function document(PlanningPublication $publication, \DateTimeImmutable $createdAt): PlanningPublicationDocument
    {
        [$first, $last] = self::cycleDays($publication);

        return new PlanningPublicationDocument($publication, $this->render($publication), $this->filename($publication), $publication->getPlanning()->getName(), $first, $last, $createdAt);
    }

    public function render(PlanningPublication $publication): string
    {
        return $this->renderView($this->view($publication));
    }

    /** docs/decisions.md D196: "planning-<slug>-periode-<N>.pdf". */
    public function filename(PlanningPublication $publication): string
    {
        $number = $publication->getCycle()?->getNumber();

        return 'planning-'.$this->slug($publication).(null !== $number ? '-periode-'.$number : '').'.pdf';
    }

    /**
     * The template's whole input — public so tests can check the content
     * itself, not only that some PDF bytes came out.
     *
     * @return array{planningName: string, periodLabel: string, publishedAtLabel: string, lines: list<array{name: string}>, weeks: list<array{label: string, days: list<array{label: string, weekend: bool, cells: list<list<array{name: ?string, dutyType: ?string, block: ?string, blockPart: ?string}>>}>}>}
     */
    public function view(PlanningPublication $publication): array
    {
        $cycle = $publication->getCycle() ?? throw new \LogicException('A publication always belongs to a period (lot L2).');
        [$first, $last] = self::cycleDays($publication);

        // The lines published in the period by then, each under the name it was first published with.
        /** @var array<int, array{name: string, position: int, order: int}> $columns by period id */
        $columns = [];
        foreach ($this->publicationLineRepository->findFirstPublishedThrough($cycle, $publication) as $order => $published) {
            $columns[(int) $published->getPeriod()->getId()] = [
                'name' => $published->getLineName() ?? $published->getPeriod()->getLine()?->getName() ?? 'Ligne supprimée',
                'position' => $published->getPeriod()->getLine()?->getPosition() ?? \PHP_INT_MAX,
                'order' => $order,
            ];
        }
        uasort($columns, static fn (array $a, array $b): int => [$a['position'], $a['order']] <=> [$b['position'], $b['order']]);

        /** @var array<string, array<int, list<array{duty: Duty, member: ?PlanningTeamMember}>>> $byDateAndColumn */
        $byDateAndColumn = [];
        foreach ($this->entryRepository->findByPublication($publication) as $entry) {
            $duty = $entry->getDuty();
            $byDateAndColumn[$duty->getLocalDate()->format('Y-m-d')][(int) $duty->getPlanningPeriod()->getId()][] = ['duty' => $duty, 'member' => $entry->getTeamMember()];
        }

        return $this->grid(
            $publication,
            $first,
            $last,
            'Période '.$cycle->getNumber().' · du '.FrenchDate::long($first).' au '.FrenchDate::long($last),
            array_map(static fn (array $column): array => ['name' => $column['name']], array_values($columns)),
            array_keys($columns),
            $byDateAndColumn,
        );
    }

    /**
     * Before D173 (no stored PDF), as it was rendered then — the lines found
     * in the entries, their current names, the "Du … au …" label — but on the
     * days of the publication's period, never the planning's: those grow when
     * a following period is created (docs/decisions.md D206). Public, like
     * view(), so tests can check the content itself.
     *
     * @return array<string, mixed>
     */
    public function legacyView(PlanningPublication $publication): array
    {
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
        [$first, $last] = self::cycleDays($publication);

        return $this->grid(
            $publication,
            $first,
            $last,
            'Du '.FrenchDate::long($first).' au '.FrenchDate::long($last),
            array_map(static fn (PlanningLine $line): array => ['name' => $line->getName()], $lines),
            array_map(static fn (PlanningLine $line): int => (int) $line->getId(), $lines),
            $byDateAndLine,
        );
    }

    /**
     * @param list<array{name: string}>                                                       $lines
     * @param list<int>                                                                       $columnKeys
     * @param array<string, array<int, list<array{duty: Duty, member: ?PlanningTeamMember}>>> $byDateAndColumn
     *
     * @return array<string, mixed>
     */
    private function grid(PlanningPublication $publication, \DateTimeImmutable $first, \DateTimeImmutable $last, string $periodLabel, array $lines, array $columnKeys, array $byDateAndColumn): array
    {
        $planning = $publication->getPlanning();
        $tz = new \DateTimeZone($planning->getTimezone());
        $first = new \DateTimeImmutable($first->format('Y-m-d'), $tz);
        $last = new \DateTimeImmutable($last->format('Y-m-d'), $tz);

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
            foreach ($columnKeys as $key) {
                $items = $byDateAndColumn[$date->format('Y-m-d')][$key] ?? [];
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
            'periodLabel' => $periodLabel,
            'publishedAtLabel' => FrenchDate::long($publication->getPublishedAt()->setTimezone($tz)).' à '.$publication->getPublishedAt()->setTimezone($tz)->format('H:i'),
            'lines' => $lines,
            'weeks' => array_map(static fn (array $week): array => ['label' => $week['label'], 'days' => $week['days']], $weeks),
        ];
    }

    /**
     * @param array<string, mixed> $view
     */
    private function renderView(array $view): string
    {
        $options = new Options();
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->twig->render('pdf/planning.html.twig', $view));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * The published period's first and last day (inclusive), as pure calendar dates.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private static function cycleDays(PlanningPublication $publication): array
    {
        $cycle = $publication->getCycle() ?? throw new \LogicException('A publication always belongs to a period (lot L2).');

        return [
            new \DateTimeImmutable($cycle->getStartsAt()->format('Y-m-d')),
            new \DateTimeImmutable($cycle->getEndsAt()->modify('-1 day')->format('Y-m-d')),
        ];
    }

    private function legacyFilename(PlanningPublication $publication): string
    {
        return 'planning-'.$this->slug($publication).'.pdf';
    }

    private function slug(PlanningPublication $publication): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', $publication->getPlanning()->getName()))), '-');

        return '' !== $slug ? $slug : 'medvue';
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
