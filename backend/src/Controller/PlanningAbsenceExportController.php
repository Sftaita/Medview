<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PlanningRepository;
use App\Security\Voter\PlanningVoter;
use App\Service\PlanningAbsenceExportService;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * "Exporter les absences (PDF)" (docs/availability.md §11, docs/decisions.md
 * D181). Everyone's unavailabilities over the planning: the same audience
 * as the availability follow-up that already shows them one person at a
 * time — PlanningVoter::MANAGE_AVAILABILITY, i.e. the creator or a current
 * OWNER/ADMIN of one of the planning's teams (D124), never a plain member.
 * The participants are always derived from the planning on the server; the
 * request carries nothing but the planning's identifier.
 */
final class PlanningAbsenceExportController
{
    public function __construct(
        private readonly PlanningRepository $planningRepository,
        private readonly PlanningAbsenceExportService $exportService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    #[Route('/api/plannings/{planningStableId}/availability-export.pdf', name: 'api_planning_availability_export', methods: ['GET'])]
    public function export(string $planningStableId): Response
    {
        $planning = $this->planningRepository->findOneByStableId($planningStableId);
        if (null === $planning) {
            throw new NotFoundHttpException('Planning not found.');
        }
        if (!$this->authorizationChecker->isGranted(PlanningVoter::MANAGE_AVAILABILITY, $planning)) {
            throw new AccessDeniedHttpException('You cannot follow the availabilities of this planning.');
        }

        $file = $this->exportService->export($planning);

        return new Response($file->content, 200, [
            'Content-Type' => $file->contentType,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file->filename),
            'Cache-Control' => 'private, no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
