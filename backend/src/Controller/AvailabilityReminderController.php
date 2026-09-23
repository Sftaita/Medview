<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Planning;
use App\Entity\PlanningTeamMember;
use App\Entity\User;
use App\Repository\PlanningRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\AvailabilityReminderService;
use App\Service\ReminderOutcome;
use App\Service\ReminderStatus;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * "Envoyer un rappel" / "Relancer les membres en attente"
 * (docs/decisions.md D127). Authorization + status-code mapping only: who is
 * reminded, the throttling and the audit row are AvailabilityReminderService.
 *
 * Needs PLANNING_MANAGE_AVAILABILITY. The recipient is never a free
 * parameter: it is a member of *this* planning (a member of another one is a
 * 404), and the body is ignored.
 */
final class AvailabilityReminderController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly AvailabilityReminderService $reminderService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/api/plannings/{planningStableId}/members/{memberStableId}/reminders', name: 'api_planning_member_reminder_create', methods: ['POST'])]
    public function remindMember(string $planningStableId, string $memberStableId, #[CurrentUser] User $user): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->denyUnlessCanManageAvailability($planning);
        $member = $this->resolveMember($planning, $memberStableId);

        $outcome = $this->reminderService->remind($planning, $member->getUser(), $user);

        return match ($outcome->status) {
            ReminderStatus::SENT => new JsonResponse($this->outcomeToArray($outcome), 201),
            ReminderStatus::TOO_RECENT => new JsonResponse([
                'error' => 'reminder_recently_sent',
                'message' => 'A reminder was already sent to this person a moment ago.',
                'lastReminderAt' => $outcome->previousSentAt?->format(\DATE_ATOM),
            ], 409),
            ReminderStatus::NOTHING_PENDING => new JsonResponse([
                'error' => 'nothing_to_remind',
                'message' => 'This person has no unanswered availability collection: there is nothing to remind.',
            ], 409),
            ReminderStatus::EMAIL_FAILED => new JsonResponse([
                'error' => 'email_not_sent',
                'message' => 'The reminder email could not be sent.',
            ], 502),
        };
    }

    /**
     * Everybody still expected to answer, one email each. Always 200 with the
     * per-status counts: a partial result (some recently reminded, some failed)
     * is information, not an error.
     */
    #[Route('/api/plannings/{planningStableId}/reminders/pending', name: 'api_planning_reminders_pending', methods: ['POST'])]
    public function remindPending(string $planningStableId, #[CurrentUser] User $user): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->denyUnlessCanManageAvailability($planning);

        $outcomes = $this->reminderService->remindPending($planning, $user);
        $count = static fn (ReminderStatus $status): int => \count(array_filter($outcomes, static fn (ReminderOutcome $o): bool => $o->status === $status));

        return new JsonResponse([
            'targetedCount' => \count($outcomes),
            'sentCount' => $count(ReminderStatus::SENT),
            'skippedRecentlyCount' => $count(ReminderStatus::TOO_RECENT),
            'failedCount' => $count(ReminderStatus::EMAIL_FAILED),
            'sent' => array_values(array_map(
                fn (ReminderOutcome $o): array => $this->outcomeToArray($o),
                array_filter($outcomes, static fn (ReminderOutcome $o): bool => ReminderStatus::SENT === $o->status),
            )),
        ]);
    }

    private function resolvePlanning(string $stableId): Planning
    {
        $planning = $this->planningRepository->findOneByStableId($stableId);
        if (null === $planning) {
            throw new NotFoundHttpException('Planning not found.');
        }

        return $planning;
    }

    private function denyUnlessCanManageAvailability(Planning $planning): void
    {
        if (!$this->authorizationChecker->isGranted(PlanningVoter::MANAGE_AVAILABILITY, $planning)) {
            throw new AccessDeniedHttpException('Only the creator or an OWNER/ADMIN of this Planning can send availability reminders.');
        }
    }

    private function resolveMember(Planning $planning, string $memberStableId): PlanningTeamMember
    {
        $member = $this->teamMemberRepository->findOneByStableId($memberStableId);
        if (null === $member || $member->getPlanning() !== $planning) {
            throw new NotFoundHttpException('Member not found in this Planning.');
        }

        return $member;
    }

    /**
     * @return array<string, mixed>
     */
    private function outcomeToArray(ReminderOutcome $outcome): array
    {
        $reminder = $outcome->reminder;

        return [
            'stableId' => null !== $reminder ? (string) $reminder->getStableId() : null,
            'userStableId' => (string) $outcome->recipient->getStableId(),
            'sentAt' => $reminder?->getSentAt()->format(\DATE_ATOM),
            'lastReminderAt' => $reminder?->getSentAt()->format(\DATE_ATOM),
            'channel' => $reminder?->getChannel()->value,
            'bulk' => $reminder?->isBulk(),
        ];
    }
}
