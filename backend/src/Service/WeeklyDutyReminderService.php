<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\User;
use App\Entity\WeeklyDutyReminder;
use App\Repository\PlanningPublicationRepository;
use App\Repository\PlanningRepository;
use App\Repository\WeeklyDutyReminderRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "Vos gardes de la semaine prochaine" (docs/decisions.md D146), run every
 * Saturday by `app:duty-reminders:weekly` (host cron, docs/deployment.md).
 *
 * - "Next week" is Monday 00:00 → next Monday 00:00 in each Planning's own
 *   timezone: "today" is the run instant converted to that timezone, and
 *   the week is the one starting on the first Monday strictly after it.
 *   Duties are matched on their localDate, itself a calendar date in that
 *   timezone — the server's timezone never matters.
 * - Read from the current calendar at send time (CurrentCalendarReader,
 *   DutyAssignment.current) — a late edit, published or not, is reflected.
 *   Only lines whose period is PUBLISHED: a draft calendar is never sent.
 * - Periods (docs/decisions.md D198, lot L4e): a week that crosses two
 *   periods reads both, each through its own PUBLISHED status
 *   (CurrentCalendarReader::readPublished()) — one email per person and
 *   planning for the whole week, a draft period's duties left out.
 * - Somebody with no duty that week receives nothing — never an email
 *   saying "no duty".
 * - Unpublished changes (docs/decisions.md D199): the current calendar
 *   stays the source — the duties the person is actually expected to work
 *   (manual reassignment, completion, accepted swap included). The week is
 *   compared, duty by duty and by User, with the latest PlanningPublication
 *   of each period (PublicationChangeService::changesSince(), so A → B → A
 *   is no change). A duty the person holds now without having been
 *   diffused as its holder is marked, and their email then says it takes
 *   into account changes not republished yet — also when they lost a
 *   diffused duty of that week while keeping another one. Nothing is said
 *   when the week matches what was diffused. No new email is created: a
 *   former holder left with no duty that week still receives nothing (D199).
 * - Idempotent: a (user, planning, week) already reminded is skipped, so a
 *   second run the same Saturday sends nothing twice. A failed send writes
 *   no row, so the next run retries it.
 */
