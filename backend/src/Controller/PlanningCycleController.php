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
use App\Service\PeriodDateRules;
use App\Service\PeriodDateViolation;
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
 * D187). Reading them takes PlanningVoter::VIEW; creating, changing the dates
 * of (D207), deleting one and reading the dates it may take take
 * PlanningVoter::MANAGE (the creator); opening a
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
        private readonly PeriodDateRules $dateRules,
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

        // docs/decisions.md D207: the end, the start (first period only), or both — each with the date it replaces.
        $endsAt = $this->date($body, 'endsAt');
        $startsAt = $this->date($body, 'startsAt');
        $outcome = $this->cycleService->changeDates(
            $planning,
            $cycle,
            $startsAt,
            null !== $startsAt ? $this->date($body, 'expectedStartsAt', required: true) : null,
            $endsAt,
            null !== $endsAt ? $this->date($body, 'expectedEndsAt', required: true) : null,
        );

        return new JsonResponse([...$this->presenter->period($outcome['cycle']), 'generationOutdated' => $outcome['generationOutdated']]);
    }

    /**
     * docs/decisions.md D207: the dates a period may take, day by day, for the
     * date picker — `?period=` a period or `next` (the one "Créer la période
     * suivante" would create), `?edge=` `end` (each candidate is a last day,
     * inclusive) or `start` (the first period's first day), `?from=&to=` the
     * days shown. Each day carries every reason it is refused, with the
     * blocks concerned; `blockers` are the reasons no day is possible. The
     * write applies the very same rules (PeriodDateRules) and refuses with
     * the same codes: this is only what the picker shows, never a promise.
     */
    #[Route('/api/plannings/{planningStableId}/periods/date-options', name: 'api_planning_period_date_options', methods: ['GET'], priority: 1)]
    public function dateOptions(string $planningStableId, Request $request): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->denyUnlessCanManage($planning);

        $query = ['from' => $request->query->getString('from'), 'to' => $request->query->getString('to')];
        $from = $this->date($query, 'from', required: true);
        $to = $this->date($query, 'to', required: true);
        if ($to < $from || (int) $from->diff($to)->days >= PeriodDateRules::MAX_OPTION_DAYS) {
            throw PlanningCycleException::invalid('invalid_range', \sprintf('to must be on or after from, %d days at most.', PeriodDateRules::MAX_OPTION_DAYS));
        }
        $edge = $request->query->getString('edge', 'end');
        if (!\in_array($edge, ['start', 'end'], true)) {
            throw PlanningCycleException::invalid('invalid_request', 'edge is "start" or "end".');
        }

        $periodParam = $request->query->getString('period');
        $historical = [];
        if ('next' === $periodParam) {
            if ('start' === $edge) {
                throw PlanningCycleException::invalid('invalid_request', 'A new period starts the day after the last one: only its end is chosen.');
            }
            $cycles = $this->cycleRepository->findByPlanning($planning);
            $last = $cycles[\count($cycles) - 1];
            $options = $this->dateRules->nextEndOptions($last, $from, $to);
            $historical = $options['historicalStartCuts'];
            $cycle = null;
            $fixedStart = $last->getEndsAt();
            $current = null;
        } else {
            $cycle = $this->resolveCycle($planning, $periodParam);
            $options = 'end' === $edge ? $this->dateRules->endOptions($cycle, $from, $to) : $this->dateRules->startOptions($cycle, $from, $to);
            $fixedStart = 'end' === $edge ? $cycle->getStartsAt() : null;
            $current = 'end' === $edge ? $cycle->getEndsAt()->modify('-1 day') : $cycle->getStartsAt();
        }

        $blockers = $options['blockers'];
        if (!$this->cycleService->isCreationEnabled()) {
            array_unshift($blockers, PeriodDateViolation::conflict('period_management_disabled', 'Managing the periods of a planning is not enabled.'));
        }
        $reasons = static fn (array $violations): array => array_map(static fn (PeriodDateViolation $violation): array => $violation->toArray(), $violations);

        return new JsonResponse([
            'period' => null !== $cycle ? $this->presenter->reference($cycle) : null,
            'edge' => $edge,
            'fixedStart' => $fixedStart?->format('Y-m-d'),
            'current' => $current?->format('Y-m-d'),
            'editable' => [] === $blockers,
            'blockers' => $reasons($blockers),
            'historicalStartCuts' => array_map(static fn (BlockCut $cut): array => $cut->toArray(), $historical),
            // A day is possible only when nothing blocks the date at all: a blocker is a reason of every day.
            'days' => array_map(static fn (array $day): array => [
                'date' => $day['date'],
                'allowed' => [] === $blockers && [] === $day['violations'],
                'reasons' => $reasons([...$blockers, ...$day['violations']]),
            ], $options['days']),
        ]);
    }

    /**
     * docs/decisions.md D211: what the end of a locked period may be brought
     * back to — its last duty day, the next period's first day, and each
     * candidate last day with its reasons. The creator only.
     */
    #[Route('/api/plannings/{planningStableId}/periods/{periodStableId}/end-rectification', name: 'api_planning_period_end_rectification_options', methods: ['GET'])]
    public function endRectificationOptions(string $planningStableId, string $periodStableId): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->denyUnlessCanManage($planning);
        $cycle = $this->resolveCycle($planning, $periodStableId);

        $options = $this->dateRules->rectificationOptions($cycle);
        $blockers = $options['blockers'];
        if (!$this->cycleService->isCreationEnabled()) {
            array_unshift($blockers, PeriodDateViolation::conflict('period_management_disabled', 'Managing the periods of a planning is not enabled.'));
        }
        $reasons = static fn (array $violations): array => array_map(static fn (PeriodDateViolation $violation): array => $violation->toArray(), $violations);
        $days = array_map(static fn (array $day): array => [
            'date' => $day['date'],
            'allowed' => [] === $blockers && [] === $day['violations'],
            'reasons' => $reasons([...$blockers, ...$day['violations']]),
        ], $options['days']);
        $allowed = array_values(array_filter($days, static fn (array $day): bool => $day['allowed']));

        return new JsonResponse([
            'period' => $this->presenter->reference($cycle),
            'startsAt' => $cycle->getStartsAt()->format('Y-m-d'),
            'currentLastDay' => $options['currentLastDay'],
            'lastDutyDay' => $options['lastDutyDay'],
            'eligible' => [] === $blockers && [] !== $allowed,
            'blockers' => $reasons($blockers),
            // The latest possible last day: the least moved, never past a duty.
            'proposedLastDay' => [] !== $allowed ? $allowed[\count($allowed) - 1]['date'] : null,
            'reasonMaxLength' => PlanningCycleService::RECTIFICATION_REASON_MAX,
            'days' => $days,
        ]);
    }

    /**
     * docs/decisions.md D211: rectifies the end of a locked period —
     * `{endsAt (exclusive), expectedEndsAt, reason, confirm: true}`. The
     * creator only; the refusals are the options' reasons.
     */
    #[Route('/api/plannings/{planningStableId}/periods/{periodStableId}/end-rectification', name: 'api_planning_period_end_rectification', methods: ['POST'])]
    public function rectifyEnd(string $planningStableId, string $periodStableId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->denyUnlessCanManage($planning);
        $cycle = $this->resolveCycle($planning, $periodStableId);
        $body = $this->body($request);
        $reason = $body['reason'] ?? '';

        $this->cycleService->rectifyLockedEnd(
            $planning,
            $cycle,
            $this->date($body, 'endsAt', required: true),
            $this->date($body, 'expectedEndsAt', required: true),
            $user,
            \is_string($reason) ? $reason : '',
            true === ($body['confirm'] ?? false),
        );

        return new JsonResponse(['periods' => $this->presenter->periods($planning)]);
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
