<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AvailabilityCollection;
use App\Entity\AvailabilityResponseStatus;
use App\Entity\PeriodPreparation;
use App\Entity\PeriodPreparationReminder;
use App\Entity\PlanningCycle;
use App\Entity\PlanningPeriodStatus;
use App\Entity\PreparationActionState;
use App\Entity\ReminderOffsetUnit;
use App\Entity\User;
use App\Exception\PlanningCycleException;
use App\Repository\AvailabilityCollectionRepository;
use App\Repository\AvailabilityCollectionResponseRepository;
use App\Repository\PeriodPreparationDeliveryRepository;
use App\Repository\PeriodPreparationReminderRepository;
use App\Repository\PeriodPreparationRepository;
use App\Repository\PlanningCycleRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * « Préparer la période » (docs/decisions.md D210): what a manager plans
 * ahead for one period — the announcement (the collection opens by itself and
 * the invitations go out), the target date of the manual generation and the
 * reminders N days or weeks before it.
 *
 * This class plans; PeriodPreparationRunner executes, every 10 minutes, and
 * checks everything again at that moment. Planning never sends anything and
 * never opens a collection by itself. Every write runs under the planning's
 * lock, like every period operation, so it never interleaves with a runner
 * executing the same period.
 */
final class PeriodPreparationService
{
    public const MAX_REMINDERS = 10;
    private const LOCKED_STATUSES = [PlanningPeriodStatus::VALIDATED, PlanningPeriodStatus::PUBLISHED, PlanningPeriodStatus::ARCHIVED];

    public function __construct(
        private readonly PeriodPreparationRepository $preparationRepository,
        private readonly PeriodPreparationReminderRepository $reminderRepository,
        private readonly PeriodPreparationDeliveryRepository $deliveryRepository,
        private readonly PlanningCycleRepository $cycleRepository,
        private readonly AvailabilityCollectionRepository $collectionRepository,
        private readonly AvailabilityCollectionResponseRepository $responseRepository,
        private readonly PlanningCycleService $cycleService,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * What the panel shows.
     *
     * @return array<string, mixed>
     */
    public function view(PlanningCycle $cycle): array
    {
        $now = $this->now();
        $preparation = $this->preparationRepository->findOneByCycle($cycle);
        $counts = null !== $preparation ? $this->deliveryRepository->countsByAction($preparation) : [];
        $blockers = $this->blockers($cycle, $now);

        return [
            'period' => [
                'stableId' => (string) $cycle->getStableId(),
                'number' => $cycle->getNumber(),
                'startsAt' => $cycle->getStartsAt()->format('Y-m-d'),
                'lastDay' => $this->lastDay($cycle)->format('Y-m-d'),
            ],
            'timezone' => PreparationCalendar::TIMEZONE,
            'sendTime' => PreparationCalendar::SEND_TIME,
            'today' => PreparationCalendar::today($now)->format('Y-m-d'),
            'editable' => [] === $blockers,
            'blockers' => $blockers,
            'announcement' => [
                'on' => PreparationCalendar::ymd($preparation?->getAnnounceOn()),
                'state' => strtolower($preparation?->getAnnouncementState()?->value ?? 'none'),
                'outcome' => $preparation?->getAnnouncementOutcome(),
                'executedAt' => $preparation?->getAnnouncementExecutedAt()?->format(\DATE_ATOM),
                'emails' => $counts['announcement'] ?? self::noEmails(),
            ],
            'targetGenerationOn' => PreparationCalendar::ymd($preparation?->getTargetGenerationOn()),
            'collection' => $this->collectionState($cycle),
            'reminders' => null === $preparation ? [] : array_map(fn (PeriodPreparationReminder $reminder): array => [
                'stableId' => (string) $reminder->getStableId(),
                'value' => $reminder->getOffsetValue(),
                'unit' => $reminder->getOffsetUnit()->value,
                'dueOn' => $reminder->getDueOn()->format('Y-m-d'),
                'state' => strtolower($reminder->getState()->value),
                'outcome' => $reminder->getOutcome(),
                'executedAt' => $reminder->getExecutedAt()?->format(\DATE_ATOM),
                'emails' => $counts[(int) $reminder->getId()] ?? self::noEmails(),
            ], $this->reminderRepository->findByPreparation($preparation)),
        ];
    }

    /**
     * Replaces what is planned: the announcement date (null: none, or cancel
     * the one not run yet), the target date, and the list of reminders not
     * sent yet — a listed stableId changes that reminder, a new entry adds
     * one, a scheduled reminder left out is removed. What already ran is
     * history: it is shown, never changed.
     *
     * @param array{announceOn: ?string, targetGenerationOn: ?string, reminders: list<array{stableId?: ?string, value: mixed, unit: mixed}>} $input
     *
     * @throws PlanningCycleException
     */
    public function save(PlanningCycle $cycle, User $by, array $input): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->entityManager->refresh($cycle->getPlanning(), LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($cycle);
            $now = $this->now();
            $blockers = $this->blockers($cycle, $now);
            if ([] !== $blockers) {
                throw PlanningCycleException::conflict($blockers[0], self::BLOCKER_MESSAGES[$blockers[0]]);
            }

            $preparation = $this->preparationRepository->findOneByCycle($cycle);
            if (null === $preparation) {
                $preparation = new PeriodPreparation($cycle, $by, $now);
                $this->entityManager->persist($preparation);
            }
            $this->apply($preparation, $cycle, $input, $now);
            $preparation->touch($by, $now);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            $this->entityManager->clear();
            throw $exception;
        }
    }

