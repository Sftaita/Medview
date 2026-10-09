<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DutySwapAudience;
use App\Entity\DutySwapKind;
use App\Entity\DutySwapProposal;
use App\Entity\DutySwapRequest;
use App\Entity\User;
use App\Exception\DutySwapException;
use App\Repository\DutySwapProposalRepository;
use App\Repository\DutySwapRequestRepository;
use App\Repository\PlanningRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\DutySwapAccess;
use App\Service\DutySwapQueryService;
use App\Service\DutySwapService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Duty swaps between members (docs/duty-swaps.md §10, docs/decisions.md
 * D178). Every rule — who may do what, revalidation, atomicity,
 * concurrency, history, emails — lives in DutySwapService /
 * DutySwapQueryService / DutySwapAccess; this controller only parses,
 * resolves stable ids and serializes. Member actions are implicitly scoped
 * to #[CurrentUser]: nobody ever acts in somebody else's name. No manager
 * approval exists anywhere; managers only read a planning's history.
 *
 * A request the viewer may not see answers 404, never 403 — its existence
 * is not disclosed.
 */
final class DutySwapController
{
    public function __construct(
        private readonly DutySwapService $swapService,
        private readonly DutySwapQueryService $queryService,
        private readonly DutySwapAccess $access,
        private readonly DutySwapRequestRepository $requestRepository,
        private readonly DutySwapProposalRepository $proposalRepository,
        private readonly PlanningRepository $planningRepository,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/api/me/duty-swaps', name: 'api_me_duty_swaps', methods: ['GET'])]
    public function overview(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse($this->queryService->overviewFor($user));
    }

    #[Route('/api/duty-swaps/options', name: 'api_duty_swap_options', methods: ['GET'])]
    public function options(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $dutyStableId = (string) $request->query->get('dutyStableId', '');
        if ('' === $dutyStableId) {
            return new JsonResponse(['error' => 'validation_failed', 'violations' => ['dutyStableId' => 'This value should not be blank.']], 422);
        }

        return $this->run(fn (): array => $this->queryService->creationOptions($user, $dutyStableId));
    }

    #[Route('/api/duty-swap-requests', name: 'api_duty_swap_request_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $body = $this->body($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }

        $kind = DutySwapKind::tryFrom((string) ($body['kind'] ?? ''));
        $audience = DutySwapAudience::tryFrom((string) ($body['audience'] ?? (DutySwapKind::AGREED === $kind ? 'SELECTED' : '')));
        $dutyStableId = (string) ($body['dutyStableId'] ?? '');
        $recipients = $body['recipientUserStableIds'] ?? [];
        $violations = [];
        if ('' === $dutyStableId) {
            $violations['dutyStableId'] = 'This value should not be blank.';
        }
        if (null === $kind) {
            $violations['kind'] = 'Expected AGREED or SEARCH.';
        }
        if (null === $audience) {
            $violations['audience'] = 'Expected SELECTED or ALL.';
        }
        if (!\is_array($recipients) || [] !== array_filter($recipients, static fn (mixed $id): bool => !\is_string($id))) {
            $violations['recipientUserStableIds'] = 'Expected a list of user stable ids.';
        }
        if ([] !== $violations) {
            return new JsonResponse(['error' => 'validation_failed', 'violations' => $violations], 422);
        }

        return $this->run(function () use ($user, $dutyStableId, $kind, $audience, $recipients, $body): array {
            $swapRequest = $this->swapService->createRequest(
                $user,
                $dutyStableId,
                $kind,
                $audience,
                array_values($recipients),
                isset($body['counterpartDutyStableId']) ? (string) $body['counterpartDutyStableId'] : null,
                true === ($body['acknowledgedResponsibility'] ?? false),
            );

            return $this->queryService->detailFor($swapRequest, $user);
        }, 201);
    }

