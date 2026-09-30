<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Planning;
use App\Entity\PlanningPublication;
use App\Entity\PlanningPublicationKind;
use App\Entity\PlanningPublicationNotification;
use App\Entity\PublicationNotificationStatus;
use App\Repository\PlanningPublicationNotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Sends the emails a PlanningPublication owes (docs/decisions.md D172),
 * from its PlanningPublicationNotification rows — right after the
 * publication commits, then again from `app:publication-notifications:retry`
 * for whatever failed or never went out.
 *
 * - At most once per recipient: every send is preceded by an atomic claim
 *   (PlanningPublicationNotificationRepository::claim), so a double request,
 *   a retry run and the publication request itself can never email the same
 *   person twice; a SENT row is never touched again.
 * - Never a silent loss: a failed send stays FAILED (logged by the mailer,
 *   counted in the publication history) and is retried up to MAX_ATTEMPTS.
 * - Always the same version: the content is the row's frozen `changes`, the
 *   PDF is rendered from the publication's own frozen entries — a retry
 *   hours later sends exactly what the first attempt would have, whatever
 *   was edited meanwhile.
 */
final class PublicationNotificationSender
{
    /** A PENDING row younger than this belongs to the request that is still sending it. */
    private const PENDING_GRACE = '-2 minutes';
    /** A SENDING row older than this belongs to a sender that died mid-send. */
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
        $pdf = null;
        foreach ($notifications as $notification) {
            $this->attempt($notification, $pdf);
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
        /** @var array<int, ?array{0: string, 1: string}> $pdfByPublication */
        $pdfByPublication = [];
        foreach ($due as $notification) {
            $publicationId = (int) $notification->getPublication()->getId();
            $pdfByPublication[$publicationId] ??= null;
            $outcome = $this->attempt($notification, $pdfByPublication[$publicationId]);
            if (null === $outcome) {
                continue;
            }
            ++$report['attempted'];
            ++$report[$outcome ? 'sent' : 'failed'];
        }

        return $report;
    }

    /**
     * @param array{0: string, 1: string}|null $pdf rendered once per publication, on the first real send
     *
     * @return bool|null null when this sender did not get the row (already sent, claimed elsewhere, exhausted)
     */
    private function attempt(PlanningPublicationNotification $notification, ?array &$pdf): ?bool
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
        $pdf ??= [$this->pdfRenderer->render($publication), $this->pdfRenderer->filename($publication)];
        $planning = $publication->getPlanning();

        $sent = PlanningPublicationKind::FIRST === $publication->getKind()
            ? $this->mailer->sendFirstPublication($user, $planning, $this->periodLabel($planning), $pdf[0], $pdf[1])
            : $this->mailer->sendRepublication($user, $planning, $notification->getChanges() ?? [], $pdf[0], $pdf[1]);

        if ($sent) {
            $notification->markSent($this->clock->now());
        } else {
            $notification->markFailed();
        }
        $this->entityManager->flush();

        return $sent;
    }

    /**
     * "du 1er octobre 2026 au 31 décembre 2026" — the planning's own dates (end exclusive).
     */
    private function periodLabel(Planning $planning): string
    {
        return 'du '.FrenchDate::long($planning->getStartsAt()).' au '.FrenchDate::long($planning->getEndsAt()->modify('-1 day'));
    }
}