    /**
     * docs/decisions.md D210: once the generation of $cycle is launched, the
     * reminders not sent yet and an announcement not run yet are pointless —
     * cancelled, with their emails still waiting. Called by
     * PlanningJobService::requestGeneration(); the runner checks it again
     * before every send.
     */
    public function onGenerationRequested(PlanningCycle $cycle): void
    {
        $preparation = $this->preparationRepository->findOneByCycle($cycle);
        if (null === $preparation) {
            return;
        }
        $this->cancelPlanned($preparation, $this->now(), 'generation_started');
        $this->entityManager->flush();
    }

    /** Cancels the scheduled announcement and reminders of $preparation, and its emails not sent yet. */
    public function cancelPlanned(PeriodPreparation $preparation, \DateTimeImmutable $now, string $reason): void
    {
        if ($preparation->isAnnouncementScheduled()) {
            $preparation->finishAnnouncement(PreparationActionState::CANCELLED, $reason, $now);
        }
        foreach ($this->reminderRepository->findScheduled($preparation) as $reminder) {
            $reminder->finish(PreparationActionState::CANCELLED, $reason, $now);
        }
        $this->deliveryRepository->cancelUnsent($preparation->getCycle(), $reason);
    }

    // --- facts shared with the runner ---------------------------------------------------------------

    /** A generation (or its job) exists for $cycle: what D210 calls "launched". */
    public function generationStarted(PlanningCycle $cycle): bool
    {
        return (bool) $this->entityManager->getConnection()->fetchOne(<<<'SQL'
            SELECT EXISTS (SELECT 1 FROM planning_jobs WHERE planning_cycle_id = :cycle AND kind = 'GENERATE')
                OR EXISTS (SELECT 1 FROM planning_generations g JOIN planning_periods p ON p.id = g.planning_period_id WHERE p.planning_cycle_id = :cycle)
        SQL, ['cycle' => $cycle->getId()]);
    }

    public function isLocked(PlanningCycle $cycle): bool
    {
        foreach ($this->cycleRepository->periodsOf($cycle) as $period) {
            if (\in_array($period->getStatus(), self::LOCKED_STATUSES, true)) {
                return true;
            }
        }

        return false;
    }

