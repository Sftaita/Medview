<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Duty;
use App\Entity\Planning;
use App\Entity\PlanningPublication;
use App\Entity\PlanningTeamMember;
use App\Entity\User;
use App\Exception\NoUnpublishedChangesException;
use App\Exception\PlanningAlreadyPublishedException;
use App\Exception\PlanningNotPublishableException;
use App\Exception\PlanningNotYetPublishedException;
use App\Exception\PlanningPublicationInProgressException;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningPublicationRepository;
use App\Repository\PlanningRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\ConditionalPublicationDuty;
use App\Service\FrenchDate;
use App\Service\InconsistentPublicationGroup;
use App\Service\InvalidPublicationAssignment;
use App\Service\LiveDemandPresenter;
use App\Service\PlanningPdfRenderer;
use App\Service\PlanningPublicationPreflightService;
use App\Service\PlanningPublicationService;
use App\Service\PublicationChange;
use App\Service\PublicationConflict;
use App\Service\PublicationLineReadiness;
use App\Service\PublicationLineResult;
use App\Service\PublicationOutcome;
use App\Service\PublicationPreflight;
use App\Service\UncoveredPublicationDuty;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * "Publier le planning" (docs/decisions.md D133): the preflight is
 * read-only (never modifies anything), the publish action always re-runs
 * it for real server-side — a GET loaded moments earlier is never trusted.
 * Both require PlanningVoter::PUBLISH (creator or team OWNER/ADMIN, same
 * population as MANAGE_CALENDAR).
 */
