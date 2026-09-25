<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\PlanningRuleSetConfiguration;
use App\Entity\Planning;
use App\Entity\PlanningLine;
use App\Entity\PlanningRuleSet;
use App\Entity\User;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningRuleSetRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\PlanningRuleSetService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * The "Paramètres de génération" business-facing surface over
 * `PlanningRuleSet` (docs/decisions.md D137, follow-up to D136) — a manager
 * (the Planning's creator, or a current OWNER/ADMIN of the line's team,
 * exactly `PlanningVoter::MANAGE_RULE_SET`'s population — same rule as
 * MANAGE_LINE_STRUCTURE, never the narrower team-only `PlanningTeamRoleVoter`)
 * never needs to understand DRAFT/ACTIVE/RETIRED, version numbers or
 * stableIds to unblock the `NO_ACTIVE_RULE_SET` preflight blocker;
 * `activate()` is the only action offered, and does everything
 * `PlanningRuleSetService` already guarantees underneath (versioning, atomic
 * retire-then-activate, never mutating an ACTIVE RuleSet in place) — no new
 * domain logic, this controller only authorizes, deserializes and
 * serializes.
 *
 * `PlanningRuleSetConfiguration`'s own fields (`maxDutiesPerFairnessPeriod`,
 * `maxWeekendsPerFairnessPeriod`, `teamMinRestHours`, `maxConsecutiveNights`,
 * `holidayDecayFactor`) are every one of them **not yet read by the real
 * generation pipeline** (audited before writing this controller — none of
 * MAX_DUTIES/MAX_WEEKENDS/MAX_CONSECUTIVE_NIGHTS is wired into
 * `OptimizationProblemBuilder`/CP-SAT, `teamMinRestHours` was explicitly
 * moved off this DTO by D105 in favour of per-generation
 * `RestPolicyOptions`, and `holidayDecayFactor` has no consumer since
 * `NAMED_HOLIDAY` was never implemented — docs/fairness.md §3).
 * `activate()` therefore always sends an **empty** configuration: exposing
 * a form for fields the solver ignores would be exactly the "expert
 * settings only because they exist in a DTO" this lot's spec explicitly
 * forbids. The real, currently-consumed generation rule — rest policy — is
 * chosen per launch instead (`PlanningLaunchController`), never stored
 * here; see docs/decisions.md D137.
 */
final class PlanningRuleSetController
{
    public function __construct(
        private readonly PlanningLineRepository $planningLineRepository,
        private readonly PlanningRuleSetRepository $ruleSetRepository,
        private readonly PlanningRuleSetService $ruleSetService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/api/planning-lines/{lineStableId}/rule-set', name: 'api_planning_rule_set_get', methods: ['GET'])]
    public function get(string $lineStableId): JsonResponse
    {
        $line = $this->resolveLine($lineStableId);
        $active = $this->ruleSetRepository->findActive($line->getPlanningTeam());

        return new JsonResponse($this->ruleSetToArray($active));
    }

    #[Route('/api/planning-lines/{lineStableId}/rule-set/activate', name: 'api_planning_rule_set_activate', methods: ['POST'])]
    public function activate(string $lineStableId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $line = $this->resolveLine($lineStableId);

        [$configuration, $errorResponse] = $this->deserialize($request);
        if (null !== $errorResponse) {
            return $errorResponse;
        }

        try {
            $draft = $this->ruleSetService->createDraft($line->getPlanningTeam(), new \DateTimeImmutable('today'), $configuration, $user);
            $this->ruleSetService->activate($draft);
        } catch (ValidationFailedException $exception) {
            return new JsonResponse(['error' => 'validation_failed', 'message' => $exception->getMessage()], 422);
        }

        return new JsonResponse($this->ruleSetToArray($draft), 201);
    }

    private function resolveLine(string $lineStableId): PlanningLine
    {
        $line = $this->planningLineRepository->findOneByStableId($lineStableId);
        if (null === $line) {
            throw new NotFoundHttpException('PlanningLine not found.');
        }

        $this->denyUnlessCanManage($line->getPlanning());

        return $line;
    }

    private function denyUnlessCanManage(Planning $planning): void
    {
        if (!$this->authorizationChecker->isGranted(PlanningVoter::MANAGE_RULE_SET, $planning)) {
            throw new AccessDeniedHttpException('Only the creator or an OWNER/ADMIN of this Planning can manage its generation rules.');
        }
    }

    /**
     * @return array{0: ?PlanningRuleSetConfiguration, 1: ?JsonResponse}
     */
    private function deserialize(Request $request): array
    {
        $content = trim($request->getContent());
        if ('' === $content) {
            return [new PlanningRuleSetConfiguration(), null];
        }

        try {
            $raw = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [null, new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body is not valid JSON.'], 400)];
        }

        if (!\is_array($raw)) {
            return [null, new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body must be a JSON object.'], 400)];
        }

        return [PlanningRuleSetConfiguration::fromArray($raw), null];
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleSetToArray(?PlanningRuleSet $ruleSet): array
    {
        if (null === $ruleSet) {
            return ['active' => false];
        }

        return [
            'active' => true,
            'activatedAt' => $ruleSet->getUpdatedAt()->format(\DATE_ATOM),
            'effectiveFrom' => $ruleSet->getEffectiveFrom()->format('Y-m-d'),
        ];
    }
}
