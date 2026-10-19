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
use App\Service\BlockCut;
use App\Service\PeriodBoundaryGuard;
use App\Service\PlanningCyclePresenter;
use App\Service\PlanningCycleService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The periods of a Planning (docs/planning-cycles.md §7.3, docs/decisions.md
 * D187). Reading them takes PlanningVoter::VIEW; creating, changing the end
 * of and deleting one take PlanningVoter::MANAGE (the creator); opening a
 * collection on one takes PlanningVoter::MANAGE_AVAILABILITY, like every
 * collection. The refusals of the domain (409/422 with their code) are
 * PlanningCycleException, rendered by ApiExceptionListener.
 *
 * A period of another Planning is always 404 `period_not_found`, never a
 * hint that it exists.
 */
final class PlanningCycleController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningCycleRepository $cycleRepository,
        private readonly PlanningCycleService $cycleService,
        private readonly PlanningCyclePresenter $presenter,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly PeriodBoundaryGuard $boundaryGuard,
    ) {
    }

    #[Route('/api/plannings/{planningStableId}/periods', name: 'api_planning_period_list', methods: ['GET'])]
    public function list(string $planningStableId): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->denyUnless(PlanningVoter::VIEW, $planning, 'You do not have access to this Planning.');

        return new JsonResponse([
            ...$this->presenter->overview($planning),
            'canCreate' => $this->cycleService->isCreationEnabled() && $this->authorizationChecker->isGranted(PlanningVoter::MANAGE, $planning),
            'periods' => $this->presenter->periods($planning),
        ]);
    }

    #[Route('/api/plannings/{planningStableId}/periods', name: 'api_planning_period_create', methods: ['POST'])]
    public function create(string $planningStableId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->denyUnlessCanManage($planning);
        $body = $this->body($request);

        $endsAt = $this->date($body, 'endsAt', required: true);
        $previous = $body['previousPeriodStableId'] ?? null;
        if (!\is_string($previous) || '' === $previous) {
            throw PlanningCycleException::invalid('invalid_request', 'previousPeriodStableId is required: the last period the request is based on.');
        }

        // docs/decisions.md D192: the explicit confirmation of a historical block cut, never implied.
        $accept = true === ($body['acceptHistoricalBlockCuts'] ?? null);
        $cycle = $this->cycleService->createNext($planning, $previous, $endsAt, $user, $accept);

        return new JsonResponse([
            ...$this->presenter->period($cycle),
            // What was confirmed: the occurrences cut by its start, their remainder days in this period.
            'historicalBlockCuts' => array_map(static fn (BlockCut $cut): array => $cut->toArray(), $this->boundaryGuard->historicalCutsAt($cycle)),
        ], 201);
    }

    #[Route('/api/plannings/{planningStableId}/periods/{periodStableId}', name: 'api_planning_period_update', methods: ['PATCH'])]
    public function update(string $planningStableId, string $periodStableId, Request $request): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->denyUnlessCanManage($planning);
        $cycle = $this->resolveCycle($planning, $periodStableId);
        $body = $this->body($request);

        $outcome = $this->cycleService->changeEnd(
            $planning,
            $cycle,
            $this->date($body, 'endsAt', required: true),
            $this->date($body, 'expectedEndsAt', required: true),
        );

        return new JsonResponse([...$this->presenter->period($outcome['cycle']), 'generationOutdated' => $outcome['generationOutdated']]);
    }

    #[Route('/api/plannings/{planningStableId}/periods/{periodStableId}', name: 'api_planning_period_delete', methods: ['DELETE'])]
    public function delete(string $planningStableId, string $periodStableId, Request $request): Response
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->denyUnlessCanManage($planning);
        $cycle = $this->resolveCycle($planning, $periodStableId);

        $expected = $request->query->getString('expected');
        if ('' === $expected) {
            throw PlanningCycleException::invalid('invalid_request', 'The expected last period (?expected=) is required.');
        }

        $this->cycleService->deleteLast($planning, $cycle, $expected);

        return new Response(status: 204);
    }

    #[Route('/api/plannings/{planningStableId}/periods/{periodStableId}/availability-collections', name: 'api_planning_period_collection_create', methods: ['POST'])]
    public function openCollection(string $planningStableId, string $periodStableId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->denyUnless(PlanningVoter::MANAGE_AVAILABILITY, $planning, 'Only the creator or an OWNER/ADMIN of this Planning can manage its availability collections.');
        $cycle = $this->resolveCycle($planning, $periodStableId);
        $body = $this->body($request);

        $collection = $this->cycleService->openCollection(
            $planning,
            $cycle,
            $user,
            $this->date($body, 'deadline'),
            $this->date($body, 'startsAt'),
            $this->date($body, 'endsAt'),
        );

        return new JsonResponse([
            'stableId' => (string) $collection->getStableId(),
            'period' => $this->presenter->reference($cycle),
            'startsAt' => $collection->getStartsAt()->format('Y-m-d'),
            'endsAt' => $collection->getEndsAt()->format('Y-m-d'),
            'lastDay' => $collection->getEndsAt()->modify('-1 day')->format('Y-m-d'),
            'deadline' => $collection->getDeadline()?->format('Y-m-d'),
            'status' => $collection->getStatus()->value,
        ], 201);
    }

    private function resolvePlanning(string $stableId): Planning
    {
        return $this->planningRepository->findOneByStableId($stableId) ?? throw new NotFoundHttpException('Planning not found.');
    }

    private function resolveCycle(Planning $planning, string $stableId): PlanningCycle
    {
        return $this->cycleRepository->findOneForPlanning($planning, $stableId) ?? throw PlanningCycleException::notFound();
    }

    private function denyUnlessCanManage(Planning $planning): void
    {
        $this->denyUnless(PlanningVoter::MANAGE, $planning, 'Only the creator of this Planning can manage its periods.');
    }

    private function denyUnless(string $attribute, Planning $planning, string $message): void
    {
        if (!$this->authorizationChecker->isGranted($attribute, $planning)) {
            throw new AccessDeniedHttpException($message);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        if ('' === $request->getContent()) {
            return [];
        }
        try {
            $body = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw PlanningCycleException::invalid('invalid_json', 'The request body is not valid JSON.');
        }
        if (!\is_array($body)) {
            throw PlanningCycleException::invalid('invalid_json', 'The request body must be a JSON object.');
        }

        return $body;
    }

    /**
     * A calendar date YYYY-MM-DD; null when absent and not required.
     *
     * @param array<string, mixed> $body
     *
     * @return ($required is true ? \DateTimeImmutable : ?\DateTimeImmutable)
     */
    private function date(array $body, string $field, bool $required = false): ?\DateTimeImmutable
    {
        $value = $body[$field] ?? null;
        if (null === $value || '' === $value) {
            if ($required) {
                throw PlanningCycleException::invalid('invalid_date', \sprintf('%s is required (YYYY-MM-DD).', $field));
            }

            return null;
        }
        $date = \is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw PlanningCycleException::invalid('invalid_date', \sprintf('%s is not a valid date (YYYY-MM-DD).', $field));
        }

        return $date;
    }
}
