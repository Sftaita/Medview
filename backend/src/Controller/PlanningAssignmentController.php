<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DutyAssignment;
use App\Entity\PlanningGeneration;
use App\Entity\PlanningLine;
use App\Repository\PlanningRepository;
use App\Repository\PlanningTeamMemberRepository;
use App\Repository\UserRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\PlanningAssignmentViewService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Read-only view of a Planning's assignments (docs/planning.md §14, D125):
 * the whole team, or one person with a summary. Visible to anyone who can
 * VIEW the Planning — seeing who is on duty is the point of a shared
 * planning. Nothing here writes, and nothing recomputes fairness: the
 * summary only counts the person's actual assignments.
 *
 * Query parameters: `userStableId` (one person), `from` / `to`
 * (YYYY-MM-DD, `to` exclusive — the same half-open convention as the
 * planning itself, so a month is `from=2027-02-01&to=2027-03-01`).
 */
final class PlanningAssignmentController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly UserRepository $userRepository,
        private readonly PlanningTeamMemberRepository $teamMemberRepository,
        private readonly PlanningAssignmentViewService $viewService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/api/plannings/{planningStableId}/assignments', name: 'api_planning_assignments', methods: ['GET'])]
    public function list(string $planningStableId, Request $request): JsonResponse
    {
        $planning = $this->planningRepository->findOneByStableId($planningStableId);
        if (null === $planning) {
            throw new NotFoundHttpException('Planning not found.');
        }
        if (!$this->authorizationChecker->isGranted(PlanningVoter::VIEW, $planning)) {
            throw new AccessDeniedHttpException('You do not have access to this Planning.');
        }

        $errors = [];
        $from = $this->parseQueryDate($request, 'from', $errors);
        $to = $this->parseQueryDate($request, 'to', $errors);
        if ([] === $errors && null !== $from && null !== $to && $to <= $from) {
            $errors['to'] = 'to must be strictly after from.';
        }

        $user = null;
        $userStableId = (string) $request->query->get('userStableId', '');
        if ('' !== $userStableId) {
            $user = $this->userRepository->findOneByStableId($userStableId);
            // Only people of this planning: never confirms an unrelated user exists.
            if (null === $user || !$this->isInPlanning($planning, $user)) {
                throw new NotFoundHttpException('User not found in this Planning.');
            }
        }

        if ([] !== $errors) {
            return new JsonResponse(['error' => 'validation_failed', 'violations' => $errors], 422);
        }

        $current = $this->viewService->currentGenerations($planning);
        $generations = array_map(static fn (array $entry): PlanningGeneration => $entry['generation'], array_values($current));
        $lineByGeneration = [];
        foreach ($current as $entry) {
            $lineByGeneration[(string) $entry['generation']->getStableId()] = $entry['line'];
        }

        $assignments = $this->viewService->assignments($generations, $user, $from, $to);

        $data = [
            'generations' => array_map(
                static fn (array $entry): array => [
                    'stableId' => (string) $entry['generation']->getStableId(),
                    'lineStableId' => (string) $entry['line']->getStableId(),
                    'lineName' => $entry['line']->getName(),
                    'generatedAt' => $entry['generation']->getGeneratedAt()?->format(\DATE_ATOM),
                    'coverageStatus' => $entry['generation']->getCoverageStatus()?->value,
                ],
                array_values($current),
            ),
            'assignments' => array_map(
                fn (DutyAssignment $assignment): array => $this->assignmentToArray($assignment, $lineByGeneration),
                $assignments,
            ),
            'summary' => null,
        ];

        if (null !== $user) {
            // The summary always covers the whole displayed generation, whatever month is on screen.
            $data['summary'] = ['userStableId' => (string) $user->getStableId()]
                + $this->viewService->summarize($this->viewService->assignments($generations, $user, null, null));
        }

        return new JsonResponse($data);
    }

    private function isInPlanning(\App\Entity\Planning $planning, \App\Entity\User $user): bool
    {
        return $planning->getCreator() === $user
            || [] !== $this->teamMemberRepository->findBy(['planning' => $planning, 'user' => $user], limit: 1);
    }

    /**
     * @param array<string, string> $errors
     */
    private function parseQueryDate(Request $request, string $name, array &$errors): ?\DateTimeImmutable
    {
        $value = (string) $request->query->get($name, '');
        if ('' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            $errors[$name] = 'This value is not a valid date (expected YYYY-MM-DD).';

            return null;
        }

        return $date;
    }

    /**
     * @param array<string, PlanningLine> $lineByGeneration
     *
     * @return array<string, mixed>
     */
    private function assignmentToArray(DutyAssignment $assignment, array $lineByGeneration): array
    {
        $duty = $assignment->getDuty();
        $type = $duty->getDutyType();
        $user = $assignment->getTeamMember()->getUser();
        $line = $lineByGeneration[(string) $assignment->getGeneration()->getStableId()] ?? null;

        return [
            'stableId' => (string) $assignment->getStableId(),
            'dutyStableId' => (string) $duty->getStableId(),
            'date' => $duty->getLocalDate()->format('Y-m-d'),
            'startsAt' => $duty->getStartsAt()->format(\DATE_ATOM),
            'endsAt' => $duty->getEndsAt()->format(\DATE_ATOM),
            'timezone' => $duty->getTimezone(),
            'dutyType' => ['stableId' => (string) $type->getStableId(), 'code' => $type->getCode(), 'name' => $type->getName()],
            'lineStableId' => null !== $line ? (string) $line->getStableId() : null,
            'lineName' => $line?->getName(),
            'source' => $assignment->getSource()->value,
            'locked' => $assignment->isLocked(),
            'user' => [
                'stableId' => (string) $user->getStableId(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
            ],
        ];
    }
}
