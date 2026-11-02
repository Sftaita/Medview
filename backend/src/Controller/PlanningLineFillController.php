<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Planning;
use App\Entity\RestPolicyOptions;
use App\Entity\User;
use App\Exception\NothingToFillException;
use App\Exception\PlanningJobInProgressException;
use App\Exception\SurgicalHubDataStaleException;
use App\Repository\PlanningRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\PlanningCyclePresenter;
use App\Service\PlanningCycleResolver;
use App\Service\PlanningJobPresenter;
use App\Service\PlanningJobService;
use App\Service\PlanningLineFillService;
use App\Service\RestPolicyRequestParser;
use App\Service\SurgicalHub\SurgicalHubParticipantFreshness;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * "Générer les postes non pourvus" and "Générer cette ligne"
 * (docs/decisions.md D222). The preview says what would happen to each line;
 * the run is queued like a generation (202 + a PlanningJob the screen
 * follows, D149). Business rules: PlanningLineFillService. Same population as
 * a generation (PlanningVoter::GENERATE — the creator, OWNER/ADMIN).
 */
final class PlanningLineFillController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningLineFillService $fillService,
        private readonly PlanningJobService $jobService,
        private readonly PlanningJobPresenter $jobPresenter,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly RestPolicyRequestParser $restPolicyParser,
        private readonly PlanningCycleResolver $cycleResolver,
        private readonly PlanningCyclePresenter $cyclePresenter,
    ) {
    }

    #[Route('/api/plannings/{planningStableId}/fill-preview', name: 'api_planning_fill_preview', methods: ['GET'])]
    public function preview(string $planningStableId, Request $request): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $cycle = $this->cycleResolver->requiredFromRequest($planning, $request);

        return new JsonResponse([
            ...$this->fillService->preview($planning, $cycle)->toArray(),
            'period' => $this->cyclePresenter->reference($cycle),
        ]);
    }

    #[Route('/api/plannings/{planningStableId}/fill', name: 'api_planning_fill', methods: ['POST'])]
    public function fill(string $planningStableId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        [$restPolicy, $errorResponse] = $this->restPolicyParser->parse($request);
        if (null !== $errorResponse) {
            return $errorResponse;
        }
        $cycle = $this->cycleResolver->requiredFromRequest($planning, $request);

        $body = json_decode($request->getContent() ?: '{}', true);
        $lines = \is_array($body) ? ($body['lines'] ?? null) : null;
        if (!\is_array($lines) || [] === $lines || [] !== array_filter($lines, static fn (mixed $id): bool => !\is_string($id))) {
            return new JsonResponse(['error' => 'validation_failed', 'violations' => ['lines' => 'A non-empty list of line stable ids.']], 422);
        }
        $override = \is_array($body) && true === ($body['overrideStaleSurgicalHubData'] ?? null);

        try {
            $requested = $this->jobService->requestFill($planning, $user, $cycle, array_values($lines), $restPolicy ?? RestPolicyOptions::none(), $override);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'validation_failed', 'violations' => ['lines' => 'Every line must be an active line of the period.']], 422);
        } catch (NothingToFillException $exception) {
            return new JsonResponse([
                'error' => 'nothing_to_fill',
                'message' => 'Aucune des lignes demandées n’a de garde à générer ou à compléter.',
                'preview' => $exception->preview->toArray(),
            ], 409);
        } catch (SurgicalHubDataStaleException $exception) {
            return new JsonResponse([
                'error' => $exception->overrideRefused ? 'surgicalhub_override_forbidden' : 'surgicalhub_data_stale',
                'message' => $exception->overrideRefused
                    ? 'Seul le créateur du planning peut générer avec des congés SurgicalHub non actualisés.'
                    : 'Les congés SurgicalHub de certains participants n’ont pas pu être actualisés et datent de plus de 24 heures.',
                'participants' => $this->surgicalHubParticipants($exception->report->blocking()),
                'canOverride' => $planning->getCreator() === $user,
            ], $exception->overrideRefused ? 403 : 409);
        } catch (PlanningJobInProgressException $exception) {
            return new JsonResponse([
                'error' => 'job_in_progress',
                'message' => $exception->getMessage(),
                'job' => null !== $exception->activeJob ? $this->jobPresenter->toArray($exception->activeJob) : null,
            ], 409);
        }

        return new JsonResponse([
            'job' => $this->jobPresenter->toArray($requested->job),
            'surgicalHub' => [
                'warnings' => $this->surgicalHubParticipants($requested->surgicalHub->warnings()),
                'overridden' => $this->surgicalHubParticipants($requested->surgicalHub->blocking()),
            ],
        ], 202);
    }

    /**
     * @param list<SurgicalHubParticipantFreshness> $participants
     *
     * @return list<array<string, mixed>>
     */
    private function surgicalHubParticipants(array $participants): array
    {
        return array_map(static fn (SurgicalHubParticipantFreshness $p): array => [
            'userStableId' => (string) $p->user->getStableId(),
            'firstName' => $p->user->getFirstName(),
            'lastName' => $p->user->getLastName(),
            'lastSuccessfulSyncAt' => $p->link->getLastSuccessfulSyncAt()?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            'error' => $p->error?->value,
        ], $participants);
    }

    private function resolvePlanning(string $stableId): Planning
    {
        $planning = $this->planningRepository->findOneByStableId($stableId);
        if (null === $planning) {
            throw new NotFoundHttpException('Planning not found.');
        }
        if (!$this->authorizationChecker->isGranted(PlanningVoter::GENERATE, $planning)) {
            throw new AccessDeniedHttpException('Only the creator or an OWNER/ADMIN of this Planning can generate or complete it.');
        }

        return $planning;
    }
}
