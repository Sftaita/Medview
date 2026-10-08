<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\PlatformAuditEventType;
use App\Entity\PlatformAuditOutcome;
use App\Repository\PlatformAuditEventRepository;
use App\Repository\UserRepository;
use App\Service\Admin\AuditEventPresenter;
use App\Service\Admin\TechnicalErrorLog;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Activité" (docs/admin.md §6): the append-only audit log, and — kept
 * apart, never mixed into it — the technical error log.
 */
#[IsGranted('ROLE_PLATFORM_ADMIN')]
final class AdminActivityController
{
    public function __construct(
        private readonly PlatformAuditEventRepository $events,
        private readonly UserRepository $users,
        private readonly TechnicalErrorLog $errors,
        private readonly AdminJson $json,
    ) {
    }

    #[Route('/api/admin/audit-events', name: 'api_admin_audit_events', methods: ['GET'])]
    public function auditEvents(Request $request): JsonResponse
    {
        $pagination = $this->json->pagination($request, 30);
        if ($pagination instanceof JsonResponse) {
            return $pagination;
        }
        [$page, $perPage] = $pagination;

        $type = PlatformAuditEventType::tryFrom((string) $request->query->get('type', ''));
        $outcome = PlatformAuditOutcome::tryFrom((string) $request->query->get('outcome', ''));
        if ((null === $type && '' !== (string) $request->query->get('type', ''))
            || (null === $outcome && '' !== (string) $request->query->get('outcome', ''))) {
            return AdminJson::invalidQuery('Unknown type or outcome.');
        }

        $target = null;
        $userId = (string) $request->query->get('user', '');
        if ('' !== $userId) {
            $target = $this->users->findOneByStableId($userId);
            if (null === $target) {
                return AdminJson::page([], 0, $page, $perPage);
            }
        }

        return AdminJson::page(
            array_map(AuditEventPresenter::present(...), $this->events->findPage($type, $outcome, $target, ($page - 1) * $perPage, $perPage)),
            $this->events->countFiltered($type, $outcome, $target),
            $page,
            $perPage,
        );
    }

    #[Route('/api/admin/technical-errors', name: 'api_admin_technical_errors', methods: ['GET'])]
    public function technicalErrors(Request $request): JsonResponse
    {
        $pagination = $this->json->pagination($request, 30);
        if ($pagination instanceof JsonResponse) {
            return $pagination;
        }
        [$page, $perPage] = $pagination;

        return AdminJson::page($this->errors->latest($perPage, ($page - 1) * $perPage), $this->errors->count(), $page, $perPage);
    }
}