final class PlanningPublicationController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningPublicationPreflightService $preflightService,
        private readonly PlanningPublicationService $publicationService,
        private readonly PlanningPublicationRepository $publicationRepository,
        private readonly PlanningPdfRenderer $pdfRenderer,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly LiveDemandPresenter $demandPresenter,
        private readonly PlanningLineRepository $lineRepository,
    ) {
    }

    #[Route('/api/plannings/{planningStableId}/publication-preflight', name: 'api_planning_publication_preflight', methods: ['GET'])]
    public function preflight(string $planningStableId): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->assertCanPublish($planning);

        return new JsonResponse($this->preflightToArray($this->preflightService->check($planning)));
    }

    #[Route('/api/plannings/{planningStableId}/publish', name: 'api_planning_publish', methods: ['POST'])]
    public function publish(string $planningStableId, #[CurrentUser] User $user): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->assertCanPublish($planning);

        try {
            $outcome = $this->publicationService->publish($planning, $user);
        } catch (PlanningPublicationInProgressException $exception) {
            return new JsonResponse(['error' => 'publication_in_progress', 'message' => $exception->getMessage()], 409);
        } catch (PlanningAlreadyPublishedException $exception) {
            return new JsonResponse(['error' => 'already_published', 'message' => $exception->getMessage()], 409);
        } catch (PlanningNotPublishableException $exception) {
            return new JsonResponse([
                'error' => 'not_publishable',
                'message' => $exception->getMessage(),
                'preflight' => $this->preflightToArray($exception->preflight),
            ], 409);
        }

        return new JsonResponse($this->outcomeToArray($outcome));
    }

    /**
     * "Republier les modifications" (docs/decisions.md D143): only when the
     * current calendar differs from the last diffusion; emails only the
     * people concerned by an impacted date.
     */
    #[Route('/api/plannings/{planningStableId}/republish', name: 'api_planning_republish', methods: ['POST'])]
    public function republish(string $planningStableId, #[CurrentUser] User $user): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->assertCanPublish($planning);

        try {
            $outcome = $this->publicationService->republish($planning, $user);
        } catch (PlanningPublicationInProgressException $exception) {
            return new JsonResponse(['error' => 'publication_in_progress', 'message' => $exception->getMessage()], 409);
        } catch (PlanningNotYetPublishedException $exception) {
            return new JsonResponse(['error' => 'not_yet_published', 'message' => $exception->getMessage()], 409);
        } catch (NoUnpublishedChangesException $exception) {
            return new JsonResponse(['error' => 'no_changes', 'message' => $exception->getMessage()], 409);
        } catch (PlanningNotPublishableException $exception) {
            return new JsonResponse([
                'error' => 'not_publishable',
                'message' => $exception->getMessage(),
                'preflight' => $this->preflightToArray($exception->preflight),
            ], 409);
        }

        return new JsonResponse($this->outcomeToArray($outcome));
    }

    /**
     * Published or not, since when, and — for someone who can publish — what
     * changed since the last diffusion ("Modifications non publiées") and the
     * diffusion history. Any viewer of the planning can read the first part.
     */
    #[Route('/api/plannings/{planningStableId}/publication-state', name: 'api_planning_publication_state', methods: ['GET'])]
    public function state(string $planningStableId): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        if (!$this->authorizationChecker->isGranted(PlanningVoter::VIEW, $planning)) {
            throw new AccessDeniedHttpException('You cannot view this planning.');
        }

        $state = $this->publicationService->state($planning);
        $body = [
            'published' => $state->isPublished(),
            'firstPublishedAt' => $state->first?->getPublishedAt()->format(\DATE_ATOM),
            'lastPublishedAt' => $state->latest?->getPublishedAt()->format(\DATE_ATOM),
            'lastPublishedBy' => null === $state->latest ? null : [
                'firstName' => $state->latest->getPublishedBy()->getFirstName(),
                'lastName' => $state->latest->getPublishedBy()->getLastName(),
            ],
        ];

        if ($this->authorizationChecker->isGranted(PlanningVoter::PUBLISH, $planning)) {
            $body['hasUnpublishedChanges'] = $state->hasUnpublishedChanges();
            $body['changes'] = array_map(static fn (PublicationChange $change): array => [
                'dutyStableId' => (string) $change->duty->getStableId(),
                'date' => $change->duty->getLocalDate()->format('Y-m-d'),
                'lineStableId' => (string) $change->line->getStableId(),
                'lineName' => $change->line->getName(),
                'groupInstanceStableId' => null !== $change->duty->getGroupInstance() ? (string) $change->duty->getGroupInstance()->getStableId() : null,
                'before' => null === $change->before ? null : ['firstName' => $change->before->getUser()->getFirstName(), 'lastName' => $change->before->getUser()->getLastName()],
                'after' => null === $change->after ? null : ['firstName' => $change->after->getUser()->getFirstName(), 'lastName' => $change->after->getUser()->getLastName()],
                // docs/decisions.md D166: false = a reinforcement nobody needed / needs — "Pas de renfort", never "Non attribuée".
                'beforeShown' => $change->beforeShown,
                'afterShown' => $change->afterShown,
            ], $state->changes);
            $body['history'] = array_map(fn (PlanningPublication $publication): array => [
                'stableId' => (string) $publication->getStableId(),
                'kind' => $publication->getKind()->value,
                'publishedAt' => $publication->getPublishedAt()->format(\DATE_ATOM),
                'publishedBy' => ['firstName' => $publication->getPublishedBy()->getFirstName(), 'lastName' => $publication->getPublishedBy()->getLastName()],
                'changedDutyCount' => $publication->getChangedDutyCount(),
                // docs/decisions.md D172: recipientCount / sentCount / failedCount (still retried, or given up).
                ...$this->publicationService->deliveryCounts($publication),
            ], $state->history);
        }

        return new JsonResponse($body);
    }

    /**
     * "Télécharger le PDF": the last diffusion, exactly as it was published
     * — never the live calendar with unpublished edits, never an old solver
     * output. Any viewer of the planning.
     */
    #[Route('/api/plannings/{planningStableId}/publication.pdf', name: 'api_planning_publication_pdf', methods: ['GET'])]
    public function pdf(string $planningStableId): Response
    {
        $planning = $this->resolvePlanning($planningStableId);
        if (!$this->authorizationChecker->isGranted(PlanningVoter::VIEW, $planning)) {
            throw new AccessDeniedHttpException('You cannot view this planning.');
        }

        $publication = $this->publicationRepository->findLatestForPlanning($planning);
        if (null === $publication) {
            return new JsonResponse(['error' => 'not_yet_published', 'message' => 'This planning has never been published.'], 404);
        }

        return new Response($this->pdfRenderer->render($publication), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $this->pdfRenderer->filename($publication)),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function outcomeToArray(PublicationOutcome $outcome): array
    {
        return [
            'lines' => array_map($this->lineResultToArray(...), $outcome->lines),
            'publication' => [
                'stableId' => (string) $outcome->publication->getStableId(),
                'kind' => $outcome->publication->getKind()->value,
                'publishedAt' => $outcome->publication->getPublishedAt()->format(\DATE_ATOM),
                'changedDutyCount' => $outcome->publication->getChangedDutyCount(),
            ],
            'recipientCount' => $outcome->recipientCount,
            'sentCount' => $outcome->sentCount,
        ];
    }

    private function resolvePlanning(string $stableId): Planning
    {
        $planning = $this->planningRepository->findOneByStableId($stableId);
        if (null === $planning) {
            throw new NotFoundHttpException('Planning not found.');
        }

        return $planning;
    }

    private function assertCanPublish(Planning $planning): void
    {
        if (!$this->authorizationChecker->isGranted(PlanningVoter::PUBLISH, $planning)) {
            throw new AccessDeniedHttpException('Only the creator or an OWNER/ADMIN of this Planning can publish it.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function preflightToArray(PublicationPreflight $preflight): array
    {
        return [
            'publishable' => $preflight->publishable,
            'republishable' => $preflight->republishable,
            'lines' => array_map($this->lineReadinessToArray(...), $preflight->lines),
            'uncoveredDuties' => array_map($this->uncoveredDutyToArray(...), $preflight->uncoveredDuties),
            'inconsistentGroups' => array_map($this->inconsistentGroupToArray(...), $preflight->inconsistentGroups),
            'invalidAssignments' => array_map($this->invalidAssignmentToArray(...), $preflight->invalidAssignments),
            'conflicts' => array_map($this->conflictToArray(...), $preflight->conflicts),
            // docs/decisions.md D165: blockers (demand unknown) and warnings (superfluous reinforcement still held).
            'undeterminedDuties' => array_map($this->conditionalDutyToArray(...), $preflight->undeterminedDuties),
            'superfluousCoverages' => array_map($this->conditionalDutyToArray(...), $preflight->superfluousCoverages),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conditionalDutyToArray(ConditionalPublicationDuty $item): array
    {
        $demand = $this->demandPresenter->dutyToArray($item->unitDemand, $item->dutyDemand, $item->state);

        return [
            'code' => $item->code,
            'lineStableId' => (string) $item->line->getStableId(),
            'lineName' => $item->line->getName(),
            'duty' => $this->dutyToArray($item->duty),
            'unitStableKey' => (string) ($item->duty->getGroupInstance()?->getStableId() ?? $item->duty->getStableId()),
            'dates' => array_map(static fn (Duty $d): string => $d->getLocalDate()->format('Y-m-d'), $item->block),
            'member' => null !== $item->holder ? $this->memberToArray($item->holder) : null,
            'source' => [
                'dutyStableId' => $demand['sourceDutyStableId'],
                'date' => $demand['sourceDate'],
                'holder' => $demand['sourceHolder'],
            ],
            'reason' => $demand['dayReason'],
            'explanation' => $this->explanation($item, $demand['sourceHolder']),
        ];
    }

    /**
     * @param array{firstName: string, lastName: string}|null $sourceHolder
     */
    private function explanation(ConditionalPublicationDuty $item, ?array $sourceHolder): string
    {
        $sourceDate = FrenchDate::long($item->dutyDemand->ownDay->sourceDuty->getLocalDate());
        if (ConditionalPublicationDuty::UNDETERMINED === $item->code) {
            return \sprintf('La garde source du %s n’a pas de titulaire : impossible de savoir si ce renfort est requis.', $sourceDate);
        }

        $holder = null !== $item->holder ? $item->holder->getUser()->getFirstName().' '.$item->holder->getUser()->getLastName() : '';
        $source = null !== $sourceHolder ? $sourceHolder['firstName'].' '.$sourceHolder['lastName'] : 'le titulaire actuel';

        return \sprintf('Renfort non requis : %s (garde source du %s) ne déclenche pas de renfort. %s reste affecté ; son retrait n’est pas obligatoire.', $source, $sourceDate, $holder);
    }

    /**
     * @return array<string, mixed>
     */
    private function lineReadinessToArray(PublicationLineReadiness $readiness): array
    {
        return [
            'lineStableId' => (string) $readiness->line->getStableId(),
            'lineName' => $readiness->line->getName(),
            'periodStatus' => $readiness->periodStatus->value,
            'hasGeneration' => $readiness->hasGeneration,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function uncoveredDutyToArray(UncoveredPublicationDuty $item): array
    {
        return $this->dutyToArray($item->duty);
    }

    /**
     * @return array<string, mixed>
     */
    private function inconsistentGroupToArray(InconsistentPublicationGroup $item): array
    {
        return [
            'groupInstanceStableId' => (string) $item->group->getStableId(),
            // Where the block is, and who holds which part of it — so it can be found and fixed in the calendar.
            'duty' => [] !== $item->block ? $this->dutyToArray($item->block[0]) : null,
            ...$this->unitToArray($item->block),
            'members' => array_map($this->memberToArray(...), $item->holders),
        ];
    }

    /**
     * The whole unit an issue is about — a block's every day — so the calendar can locate it (every one of its
     * duties) and the text can give its dates.
     *
     * @param list<Duty> $block
     *
     * @return array{unitStableKey: string|null, dates: list<string>, dutyStableIds: list<string>}
     */
    private function unitToArray(array $block): array
    {
        $first = $block[0] ?? null;

        return [
            'unitStableKey' => null !== $first ? (string) ($first->getGroupInstance()?->getStableId() ?? $first->getStableId()) : null,
            'dates' => array_map(static fn (Duty $d): string => $d->getLocalDate()->format('Y-m-d'), $block),
            'dutyStableIds' => array_map(static fn (Duty $d): string => (string) $d->getStableId(), $block),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invalidAssignmentToArray(InvalidPublicationAssignment $item): array
    {
        return [
            'duty' => $this->dutyToArray($item->duty),
            ...$this->unitToArray([] !== $item->block ? $item->block : [$item->duty]),
            'member' => $this->memberToArray($item->member),
            'reason' => $item->reason,
            'reasonCode' => $item->reasonCode?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conflictToArray(PublicationConflict $item): array
    {
        return [
            'duty' => $this->dutyToArray($item->duty),
            ...$this->unitToArray([] !== $item->block ? $item->block : [$item->duty]),
            'member' => $this->memberToArray($item->member),
            'reason' => $item->reason,
            'reasonCode' => $item->reasonCode?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dutyToArray(Duty $duty): array
    {
        $line = $this->lineRepository->findOneByPlanningPeriod($duty->getPlanningPeriod());

        return [
            'dutyStableId' => (string) $duty->getStableId(),
            'date' => $duty->getLocalDate()->format('Y-m-d'),
            'dutyTypeName' => $duty->getDutyType()->getName(),
            // docs/decisions.md D167: which line, and whether it is a reinforcement — so a missing reinforcement is
            // presented as such, without the frontend guessing.
            'lineStableId' => null !== $line ? (string) $line->getStableId() : null,
            'lineName' => $line?->getName(),
            'conditional' => $duty->isConditional(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function memberToArray(PlanningTeamMember $member): array
    {
        return [
            'teamMemberStableId' => (string) $member->getStableId(),
            'firstName' => $member->getUser()->getFirstName(),
            'lastName' => $member->getUser()->getLastName(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lineResultToArray(PublicationLineResult $result): array
    {
        return [
            'lineStableId' => (string) $result->line->getStableId(),
            'lineName' => $result->line->getName(),
            'periodStatus' => $result->periodStatus->value,
            'alreadyPublished' => $result->alreadyPublished,
        ];
    }
}
