<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PeriodPreparation;
use App\Entity\PeriodPreparationDelivery;
use App\Entity\PeriodPreparationReminder;
use App\Entity\PreparationActionState;
use App\Entity\PreparationDeliveryKind;
use App\Repository\PeriodPreparationDeliveryRepository;
use App\Repository\PeriodPreparationReminderRepository;
use App\Repository\PeriodPreparationRepository;
use App\Security\Voter\PlanningVoter;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authorization\UserAuthorizationCheckerInterface;

/**
 * Executes what the period preparations planned (docs/decisions.md D210) —
 * `app:period-preparation:run`, every 10 minutes from the host crontab, under
 * `flock`. Three steps, each safe to repeat at any time:
 *
 * 1. Announcements due: under the planning's lock and the preparation's row
 *    lock, the period is checked again (not generated, not validated or
 *    published, not over, its manager still allowed); the days of the period
 *    no collection covers get one (an existing collection is never
 *    duplicated) and they take the planned indicative deadline; one
 *    invitation per participant is queued (docs/decisions.md D214: never
 *    filtered on a confirmation). All of it commits together, or nothing
 *    does and the next run starts over.
 * 2. Reminders due: the same checks, plus the target date not passed. When
 *    several reminders of a period are due at once (the server was down),
 *    only the latest one runs; the others are skipped as superseded — never
 *    a burst of stale reminders. Once the generation was launched, every
 *    reminder not sent is cancelled.
 * 3. Emails: each one is claimed (one sender only), checked once more
 *    (account active, person still a participant, generation not
 *    launched…) and sent. A refusal of the transport is retried up to
 *    MAX_ATTEMPTS; a row left SENDING by a run that died is retried after
 *    STALE_CLAIM — the only possible duplicate, SMTP's own uncertainty (as
 *    D172). One announcement per person and period, one email per person and
 *    reminder: the outbox's unique indexes make every run, restart or
 *    concurrent runner queue the same rows.
 *
 * It never closes a collection and never starts a generation.
 */
final class PeriodPreparationRunner
{
    private const STALE_CLAIM = '-15 minutes';

