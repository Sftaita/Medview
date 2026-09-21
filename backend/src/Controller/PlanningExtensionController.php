<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\ExtendPlanningRequest;
use App\Entity\AvailabilityCollection;
use App\Entity\User;
use App\Exception\InvalidAvailabilityDeadlineException;
use App\Exception\NoNewPlanningRangeException;
use App\Exception\OverlappingFairnessPeriodException;
use App\Exception\PlanningPeriodLockedException;
use App\Exception\PlanningRangeShrinkException;
use App\Repository\PlanningRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\PlanningExtensionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Extending a Planning's date range (docs/availability-collection.md §5,
 * D122). Creator only (PlanningVoter::MANAGE, D071) — changing the range is
 * Planning-structure work. Opens an availability collection for the added
 * dates only; the response lists what was opened so the caller need not
 * re-read it.
 */
final class PlanningExtensionController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningExtensionService $extensionService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/api/plannings/{planningStableId}/extensions', name: 'api_planning_extend', methods: ['POST'])]
    public function extend(string $planningStableId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $planning = $this->planningRepository->findOneByStableId($planningStableId);
        if (null === $planning) {
            throw new NotFoundHttpException('Planning not found.');
        }
        if (!$this->authorizationChecker->isGranted(PlanningVoter::MANAGE, $planning)) {
            throw new AccessDeniedHttpException('Only the creator of this Planning can extend it.');
        }

        try {
            $raw = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body is not valid JSON.'], 400);
        }
        if (!\is_array($raw)) {
            return new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body must be a JSON object.'], 400);
        }

        $dto = new ExtendPlanningRequest();
        $dto->startsAt = (string) ($raw['startsAt'] ?? '');
        $dto->endsAt = (string) ($raw['endsAt'] ?? '');
        $dto->deadline = (string) ($raw['deadline'] ?? '');

        $errors = [];
        $startsAt = $this->parseOptionalDate($dto->startsAt, 'startsAt', $errors);
        $endsAt = $this->parseOptionalDate($dto->endsAt, 'endsAt', $errors);
        $deadline = $this->parseOptionalDate($dto->deadline, 'deadline', $errors);
        if ([] !== $errors) {
            return new JsonResponse(['error' => 'validation_failed', 'violations' => $errors], 422);
        }

        try {
            $collections = $this->extensionService->extend($planning, $startsAt, $endsAt, $user, $deadline);
        } catch (PlanningRangeShrinkException $exception) {
            return new JsonResponse(['error' => 'range_shrink_not_supported', 'message' => $exception->getMessage()], 422);
        } catch (NoNewPlanningRangeException $exception) {
            return new JsonResponse(['error' => 'no_new_range', 'message' => $exception->getMessage()], 422);
        } catch (InvalidAvailabilityDeadlineException $exception) {
            return new JsonResponse(['error' => 'validation_failed', 'violations' => ['deadline' => $exception->getMessage()]], 422);
        } catch (PlanningPeriodLockedException $exception) {
            return new JsonResponse(['error' => 'planning_period_locked', 'message' => $exception->getMessage()], 409);
        } catch (OverlappingFairnessPeriodException $exception) {
            return new JsonResponse(['error' => 'team_already_scheduled', 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse([
            'planning' => [
                'stableId' => (string) $planning->getStableId(),
                'startsAt' => $planning->getStartsAt()->format('Y-m-d'),
                'endsAt' => $planning->getEndsAt()->format('Y-m-d'),
            ],
            'collections' => array_map(
                static fn (AvailabilityCollection $collection): array => [
                    'stableId' => (string) $collection->getStableId(),
                    'startsAt' => $collection->getStartsAt()->format('Y-m-d'),
                    'endsAt' => $collection->getEndsAt()->format('Y-m-d'),
                    'deadline' => $collection->getDeadline()?->format('Y-m-d'),
                ],
                $collections,
            ),
        ], 201);
    }

    /**
     * @param array<string, string> $errors
     */
    private function parseOptionalDate(string $value, string $field, array &$errors): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            $errors[$field] = 'This value is not a valid date (expected YYYY-MM-DD).';

            return null;
        }

        return $date;
    }
}
