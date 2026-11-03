<?php

declare(strict_types=1);

namespace App\Controller;

use App\Demand\Weekday;
use App\Dto\DemandPolicyUpdateRequest;
use App\Dto\DemandTriggerInput;
use App\Entity\PlanningLine;
use App\Entity\User;
use App\Exception\DemandPolicyConflictException;
use App\Exception\InvalidDemandPolicyException;
use App\Repository\PlanningLineRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\DemandPolicyView;
use App\Service\DemandPolicyWarning;
use App\Service\DemandSourceOption;
use App\Service\PlanningLineDemandPolicyService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * GET/PUT /api/planning-lines/{id}/demand-policy (docs/decisions.md D162):
 * read or replace how a line's demand is defined — INDEPENDENT, or
 * CONDITIONAL_ON_SOURCE_ASSIGNMENT (triggers "person of the source line ×
 * weekdays"). Every rule lives in PlanningLineDemandPolicyService; this
 * controller authorizes (MANAGE_LINE_STRUCTURE, like the weekly structure),
 * checks the body's shape and serializes.
 *
 * The response carries everything the configuration screen needs to draw
 * the "source people × weekdays" matrix without rebuilding a rule
 * client-side: the policy in force (null = INDEPENDENT), the eligible
 * source lines with their current people, the target's weekly blocks and
 * excluded days, and structured warnings.
 */
final class DemandPolicyController
{
    private const BODY_FIELDS = ['schemaVersion', 'mode', 'source', 'triggers'];
    private const SOURCE_FIELDS = ['lineStableId'];
    private const TRIGGER_FIELDS = ['userStableId', 'weekdays', 'increment'];

    public function __construct(
        private readonly PlanningLineRepository $planningLineRepository,
        private readonly PlanningLineDemandPolicyService $policyService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly \App\Service\InheritedReinforcementService $reinforcements,
        private readonly \App\Repository\UserRepository $userRepository,
    ) {
    }

    #[Route('/api/planning-lines/{lineStableId}/demand-policy', name: 'api_demand_policy_get', methods: ['GET'])]
    public function get(string $lineStableId): JsonResponse
    {
        return new JsonResponse($this->viewToArray($this->policyService->read($this->resolveLine($lineStableId))));
    }

    /**
     * docs/decisions.md D224 — "Prévisualisation des gardes nécessaires": the
     * reinforcements the configuration being edited would ask for, from the
     * source line's current calendar. Same body as PUT; nothing is saved.
     */
    #[Route('/api/planning-lines/{lineStableId}/demand-policy/preview', name: 'api_demand_policy_preview', methods: ['POST'])]
    public function preview(string $lineStableId, Request $request): JsonResponse
    {
        $line = $this->resolveLine($lineStableId);

        [$dto, $errorResponse] = $this->deserialize($request);
        if (null !== $errorResponse) {
            return $errorResponse;
        }

        try {
            $periods = $this->policyService->preview($line, $dto);
        } catch (InvalidDemandPolicyException $exception) {
            return new JsonResponse(['error' => 'invalid_demand_policy', 'code' => $exception->errorCode, 'field' => $exception->field, 'message' => $exception->getMessage()], 422);
        }

        $holders = [];
        $out = [];
        foreach ($periods as $entry) {
            $period = $entry['period'];
            $sourceHolders = null;
            $units = [];
            foreach ($entry['units'] as $sources) {
                if (null === $sourceHolders) {
                    $sourceHolders = $this->reinforcements->currentHolders($sources[0]->getPlanningPeriod()) ?? [];
                }
                $holderId = $sourceHolders[(int) $sources[0]->getId()] ?? null;
                if (null !== $holderId && !\array_key_exists($holderId, $holders)) {
                    $user = $this->userRepository->findOneByStableId($holderId);
                    $holders[$holderId] = null === $user ? null : ['userStableId' => $holderId, 'firstName' => $user->getFirstName(), 'lastName' => $user->getLastName()];
                }
                $units[] = [
                    'dates' => array_map(static fn ($d): string => $d->getLocalDate()->format('Y-m-d'), $sources),
                    'block' => \count($sources) >= 2,
                    'sourceHolder' => null !== $holderId ? $holders[$holderId] : null,
                ];
            }
            $out[] = [
                'period' => ['stableId' => (string) $period->getCycle()?->getStableId(), 'number' => $period->getCycle()?->getNumber(), 'startsAt' => $period->getStartsAt()->format('Y-m-d'), 'endsAt' => $period->getEndsAt()->format('Y-m-d')],
                'generated' => $entry['generated'],
                'sourceGenerated' => $entry['sourceGenerated'],
                'dutyCount' => array_sum(array_map(static fn (array $u): int => \count($u['dates']), $units)),
                'units' => $units,
            ];
        }

        return new JsonResponse(['periods' => $out]);
    }

    #[Route('/api/planning-lines/{lineStableId}/demand-policy', name: 'api_demand_policy_put', methods: ['PUT'])]
    public function put(string $lineStableId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $line = $this->resolveLine($lineStableId);

        [$dto, $errorResponse] = $this->deserialize($request);
        if (null !== $errorResponse) {
            return $errorResponse;
        }

        try {
            $view = $this->policyService->replace($line, $dto, $user);
        } catch (InvalidDemandPolicyException $exception) {
            return new JsonResponse(['error' => 'invalid_demand_policy', 'code' => $exception->errorCode, 'field' => $exception->field, 'message' => $exception->getMessage()], 422);
        } catch (DemandPolicyConflictException $exception) {
            return new JsonResponse(['error' => $exception->errorCode, 'message' => $exception->getMessage()], 409);
        }

        return new JsonResponse($this->viewToArray($view));
    }

    private function resolveLine(string $lineStableId): PlanningLine
    {
        $line = $this->planningLineRepository->findOneByStableId($lineStableId);
        if (null === $line) {
            throw new NotFoundHttpException('PlanningLine not found.');
        }

        if (!$this->authorizationChecker->isGranted(PlanningVoter::MANAGE_LINE_STRUCTURE, $line->getPlanning())) {
            throw new AccessDeniedHttpException('Only the creator or an OWNER/ADMIN of this Planning can manage this line\'s demand.');
        }

        return $line;
    }

    /**
     * Shape only: types and accepted fields (docs/decisions.md D116 — an
     * unknown field is a 422, never silently ignored).
     *
     * @return array{0: ?DemandPolicyUpdateRequest, 1: ?JsonResponse}
     */
    private function deserialize(Request $request): array
    {
        try {
            $raw = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [null, new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body is not valid JSON.'], 400)];
        }
        if (!\is_array($raw) || array_is_list($raw) && [] !== $raw) {
            return [null, new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body must be a JSON object.'], 400)];
        }

        $violations = self::unknownFields($raw, self::BODY_FIELDS, '');
        if (!\is_int($raw['schemaVersion'] ?? null)) {
            $violations['schemaVersion'] = 'This value should be an integer.';
        }
        if (!\is_string($raw['mode'] ?? null)) {
            $violations['mode'] = 'This value should be a string.';
        }

        $source = $raw['source'] ?? null;
        $sourceLineStableId = null;
        if (null !== $source) {
            if (!\is_array($source) || !\is_string($source['lineStableId'] ?? null)) {
                $violations['source'] = 'Expected null or {"lineStableId": string}.';
            } else {
                $violations += self::unknownFields($source, self::SOURCE_FIELDS, 'source.');
                $sourceLineStableId = $source['lineStableId'];
            }
        }

        $triggers = [];
        $rawTriggers = $raw['triggers'] ?? [];
        if (!\is_array($rawTriggers) || !array_is_list($rawTriggers)) {
            $violations['triggers'] = 'This value should be a list.';
            $rawTriggers = [];
        }
        foreach ($rawTriggers as $index => $trigger) {
            $prefix = \sprintf('triggers[%d]', $index);
            if (!\is_array($trigger)) {
                $violations[$prefix] = 'Each trigger must be an object.';
                continue;
            }
            $violations += self::unknownFields($trigger, self::TRIGGER_FIELDS, $prefix.'.');
            $weekdays = $trigger['weekdays'] ?? null;
            if (!\is_string($trigger['userStableId'] ?? null)) {
                $violations[$prefix.'.userStableId'] = 'This value should be a string.';
            }
            if (!\is_array($weekdays) || !array_is_list($weekdays) || [] !== array_filter($weekdays, static fn ($w): bool => !\is_string($w))) {
                $violations[$prefix.'.weekdays'] = 'This value should be a list of weekday codes.';
                $weekdays = [];
            }
            if (!\is_int($trigger['increment'] ?? null)) {
                $violations[$prefix.'.increment'] = 'This value should be an integer.';
            }
            $triggers[] = new DemandTriggerInput((string) ($trigger['userStableId'] ?? ''), $weekdays, (int) ($trigger['increment'] ?? 0));
        }

        if ([] !== $violations) {
            return [null, new JsonResponse(['error' => 'validation_failed', 'violations' => $violations], 422)];
        }

        return [new DemandPolicyUpdateRequest($raw['schemaVersion'], $raw['mode'], $sourceLineStableId, $triggers), null];
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<string>            $accepted
     *
     * @return array<string, string>
     */
    private static function unknownFields(array $data, array $accepted, string $prefix): array
    {
        $violations = [];
        foreach (array_keys($data) as $field) {
            if (!\in_array($field, $accepted, true)) {
                $violations[$prefix.$field] = 'This field is not accepted.';
            }
        }

        return $violations;
    }

    /**
     * @return array<string, mixed>
     */
    private function viewToArray(DemandPolicyView $view): array
    {
        $policy = $view->policy;
        $triggers = [];
        foreach (array_values($view->rules->triggersByUser) as $index => $rule) {
            $user = $view->triggerUsers[$index];
            $triggers[] = [
                'userStableId' => $rule->userStableId,
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'weekdays' => array_map(static fn (Weekday $w): string => $w->value, $rule->weekdays),
                'increment' => $rule->increment,
            ];
        }

        return [
            'schemaVersion' => PlanningLineDemandPolicyService::SCHEMA_VERSION,
            'line' => ['stableId' => (string) $view->line->getStableId(), 'name' => $view->line->getName(), 'type' => $view->line->getType()->value],
            'mode' => $view->rules->mode->value,
            'policy' => null === $policy ? null : [
                'stableId' => (string) $policy->getStableId(),
                'version' => $policy->getVersion(),
                'createdAt' => $policy->getCreatedAt()->format(\DATE_ATOM),
            ],
            'source' => null === $view->rules->sourceLineStableId ? null : [
                'lineStableId' => $view->rules->sourceLineStableId,
                'name' => $view->sourceLine?->getName(),
            ],
            'triggers' => $triggers,
            'weekdays' => array_map(static fn (Weekday $w): string => $w->value, Weekday::cases()),
            'sourceOptions' => array_map(static fn (DemandSourceOption $option): array => [
                'lineStableId' => (string) $option->line->getStableId(),
                'name' => $option->line->getName(),
                'type' => $option->line->getType()->value,
                'people' => array_map(static fn (User $u): array => [
                    'userStableId' => (string) $u->getStableId(),
                    'firstName' => $u->getFirstName(),
                    'lastName' => $u->getLastName(),
                ], $option->people),
                // docs/decisions.md D224: what a reinforcement of this line would inherit, before saving.
                'structure' => $option->structure,
            ], $view->sourceOptions),
            // docs/decisions.md D224: a reinforcement line's blocks are its source's — shown read-only.
            'structureSource' => $view->rules->mode->isConditional() ? $view->rules->structureSource->value : null,
            'inheritedFrom' => null === $view->inheritsFrom ? null : ['lineStableId' => (string) $view->inheritsFrom->getStableId(), 'name' => $view->inheritsFrom->getName()],
            'targetStructure' => [
                'configured' => null !== $view->targetExcluded,
                'excludedWeekdays' => array_map(static fn (Weekday $w): string => $w->value, $view->targetExcluded ?? []),
                'blocks' => array_map(
                    static fn (array $block): array => ['name' => $block['name'], 'weekdays' => array_map(static fn (Weekday $w): string => $w->value, $block['weekdays'])],
                    $view->targetBlocks,
                ),
            ],
            'warnings' => array_map(static fn (DemandPolicyWarning $w): array => ['code' => $w->code, 'details' => $w->details, 'message' => $w->message], $view->warnings),
        ];
    }
}
