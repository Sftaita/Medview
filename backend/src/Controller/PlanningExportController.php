<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\InvalidPlanningExportRequestException;
use App\Exception\PlanningNotYetPublishedException;
use App\Repository\PlanningRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\PlanningExportService;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * "Exporter" (docs/planning-export.md, docs/decisions.md D150): the current
 * calendar of a published planning as a PDF or an .xlsx. Same audience as
 * the calendar screen and "Télécharger le PDF": anyone who can VIEW the
 * planning — the export shows nothing that person cannot already read.
 * A POST because the export carries a body (lines, order, names, dates);
 * it never modifies anything.
 */
final class PlanningExportController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningExportService $exportService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/api/plannings/{planningStableId}/export', name: 'api_planning_export', methods: ['POST'])]
    public function export(string $planningStableId, Request $request): Response
    {
        $planning = $this->planningRepository->findOneByStableId($planningStableId);
        if (null === $planning) {
            throw new NotFoundHttpException('Planning not found.');
        }
        if (!$this->authorizationChecker->isGranted(PlanningVoter::VIEW, $planning)) {
            throw new AccessDeniedHttpException('You cannot view this planning.');
        }

        try {
            $raw = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body is not valid JSON.'], 400);
        }
        if (!\is_array($raw) || ([] !== $raw && array_is_list($raw))) {
            return new JsonResponse(['error' => 'invalid_json', 'message' => 'The request body must be a JSON object.'], 400);
        }

        try {
            $file = $this->exportService->export($planning, $raw);
        } catch (PlanningNotYetPublishedException) {
            return new JsonResponse(['error' => 'not_yet_published', 'message' => 'Only a published planning can be exported.'], 409);
        } catch (InvalidPlanningExportRequestException $exception) {
            return new JsonResponse(['error' => 'validation_failed', 'violations' => $exception->violations], 422);
        }

        return new Response($file->content, 200, [
            'Content-Type' => $file->contentType,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file->filename),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
