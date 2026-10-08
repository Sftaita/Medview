<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\AdminAccountActionRequest;
use App\Entity\User;
use App\Exception\AdminActionRefusedException;
use App\Repository\UserRepository;
use App\Service\Admin\AdminUserDirectory;
use App\Service\Admin\AdminUserService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Utilisateurs" (docs/admin.md §3-§4): the account directory and the three
 * account actions. Users are addressed by stableId, never the numeric id.
 */
#[IsGranted('ROLE_PLATFORM_ADMIN')]
final class AdminUserController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AdminUserDirectory $directory,
        private readonly AdminUserService $service,
        private readonly AdminJson $json,
    ) {
    }

    #[Route('/api/admin/users', name: 'api_admin_users', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $pagination = $this->json->pagination($request);
        if ($pagination instanceof JsonResponse) {
            return $pagination;
        }
        [$page, $perPage] = $pagination;

        $search = (string) $request->query->get('search', '');
        if (mb_strlen($search) > 100) {
            return AdminJson::invalidQuery('search is limited to 100 characters.');
        }

        try {
            $result = $this->directory->search(
                $search,
                (string) $request->query->get('status', 'all'),
                (string) $request->query->get('sort', 'createdAt'),
                (string) $request->query->get('direction', 'desc'),
                $page,
                $perPage,
            );
        } catch (\InvalidArgumentException) {
            return AdminJson::invalidQuery('status must be all|active|disabled, sort one of '.implode('|', AdminUserDirectory::SORTS).', direction asc|desc.');
        }

        return AdminJson::page($result['items'], $result['total'], $page, $perPage);
    }

    #[Route('/api/admin/users/{stableId}', name: 'api_admin_user', methods: ['GET'])]
    public function show(string $stableId): JsonResponse
    {
        return new JsonResponse($this->directory->detail($this->find($stableId)));
    }

    #[Route('/api/admin/users/{stableId}/deactivate', name: 'api_admin_user_deactivate', methods: ['POST'])]
    public function deactivate(string $stableId, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        return $this->act($stableId, $request, function (User $target, ?string $reason) use ($actor, $request): void {
            $this->service->deactivate($actor, $target, $reason, $request->getClientIp());
        });
    }

    #[Route('/api/admin/users/{stableId}/reactivate', name: 'api_admin_user_reactivate', methods: ['POST'])]
    public function reactivate(string $stableId, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        return $this->act($stableId, $request, function (User $target, ?string $reason) use ($actor, $request): void {
            $this->service->reactivate($actor, $target, $reason, $request->getClientIp());
        });
    }

    #[Route('/api/admin/users/{stableId}/revoke-sessions', name: 'api_admin_user_revoke_sessions', methods: ['POST'])]
    public function revokeSessions(string $stableId, Request $request, #[CurrentUser] User $actor): JsonResponse
    {
        return $this->act($stableId, $request, function (User $target, ?string $reason) use ($actor, $request): void {
            $this->service->revokeSessions($actor, $target, $reason, $request->getClientIp());
        });
    }

    /**
     * @param callable(User, ?string): void $action
     */
    private function act(string $stableId, Request $request, callable $action): JsonResponse
    {
        $target = $this->find($stableId);
        $dto = $this->json->body($request, AdminAccountActionRequest::class);
        if ($dto instanceof JsonResponse) {
            return $dto;
        }

        $reason = null !== $dto->reason ? trim($dto->reason) : null;
        try {
            $action($target, '' === $reason ? null : $reason);
        } catch (AdminActionRefusedException $exception) {
            return AdminJson::refused($exception);
        }

        return new JsonResponse($this->directory->detail($target));
    }

    private function find(string $stableId): User
    {
        return $this->users->findOneByStableId($stableId) ?? throw new NotFoundHttpException('User not found.');
    }
}
