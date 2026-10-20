<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Duty;
use App\Entity\Planning;
use App\Entity\PlanningLine;
use App\Entity\PlanningPublication;
use App\Entity\PlanningPublicationLine;
use App\Entity\PlanningTeamMember;
use App\Entity\User;
use App\Exception\NoUnpublishedChangesException;
use App\Exception\PlanningAlreadyPublishedException;
use App\Exception\PlanningNotPublishableException;
use App\Exception\PlanningNotYetPublishedException;
use App\Exception\PlanningPublicationInProgressException;
use App\Repository\PlanningLineRepository;
use App\Repository\PlanningPublicationLineRepository;
use App\Repository\PlanningPublicationRepository;
use App\Repository\PlanningRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\ConditionalPublicationDuty;
use App\Service\FrenchDate;
use App\Service\InconsistentPublicationGroup;
use App\Service\InvalidPublicationAssignment;
use App\Service\LiveDemandPresenter;
use App\Service\PlanningCyclePresenter;
use App\Service\PlanningCycleResolver;
use App\Service\PlanningPdfRenderer;
use App\Service\PlanningPublicationPreflightService;
use App\Service\PlanningPublicationService;
use App\Service\PublicationChange;
use App\Service\PublicationConflict;
use App\Service\PublicationLineReadiness;
use App\Service\PublicationLineResult;
use App\Service\PublicationNeutralLine;
use App\Service\PublicationOutcome;
use App\Service\PublicationPreflight;
use App\Service\UncoveredPublicationDuty;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
        private readonly PlanningCycleResolver $cycleResolver,
        private readonly PlanningCyclePresenter $cyclePresenter,
        private readonly PlanningPublicationLineRepository $publicationLineRepository,
    ) {
    }

    /**
     * docs/decisions.md D196: ?period= (otherwise the default period, read
     * only), the verdict of each line of the period.
     */
    #[Route('/api/plannings/{planningStableId}/publication-preflight', name: 'api_planning_publication_preflight', methods: ['GET'])]
    public function preflight(string $planningStableId, Request $request): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->assertCanPublish($planning);
        $cycle = $this->cycleResolver->fromRequest($planning, $request);

        return new JsonResponse($this->preflightToArray($this->preflightService->check($planning, $cycle)));
    }

    /**
     * The first publication of lines of a period (docs/decisions.md D196):
     * `period` required on a planning with several; `lines` absent → all or
     * nothing; `lines: [stableId…]` → those lines only.
     */
    #[Route('/api/plannings/{planningStableId}/publish', name: 'api_planning_publish', methods: ['POST'])]
    public function publish(string $planningStableId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->assertCanPublish($planning);
        $cycle = $this->cycleResolver->requiredFromRequest($planning, $request);
        [$lines, $error] = $this->lineIds($request, 'lines', nullable: true);
        if (null !== $error) {
            return $error;
        }

        try {
            $outcome = $this->publicationService->publish($planning, $user, $cycle, $lines);
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
    public function republish(string $planningStableId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        $this->assertCanPublish($planning);
        $cycle = $this->cycleResolver->requiredFromRequest($planning, $request);
        // docs/decisions.md D196: the new lines published with it — chosen explicitly, never deduced.
        [$newLines, $error] = $this->lineIds($request, 'newLines', nullable: false);
        if (null !== $error) {
            return $error;
        }

        try {
            $outcome = $this->publicationService->republish($planning, $user, $cycle, $newLines ?? []);
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
    public function state(string $planningStableId, Request $request): JsonResponse
    {
        $planning = $this->resolvePlanning($planningStableId);
        if (!$this->authorizationChecker->isGranted(PlanningVoter::VIEW, $planning)) {
            throw new AccessDeniedHttpException('You cannot view this planning.');
        }
        $cycle = $this->cycleResolver->fromRequest($planning, $request);

        $state = $this->publicationService->state($planning, $cycle);
        $body = [
            'period' => $this->cyclePresenter->reference($cycle),
            'published' => $state->isPublished(),
            'cycleComplete' => $state->latest?->isCycleComplete(),
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
                // docs/decisions.md D196: frozen with the publication — never recomputed.
                'cycleComplete' => $publication->isCycleComplete(),
                'lines' => array_map($this->publicationLineToArray(...), $this->publicationLineRepository->findByPublication($publication)),
                // docs/decisions.md D172: recipientCount / sentCount / failedCount (still retried, or given up).
                ...$this->publicationService->deliveryCounts($publication),
            ], $state->history);
            // docs/decisions.md D196: generated, never published in this period — published only when chosen.
            $body['linesAwaitingPublication'] = array_map(static fn (PlanningLine $line): array => [
                'lineStableId' => (string) $line->getStableId(),
                'lineName' => $line->getName(),
            ], $state->linesAwaitingPublication);
        }

        return new JsonResponse($body);
    }

    /**
     * "Télécharger le PDF": the last diffusion of the period (?period=,
     * otherwise the default one), exactly as it was published — never the
     * live calendar with unpublished edits, never an old solver output. Any
     * viewer of the planning.
     */
    #[Route('/api/plannings/{planningStableId}/publication.pdf', name: 'api_planning_publication_pdf', methods: ['GET'])]
    public function pdf(string $planningStableId, Request $request): Response
    {
        $planning = $this->resolvePlanning($planningStableId);
        if (!$this->authorizationChecker->isGranted(PlanningVoter::VIEW, $planning)) {
            throw new AccessDeniedHttpException('You cannot view this planning.');
        }

        $publication = $this->publicationRepository->findLatestForCycle($this->cycleResolver->fromRequest($planning, $request));
        if (null === $publication) {
            return new JsonResponse(['error' => 'not_yet_published', 'message' => 'This period has never been published.'], 404);
        }

        return $this->pdfResponse($publication);
    }

    /**
     * docs/decisions.md D196: the PDF of any past publication of the
     * planning, as it was diffused (D173: the stored bytes).
     */
    #[Route('/api/plannings/{planningStableId}/publications/{publicationStableId}/pdf', name: 'api_planning_publication_history_pdf', methods: ['GET'])]
    public function historyPdf(string $planningStableId, string $publicationStableId): Response
    {
        $planning = $this->resolvePlanning($planningStableId);
        if (!$this->authorizationChecker->isGranted(PlanningVoter::VIEW, $planning)) {
            throw new AccessDeniedHttpException('You cannot view this planning.');
        }

        $publication = $this->publicationRepository->findOneByStableId($publicationStableId);
        if (null === $publication || $publication->getPlanning() !== $planning) {
            return new JsonResponse(['error' => 'publication_not_found', 'message' => 'This publication does not belong to this planning.'], 404);
        }

        return $this->pdfResponse($publication);
    }

    private function pdfResponse(PlanningPublication $publication): Response
    {
        // docs/decisions.md D173: the bytes stored with that publication — the very file its emails attached.
        $document = $this->pdfRenderer->pdfOf($publication);

        return new Response($document->content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $document->filename),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * The list of line stable ids under $key in the body (docs/decisions.md
     * D196): absent → null (or [] when not $nullable); anything else than a
     * list of strings → 422 `invalid_lines`.
     *
     * @return array{0: list<string>|null, 1: ?JsonResponse}
     */
    private function lineIds(Request $request, string $key, bool $nullable): array
    {
        $body = '' !== $request->getContent() ? json_decode($request->getContent(), true) : [];
        $value = \is_array($body) ? ($body[$key] ?? null) : null;
        if (null === $value) {
            return [$nullable ? null : [], null];
        }
        if (!\is_array($value) || !array_is_list($value) || [] !== array_filter($value, static fn (mixed $id): bool => !\is_string($id))) {
            return [null, new JsonResponse(['error' => 'invalid_lines', 'message' => \sprintf('"%s" must be a list of line identifiers.', $key)], 422)];
        }

        return [$value, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicationLineToArray(PlanningPublicationLine $line): array
    {
        return [
            'lineStableId' => null !== $line->getLineStableId() ? (string) $line->getLineStableId() : null,
            'lineName' => $line->getLineName(),
            'kind' => $line->getKind()->value,
            'dutyCount' => $line->getDutyCount(),
            'changedDutyCount' => $line->getChangedDutyCount(),
            'reconstructed' => $line->isReconstructed(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function outcomeToArray(PublicationOutcome $outcome): array
    {
        $cycle = $outcome->publication->getCycle();

        return [
            'period' => null !== $cycle ? $this->cyclePresenter->reference($cycle) : null,
            'lines' => array_map($this->lineResultToArray(...), $outcome->lines),
            'publication' => [
                'stableId' => (string) $outcome->publication->getStableId(),
                'kind' => $outcome->publication->getKind()->value,
                'publishedAt' => $outcome->publication->getPublishedAt()->format(\DATE_ATOM),
                'changedDutyCount' => $outcome->publication->getChangedDutyCount(),
                'cycleComplete' => $outcome->publication->isCycleComplete(),
                'lines' => array_map($this->publicationLineToArray(...), $this->publicationLineRepository->findByPublication($outcome->publication)),
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
            'period' => null !== $preflight->cycle ? $this->cyclePresenter->reference($preflight->cycle) : null,
            'publishable' => $preflight->publishable,
            'republishable' => $preflight->republishable,
            'lines' => array_map($this->lineReadinessToArray(...), $preflight->lines),
            // docs/decisions.md D196: publishing this period before an earlier one is allowed — said, never blocking.
            'warnings' => [] !== $preflight->earlierUnpublished
                ? [['code' => PublicationPreflight::EARLIER_PERIOD_UNPUBLISHED, 'periodNumbers' => $preflight->earlierUnpublished]]
                : [],
            'uncoveredDuties' => array_map($this->uncoveredDutyToArray(...), $preflight->uncoveredDuties),
            'inconsistentGroups' => array_map($this->inconsistentGroupToArray(...), $preflight->inconsistentGroups),
            'invalidAssignments' => array_map($this->invalidAssignmentToArray(...), $preflight->invalidAssignments),
            'conflicts' => array_map($this->conflictToArray(...), $preflight->conflicts),
            // docs/decisions.md D165: blockers (demand unknown) and warnings (superfluous reinforcement still held).
            'undeterminedDuties' => array_map($this->conditionalDutyToArray(...), $preflight->undeterminedDuties),
            'superfluousCoverages' => array_map($this->conditionalDutyToArray(...), $preflight->superfluousCoverages),
            // docs/decisions.md D194: active lines with nothing demanded — neutral, never published, never a blocker.
            'neutralLines' => array_map(static fn (PublicationNeutralLine $neutral): array => [
                'lineStableId' => (string) $neutral->line->getStableId(),
                'lineName' => $neutral->line->getName(),
                'periodStatus' => $neutral->periodStatus->value,
                'applicability' => $neutral->applicability->toArray(),
            ], $preflight->neutralLines),
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
            // docs/decisions.md D196: the line's own verdict.
            'published' => $readiness->isPublished(),
            'firstPublishable' => $readiness->isFirstPublishable(),
            'blockers' => $readiness->blockers,
            'sourceLineStableId' => null !== $readiness->sourceLine ? (string) $readiness->sourceLine->getStableId() : null,
            'applicability' => $readiness->applicability?->toArray(),
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
            // docs/decisions.md D196: FIRST_FOR_LINE, UPDATED, or null (unchanged, or left as it was).
            'kind' => $result->kind?->value,
        ];
    }
}
