<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\MyDutiesService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * "Mes gardes" (docs/decisions.md D168). Implicitly scoped to
 * #[CurrentUser] and never accepts another User as a parameter, so it needs
 * no Voter — like /api/me/calendar (D057).
 */
final class MyDutiesController
{
    public function __construct(
        private readonly MyDutiesService $service,
    ) {
    }

    #[Route('/api/me/duties', name: 'api_me_duties', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(['duties' => $this->service->dutiesWithSwapStateOf($user)]);
    }
}
