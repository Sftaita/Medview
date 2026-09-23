<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AvailabilityCollection;
use App\Entity\AvailabilityResponseStatus;
use App\Entity\Planning;
use App\Entity\PlanningAvailabilityReminder;
use App\Entity\ReminderChannel;
use App\Entity\User;
use App\Repository\AvailabilityCollectionRepository;
use App\Repository\AvailabilityCollectionResponseRepository;
use App\Repository\PlanningAvailabilityReminderRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Sends "please review your availabilities" reminders and audits each one
 * (docs/decisions.md D127).
 *
 * - Audience = people with an unanswered OPEN collection of the planning
 *   (`AvailabilityCollectionResponse` PENDING), exactly what
 *   AvailabilityCollectionService::pendingResponses() defines. Someone who
 *   confirmed "no unavailability" is not reminded; someone with zero
 *   unavailabilities who did not confirm is (D120).
 * - Accidental double sends: the Planning row is locked for the whole
 *   operation (two concurrent requests are serialised, the second sees the
 *   first one's audit row) and a person reminded less than MIN_INTERVAL ago
 *   is skipped as TOO_RECENT. No job infrastructure: a send is synchronous
 *   and small.
 * - The audit row is written only after the transport accepted the message.
 * - Supports many recipients (`remindPending`) with the same per-recipient
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

    public function remind(Planning $planning, User $recipient, User $sentBy): ReminderOutcome
    {
        return $this->entityManager->wrapInTransaction(function () use ($planning, $recipient, $sentBy): ReminderOutcome {
            $this->entityManager->refresh($planning, LockMode::PESSIMISTIC_WRITE);

            return $this->deliver($planning, $recipient, $sentBy, false);
        });
    }

    /**
     * "Relancer les membres en attente": one reminder per person still
     * expected to answer, in name order.
     *
     * @return list<ReminderOutcome>
     */
    public function remindPending(Planning $planning, User $sentBy): array
    {
        return $this->entityManager->wrapInTransaction(function () use ($planning, $sentBy): array {
            $this->entityManager->refresh($planning, LockMode::PESSIMISTIC_WRITE);

            $outcomes = [];
            foreach ($this->pendingRecipients($planning) as $recipient) {
                $outcomes[] = $this->deliver($planning, $recipient, $sentBy, true);
            }

            return $outcomes;
        });
    }

    private function deliver(Planning $planning, User $recipient, User $sentBy, bool $bulk): ReminderOutcome
    {
        $pending = $this->pendingCollections($planning, $recipient);
        if ([] === $pending || !$recipient->isActive()) {
            return new ReminderOutcome($recipient, ReminderStatus::NOTHING_PENDING);
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
        $this->entityManager->persist($reminder);
        $this->entityManager->flush();

        return new ReminderOutcome($recipient, ReminderStatus::SENT, $reminder);
    }

    /**
     * The open collections $recipient has not answered, earliest window first.
     *
     * @return list<AvailabilityCollection>
     */
    private function pendingCollections(Planning $planning, User $recipient): array
    {
        $pending = [];
        foreach ($this->collectionRepository->findOpenByPlanning($planning) as $collection) {
            $response = $this->responseRepository->findOneForUser($collection, $recipient);
            if (null !== $response && AvailabilityResponseStatus::PENDING === $response->getStatus()) {
                $pending[] = $collection;
            }
        }

        return $pending;
    }

    /**
     * @return list<User>
     */
    private function pendingRecipients(Planning $planning): array
    {
        $recipients = [];
        foreach ($this->collectionRepository->findOpenByPlanning($planning) as $collection) {
            // findByCollection() is ordered by name, so the first collection fixes a stable order.
            foreach ($this->responseRepository->findByCollection($collection) as $response) {
                if (AvailabilityResponseStatus::PENDING === $response->getStatus()) {
                    $recipients[$response->getUser()->getId()] ??= $response->getUser();
                }
            }
        }

        return array_values($recipients);
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