    public function lastDay(PlanningCycle $cycle): \DateTimeImmutable
    {
        return PreparationCalendar::day($cycle->getEndsAt()->modify('-1 day')->format('Y-m-d'));
    }

    /**
     * @return list<AvailabilityCollection>
     */
    public function openCollections(PlanningCycle $cycle): array
    {
        return array_values(array_filter(
            $this->collectionRepository->findOpenByPlanning($cycle->getPlanning()),
            static fn (AvailabilityCollection $collection): bool => $collection->getCycle() === $cycle,
        ));
    }

    /**
     * The people still expected to confirm in an open collection of $cycle,
     * with the collection their link points to (the first one they have not
     * answered). CONFIRMED and NO_UNAVAILABILITY both count as answered.
     *
     * @return array<int, array{user: User, collection: AvailabilityCollection}> by user id
     */
    public function pendingRecipients(PlanningCycle $cycle): array
    {
        $collections = $this->openCollections($cycle);
        usort($collections, static fn (AvailabilityCollection $a, AvailabilityCollection $b): int => $a->getStartsAt() <=> $b->getStartsAt());
        $pending = [];
        foreach ($collections as $collection) {
            foreach ($this->responseRepository->findByCollection($collection) as $response) {
                $user = $response->getUser();
                if (AvailabilityResponseStatus::PENDING === $response->getStatus() && $user->isActive()) {
                    $pending[(int) $user->getId()] ??= ['user' => $user, 'collection' => $collection];
                }
            }
        }

        return $pending;
    }

    /**
     * Why nothing can be planned (or changed) for $cycle now.
     *
     * @return list<string>
     */
    public function blockers(PlanningCycle $cycle, \DateTimeImmutable $now): array
    {
        $blockers = [];
        if (!$this->cycleService->isCreationEnabled()) {
            $blockers[] = 'period_management_disabled';
        }
        if ($this->generationStarted($cycle)) {
            $blockers[] = 'period_generation_started';
        }
        if ($this->isLocked($cycle)) {
            $blockers[] = 'period_locked';
        }
        if (PreparationCalendar::today($now) > $this->lastDay($cycle)) {
            $blockers[] = 'period_over';
        }

        return $blockers;
    }

    private const BLOCKER_MESSAGES = [
        'period_management_disabled' => 'Managing the periods of a planning is not enabled.',
        'period_generation_started' => 'The generation of this period was launched: its preparation no longer changes.',
        'period_locked' => 'This period is validated or published: its preparation no longer changes.',
        'period_over' => 'This period is over.',
    ];

    // --- writing ---------------------------------------------------------------------------------------

    /**
     * @param array{announceOn: ?string, targetGenerationOn: ?string, reminders: list<array{stableId?: ?string, value: mixed, unit: mixed}>} $input
     */
    private function apply(PeriodPreparation $preparation, PlanningCycle $cycle, array $input, \DateTimeImmutable $now): void
    {
        $today = PreparationCalendar::today($now);
        $lastDay = $this->lastDay($cycle);
        $announceOn = self::date($input['announceOn'] ?? null, 'announceOn');
        $target = self::date($input['targetGenerationOn'] ?? null, 'targetGenerationOn');

        // The announcement: only one that has not run yet changes.
        $previousAnnounce = $preparation->getAnnounceOn();
        if (null !== $preparation->getAnnouncementState() && !$preparation->isAnnouncementScheduled()) {
            if (PreparationCalendar::ymd($announceOn) !== PreparationCalendar::ymd($previousAnnounce)) {
                throw PlanningCycleException::conflict('announcement_already_done', 'The announcement of this period already ran: it no longer changes.');
            }
        } elseif (PreparationCalendar::ymd($announceOn) !== PreparationCalendar::ymd($previousAnnounce)) {
            if (null !== $announceOn && $announceOn < $today) {
                throw PlanningCycleException::invalid('announcement_in_past', 'The announcement date is already past.');
            }
            if (null !== $announceOn && $announceOn > $lastDay) {
                throw PlanningCycleException::invalid('announcement_after_period_end', 'The announcement date is after the end of the period.');
            }
            $preparation->scheduleAnnouncement($announceOn, null === $announceOn ? null : PreparationCalendar::runAt($announceOn));
        }

        // The target date of the manual generation.
        if (PreparationCalendar::ymd($target) !== PreparationCalendar::ymd($preparation->getTargetGenerationOn())) {
            if (null !== $target && $target < $today) {
                throw PlanningCycleException::invalid('target_in_past', 'The target generation date is already past.');
            }
            if (null !== $target && $target > $lastDay) {
                throw PlanningCycleException::invalid('target_after_period_end', 'The target generation date is after the end of the period.');
            }
        }
        $effectiveAnnounce = $preparation->getAnnounceOn();
        if (null !== $target && null !== $effectiveAnnounce && $target <= $effectiveAnnounce) {
            throw PlanningCycleException::invalid('target_before_announcement', 'The target generation date must come after the announcement.');
        }
        $preparation->changeTarget($target);

        $this->applyReminders($preparation, $input['reminders'] ?? [], $target, $effectiveAnnounce, $today, $now);
    }

