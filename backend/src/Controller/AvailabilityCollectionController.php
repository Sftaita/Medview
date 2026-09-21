<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreateAvailabilityCollectionRequest;
use App\Dto\UpdateAvailabilityCollectionRequest;
use App\Entity\AvailabilityAcknowledgementKind;
use App\Entity\AvailabilityCollection;
use App\Entity\AvailabilityCollectionResponse;
use App\Entity\AvailabilityResponseStatus;
use App\Entity\Planning;
use App\Entity\User;
use App\Exception\AvailabilityCollectionClosedException;
use App\Exception\AvailabilityCollectionOutsidePlanningException;
use App\Exception\AvailabilityCollectionOverlapException;
use App\Exception\ConflictingUnavailabilityException;
use App\Exception\InvalidAvailabilityDeadlineException;
use App\Exception\NotAnAvailabilityRespondentException;
use App\Repository\AvailabilityCollectionRepository;
use App\Repository\AvailabilityCollectionResponseRepository;
use App\Repository\PlanningRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\AvailabilityCollectionService;
use App\Service\DateWindow;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Availability collections of a Planning (docs/availability-collection.md,
 * D120-D124): "who has reviewed their availabilities for this slice?".
 * Authorization only + status-code mapping here; every rule lives in
 * AvailabilityCollectionService.
 *
 * Who sees what: anyone who can VIEW the planning sees its collections and
 * their *own* answer; only PLANNING_MANAGE_AVAILABILITY (the creator or a
 * team OWNER/ADMIN) sees the counters and everybody's answers, and may
 * open / re-date / close a collection. Acknowledging is always and only
 * for the current user: the target is never a parameter.
 */
