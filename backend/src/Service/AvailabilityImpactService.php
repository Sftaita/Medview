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
 * docs/decisions.md D216 — what a new unavailability meets that it will not
 * change: the periods of the person's lines already generated on those days,
 * and the duties the person currently holds on them.
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
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $added the new unavailable instants [from, to)
     *
     * @return list<array<string, mixed>> one entry per planning period met, in date order
     */
    public function impacts(User $user, array $added): array
    {
        $added = array_values(array_filter($added, static fn (array $range): bool => $range[0] < $range[1]));
        if ([] === $added) {
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
            $days = $this->daysMet($planning, $period->getStartsAt(), $period->getEndsAt(), $added);
            if (null === $days) {
                continue;
            }

            $key = (string) $cycle->getStableId();
            $entry = $byCycle[$key] ?? ['planning' => $planning, 'cycle' => $cycle, 'lines' => [], 'generatedAt' => null, 'published' => false, 'days' => $days];
            $entry['lines'][(string) $line->getStableId()] = $line->getName();
            $entry['published'] = $entry['published'] || PlanningPeriodStatus::PUBLISHED === $period->getStatus();
            $generatedAt = $this->generationRepository->findMostRecentCompletedByPlanningPeriod($period)?->getGeneratedAt();
            if (null !== $generatedAt && (null === $entry['generatedAt'] || $generatedAt > $entry['generatedAt'])) {
                $entry['generatedAt'] = $generatedAt;
            }
            $entry['days'] = [min($entry['days'][0], $days[0]), max($entry['days'][1], $days[1])];
            $byCycle[$key] = $entry;
        }

        if ([] === $byCycle) {
            return [];
        }

        $conflicts = [];
        foreach ($this->myDuties->dutiesOf($user) as $unit) {
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
            $result[] = [
                'planningStableId' => (string) $planning->getStableId(),
                'planningName' => $planning->getName(),
                'period' => [...$this->cyclePresenter->reference($cycle), 'count' => $this->cyclePresenter->periodCount($planning)],
                'lines' => array_values($entry['lines']),
                'generatedAt' => $entry['generatedAt']?->format(\DATE_ATOM),
                'published' => $entry['published'],
                'from' => $entry['days'][0],
                'to' => $entry['days'][1],
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
        if (null === $before || $before[1] <= $after[0] || $before[0] >= $after[1]) {
            return [$after];
        }

        $ranges = [];
        if ($after[0] < $before[0]) {
            $ranges[] = [$after[0], $before[0]];
        }
        if ($after[1] > $before[1]) {
            $ranges[] = [$before[1], $after[1]];
        }

        return $ranges;
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
     * The first and last local day of [$startsAt, $endsAt) met by $added, or null.
     *
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $added
     *
     * @return array{0: string, 1: string}|null
     */
    private function daysMet(Planning $planning, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, array $added): ?array
    {
        $zone = new \DateTimeZone($planning->getTimezone());
        $from = new \DateTimeImmutable($startsAt->format('Y-m-d').' 00:00:00', $zone);
        $to = new \DateTimeImmutable($endsAt->format('Y-m-d').' 00:00:00', $zone);

        $first = null;
        $last = null;
        foreach ($added as [$a, $b]) {
            $lo = max($a, $from);
            $hi = min($b, $to);
            if ($lo >= $hi) {
                continue;
            }
            $day = $lo->setTimezone($zone)->format('Y-m-d');
            // The last instant inside the range, read as a local day.
            $end = $hi->modify('-1 second')->setTimezone($zone)->format('Y-m-d');
            $first = null === $first || $day < $first ? $day : $first;
            $last = null === $last || $end > $last ? $end : $last;
        }

        return null === $first ? null : [$first, (string) $last];
    }
}