    /**
     * @param list<array{stableId?: ?string, value: mixed, unit: mixed}> $wanted
     */
    private function applyReminders(PeriodPreparation $preparation, array $wanted, ?\DateTimeImmutable $target, ?\DateTimeImmutable $announceOn, \DateTimeImmutable $today, \DateTimeImmutable $now): void
    {
        if (\count($wanted) > self::MAX_REMINDERS) {
            throw PlanningCycleException::invalid('too_many_reminders', \sprintf('At most %d reminders per period.', self::MAX_REMINDERS));
        }
        if ([] !== $wanted && null === $target) {
            throw PlanningCycleException::invalid('reminders_need_target', 'Reminders are placed before the target generation date: set it first.');
        }

        $existing = [];
        foreach ($this->reminderRepository->findByPreparation($preparation) as $reminder) {
            $existing[(string) $reminder->getStableId()] = $reminder;
        }

        $kept = [];
        $days = [];
        $plans = [];
        foreach ($wanted as $entry) {
            $unit = \is_string($entry['unit'] ?? null) ? ReminderOffsetUnit::tryFrom($entry['unit']) : null;
            $value = $entry['value'] ?? null;
            if (null === $unit || !\is_int($value) || $value < 1 || $value > $unit->maximum()) {
                throw PlanningCycleException::invalid('invalid_reminder', 'A reminder is 1 to 365 days, or 1 to 52 weeks, before the target date.');
            }
            /** @var \DateTimeImmutable $target */
            $dueOn = $target->modify(\sprintf('-%d days', $unit->days($value)));
            $id = $entry['stableId'] ?? null;
            $reminder = null;
            if (null !== $id && '' !== $id) {
                $reminder = $existing[$id] ?? throw PlanningCycleException::invalid('reminder_not_found', 'This reminder does not belong to this period.');
                if (!$reminder->isScheduled()) {
                    throw PlanningCycleException::conflict('reminder_not_editable', 'This reminder already ran: it no longer changes.');
                }
                $kept[$id] = true;
            }
            $unchanged = null !== $reminder && $reminder->getDueOn()->format('Y-m-d') === $dueOn->format('Y-m-d');
            if (!$unchanged && $dueOn < $today) {
                throw PlanningCycleException::invalid('reminder_in_past', 'This reminder would fall on a day already past.');
            }
            if (null !== $announceOn && $dueOn <= $announceOn) {
                throw PlanningCycleException::invalid('reminder_before_announcement', 'A reminder comes after the announcement.');
            }
            if (isset($days[$dueOn->format('Y-m-d')])) {
                throw PlanningCycleException::invalid('duplicate_reminder', 'Two reminders fall on the same day.');
            }
            $days[$dueOn->format('Y-m-d')] = true;
            $plans[] = [$reminder, $value, $unit, $dueOn];
        }

        // Removed first, so a remaining one may take a freed day (unique index on the scheduled days).
        foreach ($existing as $id => $reminder) {
            if ($reminder->isScheduled() && !isset($kept[$id])) {
                $this->entityManager->remove($reminder);
            }
        }
        $this->entityManager->flush();
        foreach ($plans as [$reminder, $value, $unit, $dueOn]) {
            if (null !== $reminder) {
                $reminder->reschedule($value, $unit, $dueOn, PreparationCalendar::runAt($dueOn), $now);
            }
        }
        $this->rescheduleWithoutCollision($preparation);
        foreach ($plans as [$reminder, $value, $unit, $dueOn]) {
            if (null === $reminder) {
                $this->entityManager->persist(new PeriodPreparationReminder($preparation, $value, $unit, $dueOn, PreparationCalendar::runAt($dueOn), $now));
            }
        }
    }

