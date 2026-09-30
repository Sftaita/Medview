<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlanningPublication;
use App\Entity\PlanningPublicationKind;
use App\Entity\PlanningPublicationNotification;
use App\Entity\PublicationNotificationStatus;
use App\Repository\PlanningPublicationNotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Sends the emails a PlanningPublication owes (docs/decisions.md D172,
 * D173), from its PlanningPublicationNotification rows — right after the
 * publication commits, then again from `app:publication-notifications:retry`
 * for whatever failed or never went out.
 *
 * What is guaranteed, and what is not:
 * - No concurrent sends, no duplicate from a repeated request: every send
 *   is preceded by an atomic claim (PlanningPublicationNotificationRepository::claim),
 *   so a double click, a replayed request, overlapping retry runs and the
 *   publication request itself never send the same email in parallel, and a
 *   SENT row is never sent again.
 * - No silent loss: a failed send stays FAILED (logged by the mailer, counted
 *   in the publication history) and is retried up to MAX_ATTEMPTS; a row left
 *   PENDING or SENDING by a process that died is retried too.
 * - NOT exactly once: SMTP gives no way to know whether a message was
 *   delivered when the conversation is cut after the server accepted it but
 *   before this side recorded SENT (process killed, connection lost while
 *   reading the reply, database unavailable for the final write). Such a row
 *   is FAILED or stays SENDING, and the retry sends it again — a possible
 *   duplicate, chosen over a possible loss.
 * - Always the same version: the content is the row's frozen `changes`, the
 *   PDF, the planning's name and period are the ones stored with the
 *   publication (PublishedDocument) — a retry hours later sends exactly what
 *   the first attempt would have, whatever was edited or extended meanwhile.
 */
final class PublicationNotificationSender
{
    /** A PENDING row younger than this belongs to the request that is still sending it. */
    private const PENDING_GRACE = '-2 minutes';
    /** A SENDING row older than this belongs to a sender that died mid-send (an SMTP attempt never lasts that long). */
    private const STALE_CLAIM = '-15 minutes';

    public function __construct(
        private readonly PlanningPublicationNotificationRepository $notificationRepository,
        private readonly PlanningPdfRenderer $pdfRenderer,
        private readonly PlanningPublicationMailer $mailer,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Every email of one publication, right after it is recorded.
     *
     * @return array{recipientCount: int, sentCount: int}
     */
    public function sendForPublication(PlanningPublication $publication): array
    {
        $notifications = $this->notificationRepository->findByPublication($publication);
        $document = null;
        foreach ($notifications as $notification) {
            $this->attempt($notification, $document);
        }

        return [
            'recipientCount' => \count($notifications),
            'sentCount' => \count(array_filter($notifications, static fn (PlanningPublicationNotification $n): bool => PublicationNotificationStatus::SENT === $n->getStatus())),
        ];
    }

    /**
     * Whatever is due again (FAILED, PENDING left behind, SENDING abandoned).
     *
     * @return array{attempted: int, sent: int, failed: int}
     */
    public function retryDue(): array
    {
        $now = $this->clock->now();
        $due = $this->notificationRepository->findDue($now->modify(self::PENDING_GRACE), $now->modify(self::STALE_CLAIM));

        $report = ['attempted' => 0, 'sent' => 0, 'failed' => 0];
        /** @var array<int, ?PublishedDocument> $documentByPublication */
        $documentByPublication = [];
        foreach ($due as $notification) {
            $publicationId = (int) $notification->getPublication()->getId();
            $documentByPublication[$publicationId] ??= null;
            $outcome = $this->attempt($notification, $documentByPublication[$publicationId]);
            if (null === $outcome) {
                continue;
            }
            ++$report['attempted'];
            ++$report[$outcome ? 'sent' : 'failed'];
        }

        return $report;
    }

    /**
     * @param PublishedDocument|null $document loaded once per publication, on the first real send
     *
     * @return bool|null null when this sender did not get the row (already sent, claimed elsewhere, exhausted)
     */
    private function attempt(PlanningPublicationNotification $notification, ?PublishedDocument &$document): ?bool
    {
        $now = $this->clock->now();
        if (!$this->notificationRepository->claim($notification, $now, $now->modify(self::STALE_CLAIM))) {
            return null;
        }

        $user = $notification->getUser();
        if (!$user->isActive()) {
            $notification->cancel();
            $this->entityManager->flush();

            return null;
        }

        $publication = $notification->getPublication();
        $document ??= $this->pdfRenderer->pdfOf($publication);
        $planning = $publication->getPlanning();

        $sent = PlanningPublicationKind::FIRST === $publication->getKind()
            ? $this->mailer->sendFirstPublication($user, $planning, $document)
            : $this->mailer->sendRepublication($user, $planning, $notification->getChanges() ?? [], $document);

        if ($sent) {
            $notification->markSent($this->clock->now());
        } else {
            $notification->markFailed();
        }
        $this->entityManager->flush();

        return $sent;
    }
}
