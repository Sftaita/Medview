<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningAvailabilityReminder;
use App\Entity\User;

/**
 * What happened for one recipient. Domain refusals are returned rather than
 * thrown: they happen inside a locked transaction, and throwing there would
 * close the EntityManager (same reasoning as AvailabilityCollectionService::acknowledge()).
 */
final readonly class ReminderOutcome
{
    public function __construct(
        public User $recipient,
        public ReminderStatus $status,
        /** The audit row, when SENT. */
        public ?PlanningAvailabilityReminder $reminder = null,
        /** The reminder that made this one TOO_RECENT. */
        public ?\DateTimeImmutable $previousSentAt = null,
    ) {
    }
}
