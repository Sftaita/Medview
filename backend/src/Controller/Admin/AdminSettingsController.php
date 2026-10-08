<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\GrantPlatformAdminRequest;
use App\Dto\PasswordConfirmationRequest;
use App\Entity\User;
use App\Exception\AdminActionRefusedException;
use App\Repository\UserRepository;
use App\Service\Admin\AdminSettings;
use App\Service\Admin\PlatformAdminService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Paramètres" (docs/admin.md §8): read-only settings, and the management
 * of platform administrators — the only write, password re-confirmed and
 * audited (D174). Configuration itself is never writable from here.
 */
#[IsGranted('ROLE_PLATFORM_ADMIN')]
final class AdminSettingsController
{
    public function __construct(
        private readonly AdminSettings $settings,
        private readonly PlatformAdminService $platformAdmins,
        private readonly UserRepository $users,
        private readonly AdminJson $json,
    ) {
    }

    #[Route('/api/admin/settings', name: 'api_admin_settings', methods: ['GET'])]
    public function view(): JsonResponse
    {
        return new JsonResponse($this->settings->view());
    }

    #[Route('/api/admin/platform-admins', name: 'api_admin_platform_admin_grant', methods: ['POST'])]
    public function grant(Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $dto = $this->json->body($request, GrantPlatformAdminRequest::class);
        if ($dto instanceof JsonResponse) {
            return $dto;
        }

        try {
            $this->platformAdmins->grantByAdmin($actor, $dto->email, $dto->password, $request->getClientIp());
        } catch (AdminActionRefusedException $exception) {
            return AdminJson::refused($exception);
        }

        return new JsonResponse($this->settings->view(), 201);
    }

    #[Route('/api/admin/platform-admins/{stableId}/revoke', name: 'api_admin_platform_admin_revoke', methods: ['POST'])]
    public function revoke(string $stableId, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        $target = $this->users->findOneByStableId($stableId) ?? throw new NotFoundHttpException('User not found.');
        $dto = $this->json->body($request, PasswordConfirmationRequest::class);
        if ($dto instanceof JsonResponse) {
            return $dto;
        }

        try {
            $this->platformAdmins->revokeByAdmin($actor, $target, $dto->password, $request->getClientIp());
        } catch (AdminActionRefusedException $exception) {
            return AdminJson::refused($exception);
        }

        return new JsonResponse($this->settings->view());
    }
}
