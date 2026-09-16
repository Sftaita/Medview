<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\UpsertUserAvailabilityPeriodRequest;
use App\Entity\User;
use App\Entity\UserAvailabilityPeriod;
use App\Exception\OverlappingUserAvailabilityPeriodException;
use App\Repository\UserAvailabilityPeriodRepository;
use App\Service\UserAvailabilityService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * A User's own personal calendar (UNAVAILABLE / PREFER_DUTY —
 * docs/availability.md). Deliberately never accepts another User as a
 * parameter: every action here is implicitly scoped to #[CurrentUser], so
 * this endpoint needs no Voter — a user can only ever reach their own rows
 * (D057, see also TeamRoleVoter's docblock).
 */
final class PersonalCalendarController
{
    public function __construct(
        private readonly UserAvailabilityPeriodRepository $repository,
        private readonly UserAvailabilityService $service,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/api/me/calendar', name: 'api_me_calendar_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $periods = $this->repository->findByUser($user);

        return new JsonResponse(array_map($this->toArray(...), $periods));
    }

    #[Route('/api/me/calendar', name: 'api_me_calendar_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        [$dto, $errorResponse] = $this->deserializeAndValidate($request);
        if (null !== $errorResponse) {
            return $errorResponse;
        }

        [$startsAt, $endsAt, $dateErrorResponse] = $this->parseDates($dto->startsAt, $dto->endsAt);
        if (null !== $dateErrorResponse) {
            return $dateErrorResponse;
        }

        try {
            $period = $this->service->create($user, $dto->typeEnum(), $startsAt, $endsAt);
        } catch (OverlappingUserAvailabilityPeriodException $exception) {
            return new JsonResponse(['error' => 'overlapping_period', 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse($this->toArray($period), 201);
    }

    #[Route('/api/me/calendar/{stableId}', name: 'api_me_calendar_update', methods: ['PATCH'])]
    public function update(string $stableId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $period = $this->findOwnPeriod($stableId, $user);

        [$dto, $errorResponse] = $this->deserializeAndValidate($request);
        if (null !== $errorResponse) {
            return $errorResponse;
        }

        [$startsAt, $endsAt, $dateErrorResponse] = $this->parseDates($dto->startsAt, $dto->endsAt);
        if (null !== $dateErrorResponse) {
            return $dateErrorResponse;
        }

        try {
            $this->service->reschedule($period, $dto->typeEnum(), $startsAt, $endsAt);
        } catch (OverlappingUserAvailabilityPeriodException $exception) {
            return new JsonResponse(['error' => 'overlapping_period', 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse($this->toArray($period));
    }

    #[Route('/api/me/calendar/{stableId}', name: 'api_me_calendar_delete', methods: ['DELETE'])]
    public function delete(string $stableId, #[CurrentUser] User $user): Response
    {
        $period = $this->findOwnPeriod($stableId, $user);
        $this->service->delete($period);

        return new Response(status: 204);
    }

    private function findOwnPeriod(string $stableId, User $user): UserAvailabilityPeriod
    {
        $period = $this->repository->findOneByStableId($stableId);

        // 404 (not 403) whether the row doesn't exist or belongs to someone
        // else — never confirms another user's calendar entry exists.
        if (null === $period || $period->getUser() !== $user) {
            throw new NotFoundHttpException('Calendar period not found.');
        }

        return $period;
    }

    /**
     * @return array{0: ?UpsertUserAvailabilityPeriodRequest, 1: ?JsonResponse}
     */
    private function deserializeAndValidate(Request $request): array
    {
        try {
            /** @var UpsertUserAvailabilityPeriodRequest $dto */
            $dto = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            $upsert = new UpsertUserAvailabilityPeriodRequest();
            $upsert->type = (string) ($dto['type'] ?? '');
            $upsert->startsAt = (string) ($dto['startsAt'] ?? '');
            $upsert->endsAt = (string) ($dto['endsAt'] ?? '');
        } catch (\JsonException) {
            return [null, new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body is not valid JSON.'], 400)];
        }

        $violations = $this->validator->validate($upsert);
        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return [null, new JsonResponse(['error' => 'validation_failed', 'violations' => $errors], 422)];
        }

        return [$upsert, null];
    }

    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable, 2: ?JsonResponse}
     */
    private function parseDates(string $startsAtRaw, string $endsAtRaw): array
    {
        $errors = [];

        try {
            $startsAt = new \DateTimeImmutable($startsAtRaw);
        } catch (\Exception) {
            $errors['startsAt'] = 'This value is not a valid date.';
        }

        try {
            $endsAt = new \DateTimeImmutable($endsAtRaw);
        } catch (\Exception) {
            $errors['endsAt'] = 'This value is not a valid date.';
        }

        if ([] !== $errors) {
            return [null, null, new JsonResponse(['error' => 'validation_failed', 'violations' => $errors], 422)];
        }

        if ($endsAt <= $startsAt) {
            return [null, null, new JsonResponse(
                ['error' => 'validation_failed', 'violations' => ['endsAt' => 'endsAt must be strictly after startsAt.']],
                422,
            )];
        }

        return [$startsAt, $endsAt, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(UserAvailabilityPeriod $period): array
    {
        return [
            'stableId' => (string) $period->getStableId(),
            'type' => $period->getType()->value,
            'startsAt' => $period->getStartsAt()->format(\DATE_ATOM),
            'endsAt' => $period->getEndsAt()->format(\DATE_ATOM),
            'createdAt' => $period->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $period->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }
}
