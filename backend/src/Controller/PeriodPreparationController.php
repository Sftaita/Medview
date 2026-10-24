<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Planning;
use App\Entity\PlanningCycle;
use App\Entity\User;
use App\Exception\PlanningCycleException;
use App\Repository\PlanningCycleRepository;
use App\Repository\PlanningRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\PeriodPreparationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * « Préparer la période » (docs/decisions.md D210): reading and replacing
 * what is planned for one period — announcement, target generation date,
 * reminders. Takes PlanningVoter::MANAGE_AVAILABILITY, like opening a
 * collection or sending a reminder by hand. The refusals of the domain are
 * PlanningCycleException (409/422 with their code), rendered by
 * ApiExceptionListener; a period of another planning is 404.
 */
final class PeriodPreparationController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningCycleRepository $cycleRepository,
        private readonly PeriodPreparationService $preparationService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/api/plannings/{planningStableId}/periods/{periodStableId}/preparation', name: 'api_planning_period_preparation_show', methods: ['GET'])]
    public function show(string $planningStableId, string $periodStableId): JsonResponse
    {
        $cycle = $this->resolve($planningStableId, $periodStableId);

        return new JsonResponse($this->preparationService->view($cycle));
    }

    #[Route('/api/plannings/{planningStableId}/periods/{periodStableId}/preparation', name: 'api_planning_period_preparation_save', methods: ['PUT'])]
    public function save(string $planningStableId, string $periodStableId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $cycle = $this->resolve($planningStableId, $periodStableId);
        $body = $this->body($request);
        $reminders = $body['reminders'] ?? [];
        if (!\is_array($reminders) || !array_is_list($reminders)) {
            throw PlanningCycleException::invalid('invalid_request', 'reminders must be a list.');
        }
        foreach ($reminders as $reminder) {
            if (!\is_array($reminder)) {
                throw PlanningCycleException::invalid('invalid_request', 'Each reminder is an object {stableId?, value, unit}.');
            }
        }

        $this->preparationService->save($cycle, $user, [
            'announceOn' => $body['announceOn'] ?? null,
            'targetGenerationOn' => $body['targetGenerationOn'] ?? null,
            'reminders' => $reminders,
        ]);

        return new JsonResponse($this->preparationService->view($cycle));
    }

    private function resolve(string $planningStableId, string $periodStableId): PlanningCycle
    {
        $planning = $this->planningRepository->findOneByStableId($planningStableId) ?? throw new NotFoundHttpException('Planning not found.');
        $this->denyUnless($planning);

        return $this->cycleRepository->findOneForPlanning($planning, $periodStableId) ?? throw PlanningCycleException::notFound();
    }

    private function denyUnless(Planning $planning): void
    {
        if (!$this->authorizationChecker->isGranted(PlanningVoter::MANAGE_AVAILABILITY, $planning)) {
            throw new AccessDeniedHttpException('Only the creator or an OWNER/ADMIN of this Planning can prepare its periods.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        try {
            $body = json_decode('' === $request->getContent() ? '{}' : $request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw PlanningCycleException::invalid('invalid_json', 'The request body is not valid JSON.');
        }
        if (!\is_array($body)) {
            throw PlanningCycleException::invalid('invalid_json', 'The request body must be a JSON object.');
        }

        return $body;
    }
}
