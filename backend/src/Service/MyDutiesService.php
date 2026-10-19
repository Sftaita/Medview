<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\DutySwapRequestRepository;
use App\Repository\PlanningRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * "Mes gardes" (docs/decisions.md D168): every duty $user currently holds,
 * across all their plannings, one entry per duty unit (a block once, with
 * all its days).
 *
 * Same reading as the weekly reminder (D146): the current calendar
 * (CurrentCalendarReader, DutyAssignment.current) of the lines whose period
 * is PUBLISHED — a draft calendar is never shown to its members, a late
 * edit on a published line is. Only the caller's own duties: nobody else's
 * name ever leaves this service.
 *
 * Periods (docs/decisions.md D198, lot L4e): every period of every cycle
 * whose own status is PUBLISHED (CurrentCalendarReader::readPublished()),
 * never the planning's status as a whole; each unit names its period. A
 * unit never spans two periods (a block stays in one, D189 option A), so a
 * boundary never shows a duty twice.
 */
final class MyDutiesService
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly CurrentCalendarReader $calendarReader,
        private readonly DutySwapRequestRepository $swapRequestRepository,
        private readonly ClockInterface $clock,
        private readonly PlanningCyclePresenter $cyclePresenter,
    ) {
    }

    /**
     * dutiesOf() plus what "Mes gardes" needs for swaps (docs/decisions.md
     * D178): `swappable` (not started yet — every listed unit is on a
     * PUBLISHED line) and `swapRequestStableId`, the caller's OPEN request
     * on that unit if any — shown as "Échange demandé", never as a
     * transfer: the unit is listed here because the caller still holds it.
     *
     * @return list<array<string, mixed>>
     */
    public function dutiesWithSwapStateOf(User $user): array
    {
        $openByDuty = [];
        foreach ($this->swapRequestRepository->findOpenByRequester($user) as $request) {
            $openByDuty[(string) $request->getOfferedDuty()->getStableId()] = (string) $request->getStableId();
        }

        $now = $this->clock->now();

        return array_map(static fn (array $unit): array => $unit + [
            'swappable' => new \DateTimeImmutable($unit['startsAt']) > $now,
            'swapRequestStableId' => $openByDuty[$unit['dutyStableId']] ?? null,
        ], $this->dutiesOf($user));
    }

    /**
     * @return list<array{
     *     key: string,
     *     dutyStableId: string,
     *     planningStableId: string,
     *     planningName: string,
     *     lineStableId: string,
     *     lineName: string,
     *     dutyTypeName: string,
     *     blockName: string|null,
     *     dates: non-empty-list<string>,
     *     startsAt: string,
     *     endsAt: string,
     *     conditional: bool,
     *     coverageState: string|null,
     *     period: array{stableId: string, number: int, count: int},
     * }> chronological
     */
    public function dutiesOf(User $user): array
    {
        $units = [];
        foreach ($this->planningRepository->findWithPublishedLineForMember($user) as $planning) {
            // Lot L7: how many periods the planning has — "Période N" is said only when there are several.
            $periodCount = $this->cyclePresenter->periodCount($planning);
            foreach ($this->calendarReader->readPublished($planning) as $cell) {
                if (null === $cell->member || $cell->member->getUser()->getId() !== $user->getId()) {
                    continue;
                }

                $duty = $cell->duty;
                $group = $duty->getGroupInstance();
                $key = null !== $group ? (string) $group->getStableId() : (string) $duty->getStableId();
                if (!isset($units[$key])) {
                    $units[$key] = [
                        'key' => $key,
                        // The unit's first duty — the one a swap request names (docs/decisions.md D178).
                        'dutyStableId' => (string) $duty->getStableId(),
                        'planningStableId' => (string) $planning->getStableId(),
                        'planningName' => $planning->getName(),
                        'lineStableId' => (string) $cell->line->getStableId(),
                        'lineName' => $cell->line->getName(),
                        'dutyTypeName' => $duty->getDutyType()->getName(),
                        'blockName' => $group?->getPattern()->getName(),
                        'dates' => [],
                        'startsAt' => $duty->getStartsAt(),
                        'endsAt' => $duty->getEndsAt(),
                        'conditional' => $duty->isConditional(),
                        'coverageState' => $cell->coverageState?->value,
                        // Only the periods of a line are read, and those always have their cycle (docs/planning-cycles.md I3).
                        'period' => [
                            ...$this->cyclePresenter->reference($duty->getPlanningPeriod()->getCycle() ?? throw new \LogicException('A line period has its cycle.')),
                            'count' => $periodCount,
                        ],
                    ];
                }

                $unit = &$units[$key];
                $unit['dates'][] = $duty->getLocalDate()->format('Y-m-d');
                if ($duty->getStartsAt() < $unit['startsAt']) {
                    $unit['startsAt'] = $duty->getStartsAt();
                    $unit['dutyStableId'] = (string) $duty->getStableId();
                }
                if ($duty->getEndsAt() > $unit['endsAt']) {
                    $unit['endsAt'] = $duty->getEndsAt();
                }
                unset($unit);
            }
        }

        usort($units, static fn (array $a, array $b): int => [$a['startsAt'], $a['lineName']] <=> [$b['startsAt'], $b['lineName']]);

        return array_map(static function (array $unit): array {
            $dates = array_values(array_unique($unit['dates']));
            sort($dates);
            $unit['dates'] = $dates;
            $unit['startsAt'] = $unit['startsAt']->format(\DATE_ATOM);
            $unit['endsAt'] = $unit['endsAt']->format(\DATE_ATOM);

            return $unit;
        }, $units);
    }
}
