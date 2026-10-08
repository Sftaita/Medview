<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningLine;
use App\Entity\PlanningPeriodStatus;
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
 */
final class MyDutiesService
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly CurrentCalendarReader $calendarReader,
        private readonly DutySwapRequestRepository $swapRequestRepository,
        private readonly ClockInterface $clock,
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
     * }> chronological
     */
    public function dutiesOf(User $user): array
    {
        $units = [];
        foreach ($this->planningRepository->findWithPublishedLineForMember($user) as $planning) {
            $cells = $this->calendarReader->read($planning, static fn (PlanningLine $line): bool => PlanningPeriodStatus::PUBLISHED === $line->getPlanningPeriod()->getStatus());
            foreach ($cells as $cell) {
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
