<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningLine;
use App\Entity\PlanningPeriodStatus;
use App\Entity\User;
use App\Entity\WeeklyDutyReminder;
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
 * - Somebody with no duty that week receives nothing — never an email
 *   saying "no duty".
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

            foreach ($this->dutiesByUser($planning, $weekStart, $weekEnd) as ['user' => $user, 'cells' => $cells]) {
                if ($this->reminderRepository->exists($user, $planning, $weekStart)) {
                    ++$skipped;
                    continue;
                }

                if (!$this->mailer->send($user, $planning, $weekStart, $this->items($cells))) {
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
     * @return list<array{user: User, cells: non-empty-list<CalendarCell>}> only people with at least one duty
     */
    private function dutiesByUser(Planning $planning, \DateTimeImmutable $weekStart, \DateTimeImmutable $weekEnd): array
    {
        $from = $weekStart->format('Y-m-d');
        $to = $weekEnd->format('Y-m-d');

        $byUser = [];
        $cells = $this->calendarReader->read($planning, static fn (PlanningLine $line): bool => PlanningPeriodStatus::PUBLISHED === $line->getPlanningPeriod()->getStatus());
        foreach ($cells as $cell) {
            $date = $cell->duty->getLocalDate()->format('Y-m-d');
            if (null === $cell->member || $date < $from || $date >= $to || !$cell->member->getUser()->isActive()) {
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
     * @param non-empty-list<CalendarCell> $cells
     *
     * @return list<array{when: string, what: string}>
     */
    private function items(array $cells): array
    {
        $units = [];
        foreach ($cells as $cell) {
            $group = $cell->duty->getGroupInstance();
            $key = null !== $group ? 'g'.$group->getId() : 'd'.$cell->duty->getId();
            $units[$key] ??= ['cell' => $cell, 'dates' => [], 'start' => $cell->duty->getStartsAt()];
            $units[$key]['dates'][] = $cell->duty->getLocalDate();
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
            $items[] = ['when' => ucfirst($this->daysLabel($dates)), 'what' => $what];
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