final class WeeklyDutyReminderService
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly WeeklyDutyReminderRepository $reminderRepository,
        private readonly CurrentCalendarReader $calendarReader,
        private readonly PlanningPublicationRepository $publicationRepository,
        private readonly PublicationChangeService $changeService,
        private readonly WeeklyDutyReminderMailer $mailer,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function run(\DateTimeImmutable $now): WeeklyReminderReport
    {
        $sent = 0;
        $skipped = 0;
        $failed = 0;
        $plannings = $this->planningRepository->findWithPublishedLine();

        foreach ($plannings as $planning) {
            $weekStart = $this->nextWeekStart($planning, $now);
            $weekEnd = $weekStart->modify('+7 days');

            $weekCells = $this->weekCells($planning, $weekStart, $weekEnd);
            $unpublished = $this->unpublishedChanges($weekCells);
            foreach ($this->dutiesByUser($weekCells) as ['user' => $user, 'cells' => $cells]) {
                if ($this->reminderRepository->exists($user, $planning, $weekStart)) {
                    ++$skipped;
                    continue;
                }

                $added = $unpublished['added'][(int) $user->getId()] ?? [];
                $concerned = [] !== $added || isset($unpublished['removed'][(int) $user->getId()]);
                if (!$this->mailer->send($user, $planning, $weekStart, $this->items($cells, $added), $concerned)) {
                    ++$failed;
                    continue;
                }

                $this->entityManager->persist(new WeeklyDutyReminder($user, $planning, $weekStart, \count($cells), new \DateTimeImmutable()));
                $this->entityManager->flush();
                ++$sent;
            }
        }

        return new WeeklyReminderReport(\count($plannings), $sent, $skipped, $failed);
    }

    /**
     * The Monday (a calendar date, in the Planning's timezone) of the week
     * after the one containing $now there.
     */
    public function nextWeekStart(Planning $planning, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $tz = new \DateTimeZone($planning->getTimezone());
        $localToday = new \DateTimeImmutable($now->setTimezone($tz)->format('Y-m-d'), $tz);

        return $localToday->modify('next monday');
    }

    /**
     * Every cell of the week on the planning's published periods — covered
     * or not, whoever holds it.
     *
     * @return list<CalendarCell>
     */
    private function weekCells(Planning $planning, \DateTimeImmutable $weekStart, \DateTimeImmutable $weekEnd): array
    {
        $from = $weekStart->format('Y-m-d');
        $to = $weekEnd->format('Y-m-d');

        return array_values(array_filter(
            $this->calendarReader->readPublished($planning, null, $from, $to),
            static function (CalendarCell $cell) use ($from, $to): bool {
                $date = $cell->duty->getLocalDate()->format('Y-m-d');

                return $date >= $from && $date < $to;
            },
        ));
    }

    /**
     * The week's differences with what each period last diffused
     * (docs/decisions.md D199), read from the append-only publication
     * entries: per User, the duties they hold now without having been
     * diffused as their holder (added), and whether they were diffused as
     * the holder of a duty of the week they no longer hold (removed).
     *
     * @param list<CalendarCell> $weekCells
     *
     * @return array{added: array<int, array<int, true>>, removed: array<int, true>} keyed by User id, then Duty id
     */
    private function unpublishedChanges(array $weekCells): array
    {
        /** @var array<int, array{cycle: PlanningCycle, cells: list<CalendarCell>}> $byCycle */
        $byCycle = [];
        foreach ($weekCells as $cell) {
            $cycle = $cell->duty->getPlanningPeriod()->getCycle() ?? throw new \LogicException('A published duty belongs to a period.');
            $byCycle[(int) $cycle->getId()] ??= ['cycle' => $cycle, 'cells' => []];
            $byCycle[(int) $cycle->getId()]['cells'][] = $cell;
        }

        $added = [];
        $removed = [];
        foreach ($byCycle as ['cycle' => $cycle, 'cells' => $cells]) {
            $reference = $this->publicationRepository->findLatestForCycle($cycle);
            if (null === $reference) {
                // Never diffused at all (not expected for a PUBLISHED period): nothing held now was ever told.
                foreach ($cells as $cell) {
                    if (null !== $cell->member) {
                        $added[(int) $cell->member->getUser()->getId()][(int) $cell->duty->getId()] = true;
                    }
                }
                continue;
            }
            foreach ($this->changeService->changesSince($reference, $cells) as $change) {
                if (null !== $change->after) {
                    $added[(int) $change->after->getUser()->getId()][(int) $change->duty->getId()] = true;
                }
                if (null !== $change->before) {
                    $removed[(int) $change->before->getUser()->getId()] = true;
                }
            }
        }

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * @param list<CalendarCell> $weekCells
     *
     * @return list<array{user: User, cells: non-empty-list<CalendarCell>}> only people with at least one duty
     */
    private function dutiesByUser(array $weekCells): array
    {
        $byUser = [];
        foreach ($weekCells as $cell) {
            if (null === $cell->member || !$cell->member->getUser()->isActive()) {
                continue;
            }
            $user = $cell->member->getUser();
            $byUser[(int) $user->getId()] ??= ['user' => $user, 'cells' => []];
            $byUser[(int) $user->getId()]['cells'][] = $cell;
        }

        return array_values($byUser);
    }

    /**
     * One line per duty unit of the week — a block once, with its days
     * ("Samedi 17 + dimanche 18 octobre — Bloc Week-end"), a standalone duty
     * with its line ("Mardi 13 octobre — Ligne principale").
     *
     * A unit is `changed` when one of its duties is held by the person
     * without having been diffused to them (D199).
     *
     * @param non-empty-list<CalendarCell> $cells
     * @param array<int, true>             $addedDutyIds
     *
     * @return list<array{when: string, what: string, changed: bool}>
     */
    private function items(array $cells, array $addedDutyIds): array
    {
        $units = [];
        foreach ($cells as $cell) {
            $group = $cell->duty->getGroupInstance();
            $key = null !== $group ? 'g'.$group->getId() : 'd'.$cell->duty->getId();
            $units[$key] ??= ['cell' => $cell, 'dates' => [], 'start' => $cell->duty->getStartsAt(), 'changed' => false];
            $units[$key]['dates'][] = $cell->duty->getLocalDate();
            $units[$key]['changed'] = $units[$key]['changed'] || isset($addedDutyIds[(int) $cell->duty->getId()]);
        }
        uasort($units, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        $items = [];
        foreach ($units as $unit) {
            $dates = $unit['dates'];
            sort($dates);
            $group = $unit['cell']->duty->getGroupInstance();
            $what = null !== $group
                ? 'Bloc '.$group->getPattern()->getName().' — '.$unit['cell']->line->getName()
                : $unit['cell']->line->getName();
            $items[] = ['when' => ucfirst($this->daysLabel($dates)), 'what' => $what, 'changed' => $unit['changed']];
        }

        return $items;
    }

    /**
     * "mardi 13 octobre", "samedi 17 + dimanche 18 octobre",
     * "samedi 31 octobre + dimanche 1er novembre".
     *
     * @param non-empty-list<\DateTimeImmutable> $dates sorted
     */
    private function daysLabel(array $dates): string
    {
        $parts = [];
        $count = \count($dates);
        foreach ($dates as $i => $date) {
            $day = 1 === (int) $date->format('j') ? '1er' : $date->format('j');
            $label = FrenchDate::weekday($date).' '.$day;
            $next = $dates[$i + 1] ?? null;
            if ($i === $count - 1 || $next->format('Y-m') !== $date->format('Y-m')) {
                $label .= ' '.explode(' ', FrenchDate::month($date))[0];
            }
            $parts[] = $label;
        }

        return implode(' + ', $parts);
    }
}