    #[Route('/api/duty-swap-requests/{stableId}', name: 'api_duty_swap_request_show', methods: ['GET'])]
    public function show(string $stableId, #[CurrentUser] User $user): JsonResponse
    {
        return $this->run(fn (): array => $this->queryService->detailFor($this->visibleRequest($stableId, $user), $user));
    }

    #[Route('/api/duty-swap-requests/{stableId}/cancel', name: 'api_duty_swap_request_cancel', methods: ['POST'])]
    public function cancel(string $stableId, #[CurrentUser] User $user): JsonResponse
    {
        return $this->run(function () use ($stableId, $user): array {
            $swapRequest = $this->visibleRequest($stableId, $user);
            $this->swapService->cancel($user, $swapRequest);

            return $this->queryService->detailFor($this->visibleRequest($stableId, $user), $user);
        });
    }

    #[Route('/api/duty-swap-requests/{stableId}/proposals', name: 'api_duty_swap_proposal_create', methods: ['POST'])]
    public function propose(string $stableId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $body = $this->body($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $dutyStableId = (string) ($body['dutyStableId'] ?? '');
        if ('' === $dutyStableId) {
            return new JsonResponse(['error' => 'validation_failed', 'violations' => ['dutyStableId' => 'This value should not be blank.']], 422);
        }

        return $this->run(function () use ($stableId, $user, $dutyStableId): array {
            $this->swapService->propose($user, $this->visibleRequest($stableId, $user), $dutyStableId);

            return $this->queryService->detailFor($this->visibleRequest($stableId, $user), $user);
        }, 201);
    }

    #[Route('/api/duty-swap-proposals/{stableId}/accept', name: 'api_duty_swap_proposal_accept', methods: ['POST'])]
    public function accept(string $stableId, #[CurrentUser] User $user): JsonResponse
    {
        return $this->run(function () use ($stableId, $user): array {
            $proposal = $this->visibleProposal($stableId, $user);
            $requestStableId = (string) $proposal->getRequest()->getStableId();
            $applied = $this->swapService->accept($user, $proposal);

            return ['alreadyApplied' => !$applied] + $this->queryService->detailFor($this->visibleRequest($requestStableId, $user), $user);
        });
    }

    #[Route('/api/duty-swap-proposals/{stableId}/refuse', name: 'api_duty_swap_proposal_refuse', methods: ['POST'])]
    public function refuse(string $stableId, #[CurrentUser] User $user): JsonResponse
    {
        return $this->run(function () use ($stableId, $user): array {
            $proposal = $this->visibleProposal($stableId, $user);
            $requestStableId = (string) $proposal->getRequest()->getStableId();
            $this->swapService->refuse($user, $proposal);

            return $this->queryService->detailFor($this->visibleRequest($requestStableId, $user), $user);
        });
    }

    #[Route('/api/duty-swap-proposals/{stableId}/withdraw', name: 'api_duty_swap_proposal_withdraw', methods: ['POST'])]
    public function withdraw(string $stableId, #[CurrentUser] User $user): JsonResponse
    {
        return $this->run(function () use ($stableId, $user): array {
            $proposal = $this->visibleProposal($stableId, $user);
            $requestStableId = (string) $proposal->getRequest()->getStableId();
            $this->swapService->withdraw($user, $proposal);

            return $this->queryService->detailFor($this->visibleRequest($requestStableId, $user), $user);
        });
    }

    /**
     * The read-only swap history of a planning for its managers
     * (PlanningVoter::MANAGE_CALENDAR) — nothing to approve there.
     */
    #[Route('/api/plannings/{planningStableId}/duty-swaps', name: 'api_planning_duty_swaps', methods: ['GET'])]
    public function planningHistory(string $planningStableId, #[CurrentUser] User $user): JsonResponse
    {
        $planning = $this->planningRepository->findOneByStableId($planningStableId);
        if (null === $planning) {
            throw new NotFoundHttpException('Planning not found.');
        }
        if (!$this->authorizationChecker->isGranted(PlanningVoter::MANAGE_CALENDAR, $planning)) {
            throw new AccessDeniedHttpException('Only the creator or a manager of this Planning can read its swap history.');
        }

        return new JsonResponse(['requests' => $this->queryService->planningHistory($planning, $user)]);
    }

    /**
     * @throws DutySwapException
     */
    private function visibleRequest(string $stableId, User $user): DutySwapRequest
    {
        $request = $this->requestRepository->findOneByStableId($stableId);
        if (null === $request || !$this->access->canView($request, $user)) {
            throw DutySwapException::notFound();
        }

        return $request;
    }

    /**
     * @throws DutySwapException
     */
    private function visibleProposal(string $stableId, User $user): DutySwapProposal
    {
        $proposal = $this->proposalRepository->findOneByStableId($stableId);
        if (null === $proposal || !$this->access->canView($proposal->getRequest(), $user) || !$this->access->canViewProposal($proposal, $user)) {
            throw new DutySwapException('not_found', 404, 'Cette proposition d\'échange est introuvable.');
        }

        return $proposal;
    }

    /**
     * @param callable(): array<string, mixed> $action
     */
    private function run(callable $action, int $status = 200): JsonResponse
    {
        try {
            return new JsonResponse($action(), $status);
        } catch (DutySwapException $exception) {
            return new JsonResponse(array_filter([
                'error' => $exception->error,
                'message' => $exception->getMessage(),
                'reason' => $exception->reason,
                'party' => $exception->party,
            ], static fn (mixed $value): bool => null !== $value), $exception->status);
        }
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function body(Request $request): array|JsonResponse
    {
        try {
            $raw = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body is not valid JSON.'], 400);
        }

        return \is_array($raw) ? $raw : new JsonResponse(['error' => 'invalid_json', 'message' => 'Expected a JSON object.'], 400);
    }
}