final class AvailabilityCollectionController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly AvailabilityCollectionRepository $collectionRepository,
        private readonly AvailabilityCollectionResponseRepository $responseRepository,
        private readonly AvailabilityCollectionService $service,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/api/plannings/{planningStableId}/availability-collections', name: 'api_availability_collection_list', methods: ['GET'])]
    public function list(string $planningStableId, #[CurrentUser] User $user): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->denyUnlessCanView($planning);

        return new JsonResponse(array_map(
            fn (AvailabilityCollection $collection): array => $this->collectionToArray($collection, $user),
            $this->collectionRepository->findByPlanning($planning),
        ));
    }

    #[Route('/api/plannings/{planningStableId}/availability-collections', name: 'api_availability_collection_create', methods: ['POST'])]
    public function create(string $planningStableId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->denyUnlessCanManageAvailability($planning);

        $raw = $this->decodeBody($request);
        if ($raw instanceof JsonResponse) {
            return $raw;
        }

        $dto = new CreateAvailabilityCollectionRequest();
        $dto->startsAt = (string) ($raw['startsAt'] ?? '');
        $dto->endsAt = (string) ($raw['endsAt'] ?? '');
        $dto->deadline = (string) ($raw['deadline'] ?? '');
        $invalid = $this->validationErrors($dto);
        if (null !== $invalid) {
            return $invalid;
        }

        $errors = [];
        $startsAt = $this->parseDate($dto->startsAt, 'startsAt', $errors);
        $endsAt = $this->parseDate($dto->endsAt, 'endsAt', $errors);
        $deadline = $this->parseOptionalDate($dto->deadline, 'deadline', $errors);
        if ([] === $errors && $endsAt <= $startsAt) {
            $errors['endsAt'] = 'endsAt must be strictly after startsAt.';
        }
        if ([] !== $errors) {
            return new JsonResponse(['error' => 'validation_failed', 'violations' => $errors], 422);
        }

        try {
            $collection = $this->service->open($planning, new DateWindow($startsAt, $endsAt), $user, $deadline);
        } catch (AvailabilityCollectionOutsidePlanningException $exception) {
            return new JsonResponse(['error' => 'window_outside_planning', 'message' => $exception->getMessage()], 422);
        } catch (AvailabilityCollectionOverlapException $exception) {
            return new JsonResponse(['error' => 'collection_window_overlap', 'message' => $exception->getMessage()], 409);
        } catch (InvalidAvailabilityDeadlineException $exception) {
            return new JsonResponse(['error' => 'validation_failed', 'violations' => ['deadline' => $exception->getMessage()]], 422);
        }

        return new JsonResponse($this->collectionToArray($collection, $user), 201);
    }

    #[Route('/api/availability-collections/{stableId}', name: 'api_availability_collection_get', methods: ['GET'])]
    public function get(string $stableId, #[CurrentUser] User $user): JsonResponse
    {
        $collection = $this->resolveCollection($stableId);
        $this->denyUnlessCanView($collection->getPlanning());

        return new JsonResponse($this->collectionToArray($collection, $user));
    }

    #[Route('/api/availability-collections/{stableId}', name: 'api_availability_collection_update', methods: ['PATCH'])]
    public function update(string $stableId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $collection = $this->resolveCollection($stableId);
        $this->denyUnlessCanManageAvailability($collection->getPlanning());

        $raw = $this->decodeBody($request);
        if ($raw instanceof JsonResponse) {
            return $raw;
        }
        if (!\array_key_exists('deadline', $raw)) {
            return new JsonResponse(['error' => 'validation_failed', 'violations' => ['deadline' => 'This field is required (null clears the deadline).']], 422);
        }

        $dto = new UpdateAvailabilityCollectionRequest();
        $dto->deadline = (string) ($raw['deadline'] ?? '');
        $errors = [];
        $deadline = $this->parseOptionalDate($dto->deadline, 'deadline', $errors);
        if ([] !== $errors) {
            return new JsonResponse(['error' => 'validation_failed', 'violations' => $errors], 422);
        }

        try {
            $this->service->changeDeadline($collection, $deadline);
        } catch (AvailabilityCollectionClosedException $exception) {
            return new JsonResponse(['error' => 'collection_closed', 'message' => $exception->getMessage()], 409);
        } catch (InvalidAvailabilityDeadlineException $exception) {
            return new JsonResponse(['error' => 'validation_failed', 'violations' => ['deadline' => $exception->getMessage()]], 422);
        }

        return new JsonResponse($this->collectionToArray($collection, $user));
    }

    #[Route('/api/availability-collections/{stableId}/close', name: 'api_availability_collection_close', methods: ['POST'])]
    public function close(string $stableId, #[CurrentUser] User $user): JsonResponse
    {
        $collection = $this->resolveCollection($stableId);
        $this->denyUnlessCanManageAvailability($collection->getPlanning());

        $this->service->close($collection);

        return new JsonResponse($this->collectionToArray($collection, $user));
    }

    /**
     * Everybody's answer — managers only. `?status=PENDING` returns exactly
     * the people still expected to answer: the audience of a future reminder.
     */
    #[Route('/api/availability-collections/{stableId}/responses', name: 'api_availability_collection_responses', methods: ['GET'])]
    public function responses(string $stableId, Request $request): JsonResponse
    {
        $collection = $this->resolveCollection($stableId);
        $this->denyUnlessCanManageAvailability($collection->getPlanning());

        $status = null;
        $rawStatus = $request->query->get('status');
        if (null !== $rawStatus && '' !== $rawStatus) {
            $status = AvailabilityResponseStatus::tryFrom((string) $rawStatus);
            if (null === $status) {
                return new JsonResponse(['error' => 'validation_failed', 'violations' => ['status' => 'Must be one of PENDING, ACKNOWLEDGED, WITHDRAWN.']], 422);
            }
        }

        $responses = $this->responseRepository->findByCollection($collection);
        if (null !== $status) {
            $responses = array_values(array_filter(
                $responses,
                static fn (AvailabilityCollectionResponse $response): bool => $response->getStatus() === $status,
            ));
        }

        return new JsonResponse(array_map($this->responseToArray(...), $responses));
    }

    /**
     * The current user's own confirmation, nobody else's. Body (optional):
     * `{"noUnavailability": true}` for "I have no unavailability on this
     * period". Idempotent: a second call returns the first confirmation.
     */
    #[Route('/api/availability-collections/{stableId}/acknowledge', name: 'api_availability_collection_acknowledge', methods: ['POST'])]
    public function acknowledge(string $stableId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $collection = $this->resolveCollection($stableId);
        $this->denyUnlessCanView($collection->getPlanning());

        $raw = '' === $request->getContent() ? [] : $this->decodeBody($request);
        if ($raw instanceof JsonResponse) {
            return $raw;
        }
        $kind = true === ($raw['noUnavailability'] ?? false)
            ? AvailabilityAcknowledgementKind::NO_UNAVAILABILITY
            : AvailabilityAcknowledgementKind::CONFIRMED;

        try {
            $this->service->acknowledge($collection, $user, $kind);
        } catch (NotAnAvailabilityRespondentException $exception) {
            return new JsonResponse(['error' => 'not_a_respondent', 'message' => $exception->getMessage()], 403);
        } catch (AvailabilityCollectionClosedException $exception) {
            return new JsonResponse(['error' => 'collection_closed', 'message' => $exception->getMessage()], 409);
        } catch (ConflictingUnavailabilityException $exception) {
            return new JsonResponse([
                'error' => 'unavailability_exists',
                'message' => $exception->getMessage(),
                'unavailablePeriodCount' => $exception->unavailablePeriodCount,
            ], 409);
        }

        return new JsonResponse($this->collectionToArray($collection, $user));
    }

    /**
     * The OPEN collections the current user is expected to answer (or has
     * answered) — what the dashboard shows. Never someone else's.
     */
    #[Route('/api/me/availability-collections', name: 'api_me_availability_collections', methods: ['GET'])]
    public function mine(#[CurrentUser] User $user): JsonResponse
    {
        $collections = array_map(
            static fn (AvailabilityCollectionResponse $response): AvailabilityCollection => $response->getCollection(),
            $this->responseRepository->findActiveInOpenCollectionsForUser($user),
        );

        return new JsonResponse(array_map(
            fn (AvailabilityCollection $collection): array => $this->collectionToArray($collection, $user, withProgress: false),
            $collections,
        ));
    }

    private function resolvePlanning(string $stableId): Planning
    {
        $planning = $this->planningRepository->findOneByStableId($stableId);
        if (null === $planning) {
            throw new NotFoundHttpException('Planning not found.');
        }

        return $planning;
    }

    private function resolveCollection(string $stableId): AvailabilityCollection
    {
        $collection = $this->collectionRepository->findOneByStableId($stableId);
        if (null === $collection) {
            throw new NotFoundHttpException('Availability collection not found.');
        }

        return $collection;
    }

    private function denyUnlessCanView(Planning $planning): void
    {
        if (!$this->authorizationChecker->isGranted(PlanningVoter::VIEW, $planning)) {
            throw new AccessDeniedHttpException('You do not have access to this Planning.');
        }
    }

    private function denyUnlessCanManageAvailability(Planning $planning): void
    {
        if (!$this->authorizationChecker->isGranted(PlanningVoter::MANAGE_AVAILABILITY, $planning)) {
            throw new AccessDeniedHttpException('Only the creator or an OWNER/ADMIN of this Planning can manage its availability collections.');
        }
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function decodeBody(Request $request): array|JsonResponse
    {
        try {
            $raw = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body is not valid JSON.'], 400);
        }

        if (!\is_array($raw)) {
            return new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body must be a JSON object.'], 400);
        }

        return $raw;
    }

    private function validationErrors(object $dto): ?JsonResponse
    {
        $violations = $this->validator->validate($dto);
        if (0 === \count($violations)) {
            return null;
        }

        $errors = [];
        foreach ($violations as $violation) {
            $errors[$violation->getPropertyPath()] = $violation->getMessage();
        }

        return new JsonResponse(['error' => 'validation_failed', 'violations' => $errors], 422);
    }

    /**
     * @param array<string, string> $errors
     */
    private function parseDate(string $value, string $field, array &$errors): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            $errors[$field] = 'This value is not a valid date (expected YYYY-MM-DD).';

            return new \DateTimeImmutable('1970-01-01');
        }

        return $date;
    }

    /**
     * @param array<string, string> $errors
     */
    private function parseOptionalDate(string $value, string $field, array &$errors): ?\DateTimeImmutable
    {
        return '' === $value ? null : $this->parseDate($value, $field, $errors);
    }

    /**
     * $withProgress: the X/Y counters are a manager's view; a member never
     * gets them (D124). The caller's own answer is always included.
     *
     * @return array<string, mixed>
     */
    private function collectionToArray(AvailabilityCollection $collection, User $user, bool $withProgress = true): array
    {
        $planning = $collection->getPlanning();
        $canManage = $this->authorizationChecker->isGranted(PlanningVoter::MANAGE_AVAILABILITY, $planning);
        $mine = $this->responseRepository->findOneForUser($collection, $user);

        $data = [
            'stableId' => (string) $collection->getStableId(),
            'planningStableId' => (string) $planning->getStableId(),
            'planningName' => $planning->getName(),
            'startsAt' => $collection->getStartsAt()->format('Y-m-d'),
            // endsAt is exclusive, like Planning::$endsAt; lastDay is the inclusive form for display.
            'endsAt' => $collection->getEndsAt()->format('Y-m-d'),
            'lastDay' => $collection->getEndsAt()->modify('-1 day')->format('Y-m-d'),
            'openedAt' => $collection->getOpenedAt()->format(\DATE_ATOM),
            'deadline' => $collection->getDeadline()?->format('Y-m-d'),
            'status' => $collection->getStatus()->value,
            'closedAt' => $collection->getClosedAt()?->format(\DATE_ATOM),
            'timezone' => $planning->getTimezone(),
            'canManage' => $canManage,
            'myResponse' => null !== $mine ? $this->responseToArray($mine, withUser: false) : null,
        ];

        if ($withProgress && $canManage) {
            $data['progress'] = $this->service->progress($collection);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function responseToArray(AvailabilityCollectionResponse $response, bool $withUser = true): array
    {
        $data = [
            'stableId' => (string) $response->getStableId(),
            'status' => $response->getStatus()->value,
            'acknowledgedAt' => $response->getAcknowledgedAt()?->format(\DATE_ATOM),
            'acknowledgementKind' => $response->getAcknowledgementKind()?->value,
            'lastAvailabilityChangeAt' => $response->getLastAvailabilityChangeAt()?->format(\DATE_ATOM),
        ];

        if ($withUser) {
            $user = $response->getUser();
            $data['user'] = [
                'stableId' => (string) $user->getStableId(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
            ];
        }

        return $data;
    }
}