    public function __construct(
        private readonly PeriodPreparationRepository $preparationRepository,
        private readonly PeriodPreparationReminderRepository $reminderRepository,
        private readonly PeriodPreparationDeliveryRepository $deliveryRepository,
        private readonly PeriodPreparationService $preparationService,
        private readonly PlanningCycleService $cycleService,
        private readonly PeriodPreparationMailer $mailer,
        private readonly UserAuthorizationCheckerInterface $authorization,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{announcements: int, reminders: int, skipped: int, sent: int, failed: int, cancelled: int, errors: int}
     */
    public function run(\DateTimeImmutable $now): array
    {
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        // Decided on the database as it is now, never on entities a long-lived process loaded earlier (a role, a status).
        $this->entityManager->clear();
        $report = ['announcements' => 0, 'reminders' => 0, 'skipped' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0, 'errors' => 0];

        foreach ($this->preparationRepository->findDueAnnouncementIds($now) as $id) {
            $this->step($report, fn (): ?string => $this->announce($id, $now), 'announcement');
        }
        foreach ($this->reminderRepository->findPreparationIdsWithDueReminders($now) as $id) {
            $this->step($report, fn (): ?string => $this->remind($id, $now), 'reminder');
        }
        $this->sendDue($now, $report);

        return $report;
    }

    /**
     * @param array<string, int>  $report
     * @param \Closure(): ?string $operation returns 'executed', 'skipped' or null (nothing done)
     */
    private function step(array &$report, \Closure $operation, string $kind): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $result = $operation();
            $this->entityManager->flush();
            $connection->commit();
            if ('executed' === $result) {
                ++$report['announcement' === $kind ? 'announcements' : 'reminders'];
            } elseif ('skipped' === $result) {
                ++$report['skipped'];
            }
        } catch (\Throwable $exception) {
            $connection->rollBack();
            $this->entityManager->clear();
            ++$report['errors'];
            $this->logger->error('Period preparation: the {kind} step failed and will be retried: {message}', ['kind' => $kind, 'message' => $exception->getMessage()]);
        }
    }

    // --- 1. announcements -----------------------------------------------------------------------------

    private function announce(int $id, \DateTimeImmutable $now): ?string
    {
        $preparation = $this->lockPreparation($id);
        if (null === $preparation || !$preparation->isAnnouncementScheduled() || $preparation->getAnnounceAt() > $now) {
            return null;
        }
        if (!$this->cycleService->isCreationEnabled()) {
            // A configuration of the server, not a decision about this period: it stays planned.
            $this->logger->warning('Period preparation: managing periods is disabled, announcement {id} stays planned.', ['id' => $id]);

            return null;
        }

        $cycle = $preparation->getCycle();
        $manager = $preparation->getUpdatedBy();
        $skip = $this->periodBlocker($preparation, $now)
            ?? (!$manager->isActive() || !$this->authorization->isGrantedForUser($manager, PlanningVoter::MANAGE_AVAILABILITY, $cycle->getPlanning()) ? 'manager_not_allowed' : null);
        if (null !== $skip) {
            $preparation->finishAnnouncement(PreparationActionState::SKIPPED, $skip, $now);

            return 'skipped';
        }

        $opened = 0;
        foreach ($this->cycleService->uncoveredWindows($cycle) as $window) {
            $this->cycleService->openCollection($cycle->getPlanning(), $cycle, $manager, null, $window->startsAt, $window->endsAt);
            ++$opened;
        }
        if ([] === $this->preparationService->openCollections($cycle)) {
            // Every day is covered by collections already closed: nothing to announce.
            $preparation->finishAnnouncement(PreparationActionState::SKIPPED, 'collection_closed', $now);

            return 'skipped';
        }
        // docs/decisions.md D214: the collections announced take the planned indicative deadline.
        $deadline = $preparation->getDeclarationDeadlineOn();
        if (null !== $deadline) {
            foreach ($this->preparationService->openCollections($cycle) as $collection) {
                $collection->changeDeadline($deadline, $now);
            }
        }
        foreach ($this->preparationService->participants($cycle) as ['user' => $user]) {
            $this->deliveryRepository->queue($preparation, PreparationDeliveryKind::ANNOUNCEMENT, null, $user, $now);
        }
        $preparation->finishAnnouncement(PreparationActionState::EXECUTED, $opened > 0 ? 'collection_opened' : 'collection_already_open', $now);
        $this->logger->info('Period preparation: period {number} of planning {planning} announced ({opened} collection(s) opened).', [
            'number' => $cycle->getNumber(),
            'planning' => (string) $cycle->getPlanning()->getStableId(),
            'opened' => $opened,
        ]);

        return 'executed';
    }

    // --- 2. reminders ---------------------------------------------------------------------------------

    private function remind(int $id, \DateTimeImmutable $now): ?string
    {
        $preparation = $this->lockPreparation($id);
        if (null === $preparation) {
            return null;
        }
        $due = [];
        foreach ($this->reminderRepository->findScheduled($preparation) as $reminder) {
            $this->entityManager->refresh($reminder);
            if ($reminder->isScheduled() && $reminder->getDueAt() <= $now) {
                $due[] = $reminder;
            }
        }
        if ([] === $due) {
            return null;
        }
        usort($due, static fn (PeriodPreparationReminder $a, PeriodPreparationReminder $b): int => [$a->getDueAt(), $a->getId()] <=> [$b->getDueAt(), $b->getId()]);

        $cycle = $preparation->getCycle();
        if ($this->preparationService->generationStarted($cycle)) {
            $this->preparationService->cancelPlanned($preparation, $now, 'generation_started');

            return 'skipped';
        }
        $target = $preparation->getTargetGenerationOn();
        $skip = $this->periodBlocker($preparation, $now)
            ?? (null === $target || PreparationCalendar::today($now) > $target ? 'target_passed' : null);
        if (null !== $skip) {
            foreach ($due as $reminder) {
                $reminder->finish(PreparationActionState::SKIPPED, $skip, $now);
            }

            return 'skipped';
        }

        $latest = array_pop($due);
        foreach ($due as $reminder) {
            $reminder->finish(PreparationActionState::SKIPPED, 'superseded', $now);
        }
        if ([] === $this->preparationService->openCollections($cycle)) {
            $latest->finish(PreparationActionState::SKIPPED, 'no_open_collection', $now);

            return 'skipped';
        }
        $recipients = $this->preparationService->participants($cycle);
        foreach ($recipients as ['user' => $user]) {
            $this->deliveryRepository->queue($preparation, PreparationDeliveryKind::REMINDER, $latest, $user, $now);
        }
        // docs/decisions.md D214: every participant, never filtered on a confirmation.
        $latest->finish(PreparationActionState::EXECUTED, [] === $recipients ? 'no_participant' : 'sent_to_participants', $now);

        return 'executed';
    }

    // --- 3. emails ------------------------------------------------------------------------------------

    /**
     * @param array<string, int> $report
     */
    private function sendDue(\DateTimeImmutable $now, array &$report): void
    {
        $stale = $now->modify(self::STALE_CLAIM);
        foreach ($this->deliveryRepository->findDue($stale) as $delivery) {
            try {
                if (!$this->deliveryRepository->claim($delivery, $now, $stale)) {
                    continue;
                }
                $reason = $this->noLongerRelevant($delivery, $now);
                if (null !== $reason) {
                    $delivery->cancel($reason);
                    $this->entityManager->flush();
                    ++$report['cancelled'];
                    continue;
                }
                $cycle = $delivery->getCycle();
                $collection = $this->preparationService->participants($cycle)[(int) $delivery->getRecipient()->getId()]['collection'];
                $target = $delivery->getPreparation()->getTargetGenerationOn();
                $sent = PreparationDeliveryKind::ANNOUNCEMENT === $delivery->getKind()
                    ? $this->mailer->sendAnnouncement($delivery->getRecipient(), $cycle, $collection, $target)
                    : $this->mailer->sendReminder($delivery->getRecipient(), $cycle, $collection, $target);
                if ($sent) {
                    $delivery->markSent($now);
                    ++$report['sent'];
                } else {
                    $delivery->markFailed();
                    ++$report['failed'];
                }
                $this->entityManager->flush();
            } catch (\Throwable $exception) {
                // Left SENDING: retried once its claim is stale.
                ++$report['errors'];
                $this->logger->error('Period preparation: email {id} could not be processed: {message}', ['id' => $delivery->getId(), 'message' => $exception->getMessage()]);
                $this->entityManager->clear();
            }
        }
    }

    /** Why an email claimed for sending must not go out any more, or null. */
    private function noLongerRelevant(PeriodPreparationDelivery $delivery, \DateTimeImmutable $now): ?string
    {
        $preparation = $delivery->getPreparation();
        $this->entityManager->refresh($preparation);
        if (!$delivery->getRecipient()->isActive()) {
            return 'account_disabled';
        }
        if ($this->preparationService->generationStarted($delivery->getCycle())) {
            return 'generation_started';
        }
        $blocker = $this->periodBlocker($preparation, $now);
        if (null !== $blocker) {
            return $blocker;
        }
        $reminder = $delivery->getReminder();
        if (null !== $reminder) {
            $this->entityManager->refresh($reminder);
            if (PreparationActionState::CANCELLED === $reminder->getState()) {
                return 'reminder_cancelled';
            }
            $target = $preparation->getTargetGenerationOn();
            if (null === $target || PreparationCalendar::today($now) > $target) {
                return 'target_passed';
            }
        }
        if (!isset($this->preparationService->participants($delivery->getCycle())[(int) $delivery->getRecipient()->getId()])) {
            return 'not_participant';
        }

        return null;
    }

    // --- helpers -------------------------------------------------------------------------------------

    /** The period no longer takes a preparation: validated/published, or over. */
    private function periodBlocker(PeriodPreparation $preparation, \DateTimeImmutable $now): ?string
    {
        $cycle = $preparation->getCycle();
        if ($this->preparationService->generationStarted($cycle)) {
            return 'generation_started';
        }
        if ($this->preparationService->isLocked($cycle)) {
            return 'period_locked';
        }
        if (PreparationCalendar::today($now) > $this->preparationService->lastDay($cycle)) {
            return 'period_over';
        }

        return null;
    }

    /**
     * The planning's lock first (the order of every period operation), then
     * the preparation's row; the entities are read again after both.
     */
    private function lockPreparation(int $id): ?PeriodPreparation
    {
        $preparation = $this->entityManager->find(PeriodPreparation::class, $id);
        if (null === $preparation) {
            return null;
        }
        $this->entityManager->refresh($preparation->getPlanning(), LockMode::PESSIMISTIC_WRITE);
        $this->preparationRepository->lock($id);
        $this->entityManager->refresh($preparation);
        $this->entityManager->refresh($preparation->getCycle());

        return $preparation;
    }
}
