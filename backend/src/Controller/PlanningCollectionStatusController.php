<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\UpdatePlanningSettingsRequest;
use App\Entity\AvailabilityCollectionResponse;
use App\Entity\Planning;
use App\Entity\PlanningAvailabilityReminder;
use App\Entity\PlanningTeamMember;
use App\Entity\UserAvailabilityPeriod;
use App\Exception\InvalidAvailabilityDeadlineException;
use App\Exception\NoOpenAvailabilityCollectionException;
use App\Repository\PlanningRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\AvailabilityCollectionService;
use App\Service\MemberCollectionRow;
use App\Service\PlanningCollectionStatus;
use App\Service\PlanningCollectionStatusService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The OWNER/ADMIN pilot view of a planning's availability collection
 * (docs/availability-collection.md §15, docs/decisions.md D127-D128):
 * collection status of every participant, one participant's detail, and the
 * planning settings. Authorization + status-code mapping only; the read side
 * is PlanningCollectionStatusService, the write side is
 * AvailabilityCollectionService.
 *
 * Everything here needs PLANNING_MANAGE_AVAILABILITY (the creator or a team
 * OWNER/ADMIN); a plain MEMBER gets a 403, and a member id that belongs to
 * another planning is a 404 — never confirmed to exist.
 */
final class PlanningCollectionStatusController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly PlanningCollectionStatusService $statusService,
        private readonly AvailabilityCollectionService $collectionService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/api/plannings/{planningStableId}/collection-status', name: 'api_planning_collection_status', methods: ['GET'])]
    public function status(string $planningStableId): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->denyUnlessCanManageAvailability($planning);

        $status = $this->statusService->status($planning);

        return new JsonResponse([
            'planning' => $this->planningToArray($planning),
            'availabilityDeadline' => $status->availabilityDeadline?->format('Y-m-d'),
            'deadlineOverdueDays' => $status->deadlineOverdueDays,
            'openCollectionCount' => $status->openCollectionCount,
            'summary' => $this->summaryToArray($status),
            'members' => array_map($this->rowToArray(...), $status->rows),
        ]);
    }

    #[Route('/api/plannings/{planningStableId}/members/{memberStableId}/availability-status', name: 'api_planning_member_availability_status', methods: ['GET'])]
    public function memberStatus(string $planningStableId, string $memberStableId): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->denyUnlessCanManageAvailability($planning);
        $member = $this->resolveMember($planning, $memberStableId);

        $detail = $this->statusService->detail($planning, $member);

        return new JsonResponse([
            'member' => $this->rowToArray($detail->row),
            'participation' => [
                'membershipStart' => $member->getMembershipStart()->format('Y-m-d'),
                'membershipEnd' => $member->getMembershipEnd()?->format('Y-m-d'),
                'participationPeriods' => array_map(static fn ($period): array => [
                    'validFrom' => $period->getValidFrom()->format('Y-m-d'),
                    'validTo' => $period->getValidTo()?->format('Y-m-d'),
                    'participationFactor' => $period->toFloat(),
                ], $detail->participationPeriods),
                'nonParticipationPeriods' => array_map(static fn ($period): array => [
                    'stableId' => (string) $period->getStableId(),
                    'startsAt' => $period->getStartsAt()->format(\DATE_ATOM),
                    'endsAt' => $period->getEndsAt()->format(\DATE_ATOM),
                ], $detail->nonParticipationPeriods),
            ],
            'collections' => array_map(static fn (AvailabilityCollectionResponse $response): array => [
                'collectionStableId' => (string) $response->getCollection()->getStableId(),
                'startsAt' => $response->getCollection()->getStartsAt()->format('Y-m-d'),
                'lastDay' => $response->getCollection()->getEndsAt()->modify('-1 day')->format('Y-m-d'),
                'collectionStatus' => $response->getCollection()->getStatus()->value,
                'status' => $response->getStatus()->value,
                'acknowledgedAt' => $response->getAcknowledgedAt()?->format(\DATE_ATOM),
                'acknowledgementKind' => $response->getAcknowledgementKind()?->value,
            ], $detail->responses),
            // Read live from the person's own calendar, limited to the planning period — never copied per planning.
            'unavailabilities' => array_map($this->periodToArray(...), $detail->unavailabilities),
            'preferences' => array_map($this->periodToArray(...), $detail->preferences),
            'reminders' => array_map(static fn (PlanningAvailabilityReminder $reminder): array => [
                'stableId' => (string) $reminder->getStableId(),
                'sentAt' => $reminder->getSentAt()->format(\DATE_ATOM),
                'sentByName' => trim($reminder->getSentBy()->getFirstName().' '.$reminder->getSentBy()->getLastName()),
                'channel' => $reminder->getChannel()->value,
                'bulk' => $reminder->isBulk(),
            ], $detail->reminders),
        ]);
    }

    /**
     * `{"availabilityDeadline": "YYYY-MM-DD" | null}`. Informative only: it
     * sets the deadline of every open collection and gates nothing.
     */
    #[Route('/api/plannings/{planningStableId}/settings', name: 'api_planning_settings_update', methods: ['PATCH'])]
    public function updateSettings(string $planningStableId, Request $request): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->denyUnlessCanManageAvailability($planning);

        try {
            $raw = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body is not valid JSON.'], 400);
        }
        if (!\is_array($raw)) {
            return new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body must be a JSON object.'], 400);
        }

        try {
            $dto = $this->serializer->deserialize(
                $request->getContent(),
                UpdatePlanningSettingsRequest::class,
                'json',
                // Unknown fields are rejected, never silently dropped (D116).
                [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false],
            );
        } catch (ExtraAttributesException $exception) {
            return UnknownFieldsResponse::from($exception);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'validation_failed', 'violations' => ['availabilityDeadline' => 'This value must be a date (YYYY-MM-DD) or null.']], 422);
        }

        if (!\array_key_exists('availabilityDeadline', $raw)) {
            return new JsonResponse(['error' => 'validation_failed', 'violations' => ['availabilityDeadline' => 'This field is required (null clears the deadline).']], 422);
        }

        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return new JsonResponse(['error' => 'validation_failed', 'violations' => $errors], 422);
        }

        $deadline = null;
        if (null !== $dto->availabilityDeadline) {
            $deadline = \DateTimeImmutable::createFromFormat('!Y-m-d', $dto->availabilityDeadline);
            if (false === $deadline) {
                return new JsonResponse(['error' => 'validation_failed', 'violations' => ['availabilityDeadline' => 'This value is not a valid date (expected YYYY-MM-DD).']], 422);
            }
        }

        try {
            $this->collectionService->changePlanningDeadline($planning, $deadline);
        } catch (NoOpenAvailabilityCollectionException $exception) {
            return new JsonResponse(['error' => 'no_open_collection', 'message' => $exception->getMessage()], 409);
        } catch (InvalidAvailabilityDeadlineException $exception) {
            return new JsonResponse(['error' => 'validation_failed', 'violations' => ['availabilityDeadline' => $exception->getMessage()]], 422);
        }

        $status = $this->statusService->status($planning);

        return new JsonResponse([
            'availabilityDeadline' => $status->availabilityDeadline?->format('Y-m-d'),
            'deadlineOverdueDays' => $status->deadlineOverdueDays,
            'openCollectionCount' => $status->openCollectionCount,
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
            throw new AccessDeniedHttpException('Only the creator or an OWNER/ADMIN of this Planning can follow its availability collection.');
        }
    }

    private function resolveMember(Planning $planning, string $memberStableId): PlanningTeamMember
    {
        $member = $this->teamMemberRepository->findOneByStableId($memberStableId);

        // 404 (never 403) when the member exists but belongs to another planning: nothing is confirmed.
        if (null === $member || $member->getPlanning() !== $planning) {
            throw new NotFoundHttpException('Member not found in this Planning.');
        }

        return $member;
    }

    /**
     * @return array<string, mixed>
     */
    private function planningToArray(Planning $planning): array
    {
        return [
            'stableId' => (string) $planning->getStableId(),
            'name' => $planning->getName(),
            'startsAt' => $planning->getStartsAt()->format('Y-m-d'),
            // endsAt is exclusive, like everywhere else; lastDay is the inclusive form for display.
            'endsAt' => $planning->getEndsAt()->format('Y-m-d'),
            'lastDay' => $planning->getEndsAt()->modify('-1 day')->format('Y-m-d'),
            'timezone' => $planning->getTimezone(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function summaryToArray(PlanningCollectionStatus $status): array
    {
        return [
            'participantCount' => $status->participantCount(),
            'expectedCount' => $status->expectedCount(),
            'confirmedCount' => $status->confirmedCount(),
            'pendingCount' => $status->pendingCount(),
            'notExpectedCount' => $status->notExpectedCount(),
            'unavailabilityCount' => $status->unavailabilityCount(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowToArray(MemberCollectionRow $row): array
    {
        $member = $row->member;
        $user = $member->getUser();

        return [
            'memberStableId' => (string) $member->getStableId(),
            'userStableId' => (string) $user->getStableId(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'role' => $member->getRole()->value,
            'team' => ['stableId' => (string) $member->getPlanningTeam()->getStableId(), 'name' => $member->getPlanningTeam()->getName()],
            'membershipEnd' => $member->getMembershipEnd()?->format('Y-m-d'),
            // PENDING / ACKNOWLEDGED (shown "Confirmé") / NOT_EXPECTED — from explicit answers only (D120, D128).
            'collectionState' => $row->state->value,
            'acknowledgedAt' => $row->acknowledgedAt?->format(\DATE_ATOM),
            'acknowledgementKind' => $row->acknowledgementKind?->value,
            'lastAvailabilityChangeAt' => $row->lastAvailabilityChangeAt?->format(\DATE_ATOM),
            'pendingCollectionCount' => $row->pendingCollectionCount,
            'unavailabilityCount' => $row->unavailabilityCount,
            'lastReminderAt' => $row->lastReminderAt?->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function periodToArray(UserAvailabilityPeriod $period): array
    {
        return [
            'stableId' => (string) $period->getStableId(),
            'type' => $period->getType()->value,
            'startsAt' => $period->getStartsAt()->format(\DATE_ATOM),
            'endsAt' => $period->getEndsAt()->format(\DATE_ATOM),
        ];
    }
}
