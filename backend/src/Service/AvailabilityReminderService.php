<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AvailabilityCollection;
use App\Entity\Planning;
use App\Entity\PlanningAvailabilityReminder;
use App\Entity\PlanningCycle;
use App\Entity\ReminderChannel;
use App\Entity\User;
use App\Repository\AvailabilityCollectionRepository;
use App\Repository\AvailabilityCollectionResponseRepository;
use App\Repository\PlanningAvailabilityReminderRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Sends "please check your calendar" reminders and audits each one
 * (docs/decisions.md D127, D214).
 *
 * - Audience = the participants of the OPEN collections of the planning
 *   (AvailabilityCollectionService::participantResponses()): never filtered
 *   on a confirmation nor on the number of unavailabilities entered —
 *   someone who already entered absences may still add some (D214).
 * - Accidental double sends: the Planning row is locked for the whole
 *   operation (two concurrent requests are serialised, the second sees the
 *   first one's audit row) and a person reminded less than MIN_INTERVAL ago
 *   is skipped as TOO_RECENT. No job infrastructure: a send is synchronous
 *   and small.
 * - The audit row is written only after the transport accepted the message.
 * - Supports many recipients (`remindParticipants`) with the same per-recipient
 *   rules as a single one (`remind`).
 */
final class AvailabilityReminderService
{
    /** A person is not reminded twice within this delay, whoever asks. */
    public const MIN_INTERVAL = 'PT5M';

    public function __construct(
        private readonly AvailabilityCollectionRepository $collectionRepository,
        private readonly AvailabilityCollectionResponseRepository $responseRepository,
        private readonly PlanningAvailabilityReminderRepository $reminderRepository,
        private readonly AvailabilityReminderMailer $mailer,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * $cycle: remind about the collections of this period only
     * (docs/planning-cycles.md §5.9); null: every open collection of the
     * planning. The 5-minute interval between two reminders to the same
     * person stays per planning.
     */
    public function remind(Planning $planning, User $recipient, User $sentBy, ?PlanningCycle $cycle = null): ReminderOutcome
    {
        return $this->entityManager->wrapInTransaction(function () use ($planning, $recipient, $sentBy, $cycle): ReminderOutcome {
            $this->entityManager->refresh($planning, LockMode::PESSIMISTIC_WRITE);

            return $this->deliver($planning, $cycle, $recipient, $sentBy, false);
        });
    }

    /**
     * "Rappeler les participants": one reminder per participant, in name order.
     *
     * @return list<ReminderOutcome>
     */
    public function remindParticipants(Planning $planning, User $sentBy, ?PlanningCycle $cycle = null): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($planning, $sentBy, $cycle): array {
            $this->entityManager->refresh($planning, LockMode::PESSIMISTIC_WRITE);

            $outcomes = [];
            foreach ($this->participants($planning, $cycle) as $recipient) {
                $outcomes[] = $this->deliver($planning, $cycle, $recipient, $sentBy, true);
            }

            return $outcomes;
        });
    }

    private function deliver(Planning $planning, ?PlanningCycle $cycle, User $recipient, User $sentBy, bool $bulk): ReminderOutcome
    {
        $pending = $this->collectionsOf($planning, $cycle, $recipient);
        if ([] === $pending || !$recipient->isActive()) {
            return new ReminderOutcome($recipient, ReminderStatus::NOT_PARTICIPANT);
        }

        $now = $this->now();
        $last = $this->reminderRepository->findLastForRecipient($planning, $recipient);
        if (null !== $last && $last->getSentAt() > $now->sub(new \DateInterval(self::MIN_INTERVAL))) {
            return new ReminderOutcome($recipient, ReminderStatus::TOO_RECENT, previousSentAt: $last->getSentAt());
        }

        if (!$this->mailer->send($recipient, $planning, $pending, $this->latestDeadline($pending))) {
            return new ReminderOutcome($recipient, ReminderStatus::EMAIL_FAILED);
        }

        $reminder = new PlanningAvailabilityReminder($planning, $recipient, $sentBy, ReminderChannel::EMAIL, $bulk, \count($pending), $now);
        // The period reminded about; without one, the period of its collections when they are all of the same one.
        $remindedCycle = $cycle ?? $pending[0]->getCycle();
        if (null !== $remindedCycle && [] === array_filter($pending, static fn (AvailabilityCollection $collection): bool => $collection->getCycle() !== $remindedCycle)) {
            $reminder->assignToCycle($remindedCycle);
        }
        $this->entityManager->persist($reminder);
        $this->entityManager->flush();

        return new ReminderOutcome($recipient, ReminderStatus::SENT, $reminder);
    }

    /**
     * The open collections $recipient takes part in, earliest window first.
     *
     * @return list<AvailabilityCollection>
     */
    private function collectionsOf(Planning $planning, ?PlanningCycle $cycle, User $recipient): array
    {
        $collections = [];
        foreach ($this->openCollections($planning, $cycle) as $collection) {
            $response = $this->responseRepository->findOneForUser($collection, $recipient);
            if (null !== $response && $response->isParticipant()) {
                $collections[] = $collection;
            }
        }

        return $collections;
    }

    /**
     * @return list<User>
     */
    private function participants(Planning $planning, ?PlanningCycle $cycle): array
    {
        $recipients = [];
        foreach ($this->openCollections($planning, $cycle) as $collection) {
            // findByCollection() is ordered by name, so the first collection fixes a stable order.
            foreach ($this->responseRepository->findByCollection($collection) as $response) {
                if ($response->isParticipant()) {
                    $recipients[$response->getUser()->getId()] ??= $response->getUser();
                }
            }
        }

        return array_values($recipients);
    }

    /**
     * @return list<AvailabilityCollection>
     */
    private function openCollections(Planning $planning, ?PlanningCycle $cycle): array
    {
        return array_values(array_filter(
            $this->collectionRepository->findOpenByPlanning($planning),
            static fn (AvailabilityCollection $collection): bool => null === $cycle || $collection->getCycle() === $cycle,
        ));
    }

    /**
     * @param non-empty-list<AvailabilityCollection> $collections
     */
    private function latestDeadline(array $collections): ?\DateTimeImmutable
    {
        $deadline = null;
        foreach ($collections as $collection) {
            $candidate = $collection->getDeadline();
            if (null !== $candidate && (null === $deadline || $candidate > $deadline)) {
                $deadline = $candidate;
            }
        }

        return $deadline;
    }

    /** Always UTC, like every other writer of `datetime_immutable` columns. */
    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
