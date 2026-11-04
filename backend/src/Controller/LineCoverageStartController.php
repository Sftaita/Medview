<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Duty;
use App\Entity\PlanningLine;
use App\Entity\User;
use App\Exception\CalendarBusyException;
use App\Exception\LineCoverageStartConfirmationRequiredException;
use App\Repository\PlanningCycleRepository;
use App\Repository\PlanningLineRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\LineCoverageStartPlan;
use App\Service\LineCoverageStartService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * "Début de couverture de cette ligne" (docs/decisions.md D233). Every rule
 * lives in LineCoverageStartService; this controller authorizes, checks the
 * shape of the body and serializes.
 */
final class LineCoverageStartController
{
    public function __construct(
        private readonly PlanningLineRepository $lineRepository,
        private readonly PlanningCycleRepository $cycleRepository,
        private readonly LineCoverageStartService $service,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/api/planning-lines/{lineStableId}/coverage-start', name: 'api_line_coverage_start_get', methods: ['GET'])]
    public function get(string $lineStableId): JsonResponse
    {
        $line = $this->resolve($lineStableId, PlanningVoter::MANAGE_LINE_STRUCTURE);

        return new JsonResponse(['coverageStartsOn' => $line->getCoverageStartsOn()?->format('Y-m-d')]);
    }

    /** What this date would do. Nothing is written. */
    #[Route('/api/planning-lines/{lineStableId}/coverage-start/preview', name: 'api_line_coverage_start_preview', methods: ['POST'])]
    public function preview(string $lineStableId, Request $request): JsonResponse
    {
        $line = $this->resolve($lineStableId, PlanningVoter::MANAGE_LINE_STRUCTURE);
        [$date, $error] = $this->date($request);
        if (null !== $error) {
            return $error;
        }

        return new JsonResponse($this->planToArray($this->service->plan($line, $date)));
    }

    #[Route('/api/planning-lines/{lineStableId}/coverage-start', name: 'api_line_coverage_start_put', methods: ['PUT'])]
    public function put(string $lineStableId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $line = $this->resolve($lineStableId, PlanningVoter::MANAGE_LINE_STRUCTURE);
        [$date, $error] = $this->date($request);
        if (null !== $error) {
            return $error;
        }
        $confirmation = $request->toArray()['confirmation'] ?? null;

        try {
            $outcome = $this->service->change($line, $date, $user, \is_string($confirmation) ? $confirmation : null);
        } catch (LineCoverageStartConfirmationRequiredException $exception) {
            return new JsonResponse(['error' => 'coverage_start_confirmation_required', 'plan' => $this->planToArray($exception->plan)], 409);
        } catch (CalendarBusyException) {
            return new JsonResponse(['error' => 'job_in_progress', 'message' => 'Une génération ou une complétion est en cours sur ce planning.'], 409);
        }

        return new JsonResponse([
            'coverageStartsOn' => $line->getCoverageStartsOn()?->format('Y-m-d'),
            'changed' => null !== $outcome['change'],
            'retiredDutyCount' => null !== $outcome['change'] ? \count($outcome['plan']->retired) : 0,
            'keptDutyCount' => \count($outcome['plan']->kept),
        ]);
    }

    /** The reinforcements required since a reassignment, created empty on the manager's explicit request. */
    #[Route('/api/planning-lines/{lineStableId}/missing-reinforcements', name: 'api_line_missing_reinforcements', methods: ['POST'])]
    public function createMissing(string $lineStableId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $line = $this->resolve($lineStableId, PlanningVoter::MANAGE_CALENDAR);
        $periodId = $request->toArray()['periodStableId'] ?? null;
        $cycle = \is_string($periodId) ? $this->cycleRepository->findOneForPlanning($line->getPlanning(), $periodId) : null;
        if (null === $cycle) {
            return new JsonResponse(['error' => 'validation_failed', 'violations' => ['periodStableId' => 'Unknown period.']], 422);
        }

        try {
            return new JsonResponse(['createdDutyCount' => $this->service->createMissingReinforcements($line, $cycle, $user)]);
        } catch (CalendarBusyException) {
            return new JsonResponse(['error' => 'job_in_progress', 'message' => 'Une génération ou une complétion est en cours sur ce planning.'], 409);
        }
    }

    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?JsonResponse}
     */
    private function date(Request $request): array
    {
        $body = $request->toArray();
        if (!\array_key_exists('coverageStartsOn', $body)) {
            return [null, new JsonResponse(['error' => 'validation_failed', 'violations' => ['coverageStartsOn' => 'This field is required (null clears the date).']], 422)];
        }
        $raw = $body['coverageStartsOn'];
        if (null === $raw) {
            return [null, null];
        }
        $date = \is_string($raw) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new \DateTimeZone('UTC')) : false;
        if (false === $date || $date->format('Y-m-d') !== $raw) {
            return [null, new JsonResponse(['error' => 'validation_failed', 'violations' => ['coverageStartsOn' => 'This value is not a valid date.']], 422)];
        }

        return [$date, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function planToArray(LineCoverageStartPlan $plan): array
    {
        $row = static fn (Duty $duty): array => ['dutyStableId' => (string) $duty->getStableId(), 'date' => $duty->getLocalDate()->format('Y-m-d')];

        return [
            'previousStartsOn' => $plan->previousStartsOn?->format('Y-m-d'),
            'newStartsOn' => $plan->newStartsOn?->format('Y-m-d'),
            'changesAnything' => $plan->changesAnything(),
            'confirmationRequired' => $plan->confirmationRequired(),
            'confirmation' => $plan->hash(),
            'retired' => array_map($row, $plan->retired),
            'kept' => array_map(static fn (array $kept): array => $row($kept['duty']) + ['holder' => ['firstName' => $kept['holderFirstName'], 'lastName' => $kept['holderLastName']]], $plan->kept),
        ];
    }

    private function resolve(string $lineStableId, string $attribute): PlanningLine
    {
        $line = $this->lineRepository->findOneByStableId($lineStableId);
        if (null === $line) {
            throw new NotFoundHttpException('PlanningLine not found.');
        }
        if (!$this->authorizationChecker->isGranted($attribute, $line->getPlanning())) {
            throw new AccessDeniedHttpException('You cannot manage this line.');
        }

        return $line;
    }
}
