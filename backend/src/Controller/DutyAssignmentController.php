<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The former technical assignment endpoint (docs/planning-generation.md §17,
 * lot 3), closed by docs/decisions.md D197: it wrote a MANUAL assignment into
 * any generation with no eligibility check, no CalendarWriteLock, no
 * DutyAssignmentEvent — even into a published calendar — under a team role
 * instead of PlanningVoter. No screen used it. Assigning someone to a duty
 * nobody holds is `POST /api/plannings/{planning}/duties/{duty}/reassign`
 * with `expectedCurrentTeamMemberStableId: null`: checked live on every
 * period, locked, audited.
 *
 * The route stays, answering 410 for every caller: an old client gets an
 * explicit answer, never a 404 that could pass for a missing generation, and
 * nothing is read or written.
 */
final class DutyAssignmentController
{
    #[Route('/api/planning-generations/{generationStableId}/assignments', name: 'api_duty_assignment_create', methods: ['POST'])]
    public function create(): JsonResponse
    {
        return new JsonResponse([
            'error' => 'endpoint_removed',
            'message' => 'This endpoint is closed. Use POST /api/plannings/{planning}/duties/{duty}/reassign.',
        ], 410);
    }
}
