<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DutySwapNotification;
use App\Entity\DutySwapNotificationStatus;
use App\Repository\DutySwapNotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Sends the swap workflow's emails from their DutySwapNotification outbox
 * rows (docs/duty-swaps.md §8) — right after the step that owes them has
 * COMMITTED, then again from `app:duty-swaps:maintain` for whatever failed
 * or never went out. The same guarantees as the publication emails
 * (PublicationNotificationSender, D172/D173), and the same honest limits:
 *
 * - never two senders for one email (atomic claim), never a SENT row again;
 * - no silent loss: a failure stays FAILED with its error and attempt time,
 *   retried up to MAX_ATTEMPTS; a row left PENDING/SENDING by a dead process
 *   is retried too;
 * - NOT exactly once: when SMTP accepted a message but the process died
 *   before recording SENT, the retry sends it again — a possible duplicate,
 *   chosen over a possible loss. SENT means "accepted by the SMTP server",
 *   never "read".
 *
 * An email failure never undoes anything: the swap is already committed
 * when the first attempt starts.
 */
final class DutySwapNotificationSender
{
    /** A PENDING row younger than this belongs to the request that is still sending it. */
    private const PENDING_GRACE = '-2 minutes';
    /** A SENDING row older than this belongs to a sender that died mid-send. */
    private const STALE_CLAIM = '-15 minutes';

    public function __construct(
        private readonly DutySwapNotificationRepository $notificationRepository,
        private readonly DutySwapMailer $mailer,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The emails a step just recorded — called after its commit only.
     *
     * @param list<DutySwapNotification> $notifications
     */
    public function sendNow(array $notifications): void
    {
        foreach ($notifications as $notification) {
            $this->attempt($notification);
        }
    }

    /**
     * @return array{attempted: int, sent: int, failed: int}
     */
    public function retryDue(): array
    {
        $now = $this->clock->now();
        $report = ['attempted' => 0, 'sent' => 0, 'failed' => 0];
        foreach ($this->notificationRepository->findDue($now->modify(self::PENDING_GRACE), $now->modify(self::STALE_CLAIM)) as $notification) {
            $outcome = $this->attempt($notification);
            if (null === $outcome) {
                continue;
            }
            ++$report['attempted'];
            ++$report[$outcome ? 'sent' : 'failed'];
        }

        return $report;
    }

    /**
     * @return bool|null null when this sender did not get the row (already sent, claimed elsewhere, exhausted, cancelled)
     */
    private function attempt(DutySwapNotification $notification): ?bool
    {
        $now = $this->clock->now();
        if (!$this->notificationRepository->claim($notification, $now, $now->modify(self::STALE_CLAIM))) {
            return null;
        }

        if (!$notification->getRecipient()->isActive()) {
            $notification->cancel();
            $this->entityManager->flush();

            return null;
        }

        $error = $this->mailer->send($notification);
        if (null === $error) {
            $notification->markSent($this->clock->now());
        } else {
            $notification->markFailed($this->clock->now(), $error);
        }
        $this->entityManager->flush();

        return null === $error && DutySwapNotificationStatus::SENT === $notification->getStatus();
    }
}
