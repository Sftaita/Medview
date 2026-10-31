<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriod;
use App\Entity\PlanningPeriodStatus;
use App\Entity\PlanningTeamMember;
use App\Entity\User;
use App\Repository\PlanningGenerationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * docs/decisions.md D216 / D218 — what a change of the calendar meets that it
 * will not change: the periods of the person's lines already generated on the
 * days it makes unavailable (added) or available again (released), and the
 * duties the person currently holds on the days added.
 *
 * Read only, and only about the caller's own lines: the plannings named are
 * those whose generated line period the person takes part in (their
 * membership of that line's team overlaps it). The duties are read exactly as
 * "Mes gardes" reads them (MyDutiesService: the current calendar of the
 * PUBLISHED line periods) — a line generated but not published yet is named,
 * never its draft duties (docs/decisions.md D204).
 *
 * Nothing here changes an assignment: an unavailability added after a
 * generation is recorded in the calendar and used by a later generation only.
 */
final class AvailabilityImpactService
{
    private const GENERATED = [PlanningPeriodStatus::GENERATED, PlanningPeriodStatus::VALIDATED, PlanningPeriodStatus::PUBLISHED, PlanningPeriodStatus::ARCHIVED];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlanningGenerationRepository $generationRepository,
        private readonly MyDutiesService $myDuties,
        private readonly PlanningCyclePresenter $cyclePresenter,
    ) {
    }

    /**
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $added    the new unavailable instants [from, to)
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $released the instants no longer unavailable [from, to)
     *
     * @return list<array<string, mixed>> one entry per planning period met, in date order
     */
    public function impacts(User $user, array $added, array $released = []): array
    {
        $added = array_values(array_filter($added, static fn (array $range): bool => $range[0] < $range[1]));
        $released = array_values(array_filter($released, static fn (array $range): bool => $range[0] < $range[1]));
        if ([] === $added && [] === $released) {
            return [];
        }

        $byCycle = [];
        foreach ($this->generatedLinePeriods($user) as $period) {
            $cycle = $period->getCycle();
            $line = $period->getLine();
            if (null === $cycle || null === $line) {
                continue;
            }
            $planning = $cycle->getPlanning();
            $addedDays = $this->daysMet($planning, $period->getStartsAt(), $period->getEndsAt(), $added);
            $releasedDays = $this->daysMet($planning, $period->getStartsAt(), $period->getEndsAt(), $released);
            if ([] === $addedDays && [] === $releasedDays) {
                continue;
            }

            $key = (string) $cycle->getStableId();
            $entry = $byCycle[$key] ?? ['planning' => $planning, 'cycle' => $cycle, 'lines' => [], 'generatedAt' => null, 'published' => false, 'added' => [], 'released' => []];
            $entry['lines'][(string) $line->getStableId()] = $line->getName();
            $entry['published'] = $entry['published'] || PlanningPeriodStatus::PUBLISHED === $period->getStatus();
            $generatedAt = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($period)?->getGeneratedAt();
            if (null !== $generatedAt && (null === $entry['generatedAt'] || $generatedAt > $entry['generatedAt'])) {
                $entry['generatedAt'] = $generatedAt;
            }
            $entry['added'] = array_merge($entry['added'], $addedDays);
            $entry['released'] = array_merge($entry['released'], $releasedDays);
            $byCycle[$key] = $entry;
        }

        if ([] === $byCycle) {
            return [];
        }

        $conflicts = [];
        // Only a day made unavailable can clash with a duty held; a day released never does.
        foreach ([] === $added ? [] : $this->myDuties->dutiesOf($user) as $unit) {
            $startsAt = new \DateTimeImmutable($unit['startsAt']);
            $endsAt = new \DateTimeImmutable($unit['endsAt']);
            foreach ($added as [$from, $to]) {
                if ($startsAt < $to && $endsAt > $from) {
                    $conflicts[$unit['period']['stableId']][] = [
                        'dates' => $unit['dates'],
                        'lineName' => $unit['lineName'],
                        'dutyTypeName' => $unit['dutyTypeName'],
                        'blockName' => $unit['blockName'],
                    ];
                    break;
                }
            }
        }

        $result = [];
        foreach ($byCycle as $key => $entry) {
            /** @var Planning $planning */
            $planning = $entry['planning'];
            /** @var PlanningCycle $cycle */
            $cycle = $entry['cycle'];
            $addedDays = self::sortedDays($entry['added']);
            $releasedDays = self::sortedDays($entry['released']);
            $all = self::sortedDays([...$addedDays, ...$releasedDays]);
            $result[] = [
                'planningStableId' => (string) $planning->getStableId(),
                'planningName' => $planning->getName(),
                'period' => [...$this->cyclePresenter->reference($cycle), 'count' => $this->cyclePresenter->periodCount($planning)],
                'lines' => array_values($entry['lines']),
                'generatedAt' => $entry['generatedAt']?->format(\DATE_ATOM),
                'published' => $entry['published'],
                // The local days of the period met, made unavailable / available again.
                'addedDays' => $addedDays,
                'releasedDays' => $releasedDays,
                'from' => $all[0],
                'to' => $all[\count($all) - 1],
                'conflicts' => $conflicts[$key] ?? [],
            ];
        }
        usort($result, static fn (array $a, array $b): int => [$a['from'], $a['planningName']] <=> [$b['from'], $b['planningName']]);

        return $result;
    }

    /**
     * The new days that are missing from the calendar before a change: all of
     * $after when nothing unavailable was there, else $after minus $before.
     *
     * @param array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null $before
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    public static function addedRanges(?array $before, array $after): array
    {
        return self::minus($after, $before);
    }

    /**
     * The days a change gives back (docs/decisions.md D218): $before minus
     * $after — all of $before for a deletion or a change to a preference.
     *
     * @param array{0: \DateTimeImmutable, 1: \DateTimeImmutable}      $before
     * @param array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null $after
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    public static function releasedRanges(array $before, ?array $after): array
    {
        return self::minus($before, $after);
    }

    /**
     * @param array{0: \DateTimeImmutable, 1: \DateTimeImmutable}      $range
     * @param array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null $cut
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    private static function minus(array $range, ?array $cut): array
    {
        if (null === $cut || $cut[1] <= $range[0] || $cut[0] >= $range[1]) {
            return [$range];
        }

        $ranges = [];
        if ($range[0] < $cut[0]) {
            $ranges[] = [$range[0], $cut[0]];
        }
        if ($range[1] > $cut[1]) {
            $ranges[] = [$cut[1], $range[1]];
        }

        return $ranges;
    }

    /**
     * @param list<string> $days
     *
     * @return list<string>
     */
    private static function sortedDays(array $days): array
    {
        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }

    /**
     * The generated periods of the active lines whose team the person belongs
     * to while the period runs.
     *
     * @return list<PlanningPeriod>
     */
    private function generatedLinePeriods(User $user): array
    {
        /** @var list<PlanningPeriod> $periods */
        $periods = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT pp')
            ->from(PlanningPeriod::class, 'pp')
            ->join(PlanningLine::class, 'l', 'WITH', 'pp.line = l AND l.active = true')
            ->join(PlanningTeamMember::class, 'tm', 'WITH', 'tm.planningTeam = l.planningTeam AND tm.user = :user')
            ->andWhere('pp.status IN (:generated)')
            ->andWhere('pp.cycle IS NOT NULL')
            ->andWhere('tm.membershipStart < pp.endsAt')
            ->andWhere('tm.membershipEnd IS NULL OR tm.membershipEnd > pp.startsAt')
            ->setParameter('user', $user)
            ->setParameter('generated', self::GENERATED)
            ->orderBy('pp.startsAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $periods;
    }

    /**
     * The local days of [$startsAt, $endsAt) that $ranges touch, in order.
     *
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $ranges
     *
     * @return list<string>
     */
    private function daysMet(Planning $planning, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, array $ranges): array
    {
        $zone = new \DateTimeZone($planning->getTimezone());
        $from = new \DateTimeImmutable($startsAt->format('Y-m-d').' 00:00:00', $zone);
        $to = new \DateTimeImmutable($endsAt->format('Y-m-d').' 00:00:00', $zone);

        $days = [];
        foreach ($ranges as [$a, $b]) {
            $lo = max($a, $from);
            $hi = min($b, $to);
            if ($lo >= $hi) {
                continue;
            }
            $day = new \DateTimeImmutable($lo->setTimezone($zone)->format('Y-m-d'), $zone);
            // The last instant inside the range, read as a local day.
            $last = $hi->modify('-1 second')->setTimezone($zone)->format('Y-m-d');
            while ($day->format('Y-m-d') <= $last) {
                $days[] = $day->format('Y-m-d');
                $day = $day->modify('+1 day');
            }
        }

        return self::sortedDays($days);
    }
}