    /**
     * Flushes the changed reminders. Two scheduled reminders that exchange
     * their days would briefly collide on the unique index of scheduled days:
     * each changed one is first moved to a far day, then to its own.
     */
    private function rescheduleWithoutCollision(PeriodPreparation $preparation): void
    {
        $uow = $this->entityManager->getUnitOfWork();
        $uow->computeChangeSets();
        $changed = array_values(array_filter(
            $this->reminderRepository->findScheduled($preparation),
            static fn (PeriodPreparationReminder $reminder): bool => [] !== $uow->getEntityChangeSet($reminder),
        ));
        if (\count($changed) < 2) {
            $this->entityManager->flush();

            return;
        }
        $connection = $this->entityManager->getConnection();
        foreach ($changed as $index => $reminder) {
            $connection->executeStatement('UPDATE period_preparation_reminders SET due_on = :parked WHERE id = :id', [
                'parked' => (new \DateTimeImmutable('1900-01-01'))->modify(\sprintf('+%d days', $index))->format('Y-m-d'),
                'id' => $reminder->getId(),
            ]);
        }
        $this->entityManager->flush();
    }

    // --- helpers -------------------------------------------------------------------------------------

    /**
     * @return array{state: string, responded: int, expected: int}
     */
    private function collectionState(PlanningCycle $cycle): array
    {
        $open = $this->openCollections($cycle);
        if ([] === $open) {
            $any = array_filter($this->collectionRepository->findByPlanning($cycle->getPlanning()), static fn (AvailabilityCollection $c): bool => $c->getCycle() === $cycle);

            return ['state' => [] === $any ? 'not_open' : 'closed', 'responded' => 0, 'expected' => 0];
        }
        $expected = [];
        $responded = [];
        foreach ($open as $collection) {
            foreach ($this->responseRepository->findByCollection($collection) as $response) {
                $id = (int) $response->getUser()->getId();
                if (AvailabilityResponseStatus::WITHDRAWN !== $response->getStatus()) {
                    $expected[$id] = true;
                    $responded[$id] = ($responded[$id] ?? true) && AvailabilityResponseStatus::ACKNOWLEDGED === $response->getStatus();
                }
            }
        }

        return ['state' => 'open', 'responded' => \count(array_filter($responded)), 'expected' => \count($expected)];
    }

    private static function date(mixed $value, string $field): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!\is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || false === \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'))
            || \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'))->format('Y-m-d') !== $value) {
            throw PlanningCycleException::invalid('invalid_date', $field.' must be a date (YYYY-MM-DD) or null.');
        }

        return PreparationCalendar::day($value);
    }

    /**
     * @return array{queued: int, sent: int, failed: int, cancelled: int, pending: int}
     */
    private static function noEmails(): array
    {
        return ['queued' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0, 'pending' => 0];
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
