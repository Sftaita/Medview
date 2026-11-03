<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\WeekStructureBlockInput;
use App\Dto\WeekStructureUpdateRequest;
use App\Entity\Planning;
use App\Entity\PlanningLine;
use App\Entity\PlanningLineType;
use App\Exception\InvalidWeekStructureException;
use App\Exception\PeriodBoundarySplitsBlockException;
use App\Repository\PlanningLineDemandPolicyRepository;
use App\Repository\PlanningLineRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The general "Semaine type" of a Planning (docs/decisions.md D227): the
 * structure lines may share. It is one more input of the existing pipeline,
 * never a second one: a line that follows it receives a copy through
 * WeekStructureService::replace() — its own patterns, its own team — so the
 * materialization, the engine and history are exactly what they were.
 *
 * Each line says what it uses (PlanningLine::$weekStructureMode): GENERAL or
 * CUSTOM; a reinforcement line that inherits its blocks (D224) is neither, its
 * structure is its source's. Changing the general structure copies it to the
 * lines that follow it, nothing else: WeekStructureService never touches a duty
 * that exists, and a day already materialized stays decided. A period already
 * generated or published therefore keeps every duty — the new structure only
 * reaches what is created afterwards.
 *
 * Until a general structure is saved, it reads as the primary line's own: that
 * is what "Semaine type" has always edited, so nothing has to be migrated.
 */
final class PlanningWeekTemplateService
{
    public const GENERAL = 'GENERAL';
    public const CUSTOM = 'CUSTOM';

    public function __construct(
        private readonly WeekStructureService $weekStructureService,
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningLineDemandPolicyRepository $policyRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function read(Planning $planning): WeekStructureView
    {
        $template = $planning->getWeekTemplate();
        if (null !== $template) {
            return self::toView($template);
        }
        foreach ($this->lineRepository->findByPlanning($planning) as $line) {
            if (PlanningLineType::PRIMARY === $line->getType()) {
                return $this->weekStructureService->read($line);
            }
        }

        return new WeekStructureView([], [], null, ['LUN', 'MAR', 'MER', 'JEU', 'VEN', 'SAM', 'DIM']);
    }

    public function isDefined(Planning $planning): bool
    {
        $view = $this->read($planning);

        return [] !== $view->blocks || [] !== $view->solo;
    }

    /**
     * Saves the general structure and copies it to every active line that
     * follows it, all or nothing: a block a boundary would cut on any of them
     * refuses the whole operation.
     *
     * @return list<PlanningLine> the lines it was copied to
     *
     * @throws InvalidWeekStructureException
     */
    public function save(Planning $planning, WeekStructureUpdateRequest $request): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($planning, $request): array {
            $this->entityManager->refresh($planning, LockMode::PESSIMISTIC_WRITE);
            $planning->changeWeekTemplate(self::toTemplate($request));
            $this->entityManager->flush();

            $applied = [];
            foreach ($this->lineRepository->findByPlanning($planning) as $line) {
                if ($line->isActive() && self::GENERAL === $line->getWeekStructureMode() && !$this->inherits($line)) {
                    $this->weekStructureService->replace($line, $request);
                    $applied[] = $line;
                }
            }

            return $applied;
        });
    }

    /**
     * What $line uses from now on: the general structure (copied to it now) or
     * its own (which keeps what it currently has until it is edited).
     */
    public function setMode(PlanningLine $line, string $mode): void
    {
        $planning = $line->getPlanning();
        $this->entityManager->wrapInTransaction(function () use ($line, $mode, $planning): void {
            $this->entityManager->refresh($planning, LockMode::PESSIMISTIC_WRITE);
            // The first line leaving the general structure must not take it away: its current content becomes explicit.
            if (null === $planning->getWeekTemplate()) {
                $planning->changeWeekTemplate(self::toTemplate(self::requestFrom($this->read($planning))));
            }
            if (self::GENERAL === $mode) {
                $this->weekStructureService->replace($line, self::requestFrom($this->read($planning)));
            }
            $line->changeWeekStructureMode($mode);
            $this->entityManager->flush();
        });
    }

    /**
     * A new line follows the general structure, when there is one: it is ready
     * to be generated with the others, and "Personnaliser" stays one click away.
     */
    public function adoptOnCreation(PlanningLine $line): void
    {
        $planning = $line->getPlanning();
        if (!$this->isDefined($planning)) {
            return;
        }
        try {
            $this->weekStructureService->replace($line, self::requestFrom($this->read($planning)));
        } catch (PeriodBoundarySplitsBlockException) {
            return; // A boundary of this planning cuts it: the line starts "to configure", and creating it never fails.
        }
        $line->changeWeekStructureMode(self::GENERAL);
        $this->entityManager->flush();
    }

    /**
     * Saving the weekly structure of a line by itself makes it its own:
     * it no longer follows the general one.
     */
    public function markCustomized(PlanningLine $line): void
    {
        $planning = $line->getPlanning();
        if (self::GENERAL !== $line->getWeekStructureMode()) {
            return;
        }
        // The primary line was the implicit general structure: its content is kept as the general one first.
        if (null === $planning->getWeekTemplate()) {
            $planning->changeWeekTemplate(self::toTemplate(self::requestFrom($this->read($planning))));
        }
        $line->changeWeekStructureMode(self::CUSTOM);
    }

    /** GENERAL, CUSTOM, or INHERITED for a reinforcement line that inherits its source's blocks. */
    public function kindOf(PlanningLine $line): string
    {
        return $this->inherits($line) ? 'INHERITED' : $line->getWeekStructureMode();
    }

    private function inherits(PlanningLine $line): bool
    {
        return true === $this->policyRepository->findActiveForLine($line)?->toRules()->inheritsStructure();
    }

    /**
     * @return array{blocks: list<array{name: string, days: list<string>, family: string}>, solo: list<string>, soloFamily: string, excluded: list<string>}
     */
    private static function toTemplate(WeekStructureUpdateRequest $request): array
    {
        return [
            'blocks' => array_map(static fn (WeekStructureBlockInput $block): array => ['name' => $block->name, 'days' => $block->days, 'family' => $block->family], $request->blocks),
            'solo' => $request->solo,
            'soloFamily' => $request->soloFamily,
            'excluded' => $request->excluded,
        ];
    }

    /**
     * @param array{blocks: list<array{name: string, days: list<string>, family: string}>, solo: list<string>, soloFamily: string, excluded: list<string>} $template
     */
    private static function toView(array $template): WeekStructureView
    {
        return new WeekStructureView(
            array_map(static fn (array $block): WeekStructureBlockView => new WeekStructureBlockView($block['name'], $block['days'], '' !== $block['family'] ? $block['family'] : null), $template['blocks']),
            $template['solo'],
            '' !== $template['soloFamily'] ? $template['soloFamily'] : null,
            $template['excluded'],
        );
    }

    private static function requestFrom(WeekStructureView $view): WeekStructureUpdateRequest
    {
        return new WeekStructureUpdateRequest(
            array_map(static fn (WeekStructureBlockView $block): WeekStructureBlockInput => new WeekStructureBlockInput($block->name, $block->days, $block->family ?? ''), $view->blocks),
            $view->solo,
            $view->soloFamily ?? '',
            $view->excluded,
        );
    }
}
