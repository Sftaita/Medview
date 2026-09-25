<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DutyAssignment;
use App\Entity\Planning;
use App\Repository\PlanningRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\PlanningResultCandidateReason;
use App\Service\PlanningResultDuty;
use App\Service\PlanningResultLine;
use App\Service\PlanningResultService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * "Consultation du planning généré" (docs/decisions.md D130): a dedicated
 * read endpoint, deliberately separate from
 * `GET /api/plannings/{id}/assignments` (D125) — that one is a
 * month-windowed, optionally per-person browsing view of *covered*
 * duties only, used for "Planning par personne"; this one is the whole
 * period's coverage picture, uncovered REQUIRED duties included, which
 * that endpoint's contract was never meant to carry (an uncovered duty
 * has no assignee, so a `userStableId` filter cannot apply to it the same
 * way). Same authorization as `/assignments`: anyone who can VIEW the
 * Planning.
 */
final class PlanningResultController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningResultService $resultService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    /**
     * `from`/`to` (YYYY-MM-DD, `to` exclusive) restrict which duties are
     * *listed*, never the coverage counts, which always describe the whole
     * period — same rule as `/assignments`' per-person summary.
     */
    #[Route('/api/plannings/{planningStableId}/result', name: 'api_planning_result', methods: ['GET'])]
    public function get(string $planningStableId, Request $request): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        if (!$this->authorizationChecker->isGranted(PlanningVoter::VIEW, $planning)) {
            throw new AccessDeniedHttpException('You do not have access to this Planning.');
        }

        $errors = [];
        $from = $this->parseQueryDate($request, 'from', $errors);
        $to = $this->parseQueryDate($request, 'to', $errors);
        if ([] === $errors && null !== $from && null !== $to && $to <= $from) {
            $errors['to'] = 'to must be strictly after from.';
        }
        if ([] !== $errors) {
            return new JsonResponse(['error' => 'validation_failed', 'violations' => $errors], 422);
        }

        $result = $this->resultService->forPlanning($planning, $from, $to);

        return new JsonResponse([
            'planningStableId' => (string) $planning->getStableId(),
            'lines' => array_map($this->lineToArray(...), $result->lines),
        ]);
    }

    private function resolvePlanning(string $stableId): Planning
    {
        $planning = $this->planningRepository->findOneByStableId($stableId);
        if (null === $planning) {
            throw new NotFoundHttpException('Planning not found.');
        }

        return $planning;
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
     * @return array<string, mixed>
     */
    private function lineToArray(PlanningResultLine $result): array
    {
        $line = $result->line;
        $generation = $result->generation;

        return [
            'lineStableId' => (string) $line->getStableId(),
            'lineName' => $line->getName(),
            'lineType' => $line->getType()->value,
            'generationStableId' => null !== $generation ? (string) $generation->getStableId() : null,
            'generatedAt' => $generation?->getGeneratedAt()?->format(\DATE_ATOM),
            'coverageStatus' => $result->coverageStatus?->value,
            'requiredDutyCount' => $result->requiredDutyCount,
            'coveredRequiredDutyCount' => $result->coveredRequiredDutyCount,
            'uncoveredRequiredDutyCount' => $result->uncoveredRequiredDutyCount,
            'duties' => array_map($this->dutyToArray(...), $result->duties),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dutyToArray(PlanningResultDuty $result): array
    {
        $duty = $result->duty;
        $type = $duty->getDutyType();
        $assignment = $result->assignment;

        return [
            'dutyStableId' => (string) $duty->getStableId(),
            'date' => $duty->getLocalDate()->format('Y-m-d'),
            'startsAt' => $duty->getStartsAt()->format(\DATE_ATOM),
            'endsAt' => $duty->getEndsAt()->format(\DATE_ATOM),
            'timezone' => $duty->getTimezone(),
            'dutyType' => ['stableId' => (string) $type->getStableId(), 'code' => $type->getCode(), 'name' => $type->getName()],
            'required' => $duty->isRequired(),
            'grouped' => null !== $duty->getGroupInstance(),
            'covered' => $result->covered,
            'assignment' => null === $assignment ? null : $this->assignmentToArray($assignment),
            'reasons' => array_map($this->reasonToArray(...), $result->reasons),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assignmentToArray(DutyAssignment $assignment): array
    {
        $user = $assignment->getTeamMember()->getUser();

        return [
            'stableId' => (string) $assignment->getStableId(),
            'source' => $assignment->getSource()->value,
            'locked' => $assignment->isLocked(),
            'user' => [
                'stableId' => (string) $user->getStableId(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reasonToArray(PlanningResultCandidateReason $reason): array
    {
        return [
            'candidateFirstName' => $reason->candidateFirstName,
            'candidateLastName' => $reason->candidateLastName,
            'reasons' => $reason->reasons,
        ];
    }
}
